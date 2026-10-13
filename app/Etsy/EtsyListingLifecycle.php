<?php

namespace App\Etsy;

use App\Alerts\SystemAlerts;
use App\Models\EtsyConnection;
use App\Models\EtsyListing;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A vida de um anuncio depois de enviado: por a venda, tirar de venda,
 * apagar — e acompanhar sozinho o stock e o produto.
 *
 * Duas metades com regras diferentes:
 *
 *   pedido   publish(), deactivate(), delete(): alguem carregou num botao ou
 *            confirmou no MCP. Lancam EtsyException com o que correu mal.
 *   sozinho  sync(), refreshRemote(): correm na fila e no agendador. Nunca
 *            lancam; o que falhar fica escrito no anuncio e avisa o #sistema.
 *
 * O que a metade "sozinho" NUNCA faz: publicar um rascunho, voltar a ligar
 * um anuncio que o dono desligou, mexer num esgotado ou expirado (repor e
 * renovar, e renovar paga-se), nem tocar num anuncio feito a mao na Etsy
 * para o qual ainda nao houve um envio pedido.
 */
class EtsyListingLifecycle
{
    public const SKIPPED = 'skipped';

    public const UNCHANGED = 'unchanged';

    public const PUSHED = 'pushed';

    public const DEACTIVATED = 'deactivated';

    public const REACTIVATED = 'reactivated';

    public const FAILED = 'failed';

    /** Os estados em que a sincronizacao acompanha o anuncio. */
    private const FOLLOWED = ['draft', 'active', 'inactive'];

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
        private EtsyListingSync $sync,
        private SystemAlerts $alerts,
    ) {}

    // ── A pedido ───────────────────────────────────────────────────────────

    /**
     * Poe o anuncio a venda. E a unica coisa aqui que custa dinheiro (a taxa
     * de publicacao), e por isso so sai o que foi visto: com alteracoes por
     * enviar, recusa.
     */
    public function publish(Product $product): EtsyListing
    {
        $blocker = $this->publishBlocker($product);

        if ($blocker !== null) {
            throw new EtsyException($blocker);
        }

        $listing = $this->onEtsy($product);

        if ($listing->state === 'active') {
            return $listing;
        }

        $previous = $listing->state;
        $this->setRemoteState($listing, 'active');
        $listing->update(['auto_deactivated_at' => null, 'last_error' => null]);

        // Um esgotado volta com a quantidade a 1 (e a Etsy que o faz), e num
        // expirado nao se mexeu enquanto esteve parado: o inventario e o
        // conteudo certos vao a seguir.
        if (in_array($previous, ['sold_out', 'expired'], true)) {
            $this->sync->push($product);
        }

        return $listing->refresh();
    }

    /**
     * O que impede a publicacao, por palavras — ou null se pode ir (ou se ja
     * esta a venda). Nao fala com a Etsy: serve para dizer o que falta ANTES
     * de pedir confirmacao a alguem.
     */
    public function publishBlocker(Product $product): ?string
    {
        $listing = EtsyListing::query()->where('product_id', $product->getKey())->first();

        if ($listing === null || $listing->etsy_listing_id === null) {
            return "\"{$product->name}\" ainda não tem anúncio na Etsy. Envia-o primeiro.";
        }

        if ($product->trashed() || $product->status === 'archived') {
            return 'Este produto está arquivado ou apagado: não se publica um anúncio de um produto fora do catálogo.';
        }

        if ($listing->state === 'active') {
            return null;
        }

        $payload = $this->sync->payload($product);

        return match (true) {
            ! $payload->isReady() => 'Ainda não dá para publicar este anúncio. '.implode(' ', $payload->blockers),
            ! in_array($listing->state, ['sold_out', 'expired'], true) && $listing->last_push_hash !== $payload->hash() => 'Há alterações por enviar: o que está na Etsy não é o que o 12studio tem agora. Envia o anúncio primeiro e publica a seguir.',
            $this->sync->pendingUploads($listing)['images'] > 0 => 'As fotografias ainda não estão todas na Etsy'
                .($listing->last_error === null ? ' (podem estar a subir).' : ": {$listing->last_error}")
                .' Espera um minuto ou volta a enviar o anúncio.',
            ! $listing->images()->whereNotNull('etsy_image_id')->exists() => 'A Etsy não publica um anúncio sem pelo menos uma fotografia. Põe uma na galeria do produto e envia o anúncio.',
            default => null,
        };
    }

    /**
     * Tira o anuncio de venda. Desligado a mao fica desligado: a
     * sincronizacao nao o volta a ligar.
     */
    public function deactivate(Product $product): EtsyListing
    {
        $listing = $this->onEtsy($product);

        if ($listing->state !== 'active') {
            throw new EtsyException("O anúncio {$listing->etsy_listing_id} não está à venda (estado: {$listing->state}). Não há o que desativar.");
        }

        $this->setRemoteState($listing, 'inactive');
        $listing->update(['auto_deactivated_at' => null]);

        return $listing->refresh();
    }

    /**
     * Apaga o anuncio da Etsy. La nao se recupera; ca fica o conteudo
     * trabalhado, para um anuncio novo partir dele.
     *
     * Serve tambem o anuncio de um produto apagado. Se o anuncio ja la nao
     * estiver, esta feito. Se a Etsy nao deixar apagar naquele estado,
     * desativa-o — o que interessa e que deixe de estar a venda — e diz.
     */
    public function delete(Product $product): void
    {
        $listing = EtsyListing::query()->where('product_id', $product->getKey())->first();

        if ($listing === null || $listing->etsy_listing_id === null) {
            return;
        }

        // Antes de falar com a Etsy: sem loja ligada nao ha como apagar.
        $shopId = $this->connection()->shop_id;

        try {
            $this->api->deleteListing($listing->etsy_listing_id);
        } catch (EtsyApiException $exception) {
            if ($exception->status === 409) {
                throw $this->deactivateInstead($shopId, $listing, $exception);
            }

            if ($exception->status !== 404) {
                throw $exception;
            }
        }

        $this->forgetRemote($listing);
    }

    // ── Sozinho ────────────────────────────────────────────────────────────

    /**
     * Poe o anuncio de acordo com o produto, se alguma coisa mudou.
     *
     * @return self::SKIPPED|self::UNCHANGED|self::PUSHED|self::DEACTIVATED|self::REACTIVATED|self::FAILED
     */
    public function sync(EtsyListing $listing): string
    {
        $product = $listing->product;

        if (! $this->follows($listing) || $product->trashed()) {
            return self::SKIPPED;
        }

        try {
            $payload = $this->sync->payload($product);
            $sellable = $product->status !== 'archived' && $payload->totalQuantity() > 0;
            $changed = $listing->last_push_hash !== $payload->hash();
            // follows() ja garantiu que, se esta inativo, fomos nos.
            $returning = $listing->state === 'inactive';

            // Nada a fazer: nem se gasta um pedido a Etsy.
            if ($sellable ? (! $changed && ! $returning) : $listing->state !== 'active') {
                return self::UNCHANGED;
            }

            // Antes de mexer: o estado que ca esta pode ter horas. O dono
            // pode ter desligado o anuncio na Etsy, ou ele ter esgotado ou
            // expirado — e ai nao e connosco (repor um expirado era pagar).
            if (! $this->stateStillHolds($listing)) {
                return self::SKIPPED;
            }

            // Sem nada para vender (ou com o produto fora do catalogo) nao
            // pode estar a venda. So se muda o estado: um inventario a zero
            // a Etsy nao aceitava.
            if (! $sellable) {
                $this->setRemoteState($listing, 'inactive');
                $listing->update(['auto_deactivated_at' => now()]);

                return self::DEACTIVATED;
            }

            if (! $payload->isReady()) {
                throw new EtsyException('A sincronização parou: '.implode(' ', $payload->blockers));
            }

            $outcome = self::UNCHANGED;

            if ($changed) {
                $listing = $this->sync->push($product);
                $outcome = self::PUSHED;
            }

            // Foi a sincronizacao que o tirou de venda; e ela que o repoe.
            // Depois do inventario, para nao voltar com quantidades velhas.
            if ($returning) {
                $this->setRemoteState($listing, 'active');
                $listing->update(['auto_deactivated_at' => null]);

                return self::REACTIVATED;
            }

            return $outcome;
        } catch (EtsyBusyException) {
            // Alguem esta a enviar este anuncio agora mesmo: fica para a
            // volta seguinte, sem alarme.
            return self::SKIPPED;
        } catch (EtsyException $exception) {
            $this->problem($listing, 'A sincronização com a Etsy falhou', $exception->getMessage());

            return self::FAILED;
        }
    }

    /**
     * Confere com a Etsy o estado de todos os anuncios ligados: o dono pode
     * ter mexido la a mao, e um anuncio esgota ou expira sem nos avisar.
     *
     * Lanca se a Etsy nao responder — quem chama (o comando) decide.
     *
     * @return array{checked: int, changed: int, gone: int}
     */
    public function refreshRemote(): array
    {
        $result = ['checked' => 0, 'changed' => 0, 'gone' => 0];

        EtsyListing::query()->whereNotNull('etsy_listing_id')->orderBy('id')->chunkById(100, function ($listings) use (&$result): void {
            /** @var array<int, int> $ids */
            $ids = $listings->pluck('etsy_listing_id')->map(fn (mixed $id): int => (int) $id)->all();

            $remote = [];

            foreach (EtsyPresenter::rows($this->api->listingsByIds($ids)['results'] ?? null) as $row) {
                $remote[(int) ($row['listing_id'] ?? 0)] = $row;
            }

            foreach ($listings as $listing) {
                $result['checked']++;
                $row = $remote[$listing->etsy_listing_id] ?? $this->lookUp($listing);

                if ($row === null) {
                    $this->forgetRemote($listing);
                    $result['gone']++;

                    continue;
                }

                $state = is_string($row['state'] ?? null) ? Str::limit($row['state'], 20, '') : $listing->state;
                $url = EtsyPresenter::link($row['url'] ?? null) ?? $listing->url;

                if ($state !== $listing->state) {
                    $result['changed']++;
                }

                $listing->update([
                    'state' => $state,
                    'url' => $url,
                    'remote_checked_at' => now(),
                    // Posto a venda a mao, na Etsy: a decisao passou a ser do dono.
                    'auto_deactivated_at' => $state === 'inactive' ? $listing->auto_deactivated_at : null,
                ]);
            }
        });

        return $result;
    }

    /**
     * Os anuncios que ficaram na Etsy depois de o produto ser apagado (a
     * Etsy estava em baixo, a tarefa falhou): tenta outra vez.
     */
    public function deleteOrphans(): int
    {
        $deleted = 0;

        $orphans = EtsyListing::query()
            ->whereNotNull('etsy_listing_id')
            ->whereHas('product', fn (Builder $query) => $query->whereNotNull('products.deleted_at'))
            ->with('product')
            ->get();

        foreach ($orphans as $listing) {
            if ($this->deleteQuietly($listing)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Apaga sem lancar, para a fila e para o agendador: o que falhar fica no
     * anuncio e no #sistema.
     */
    public function deleteQuietly(EtsyListing $listing): bool
    {
        try {
            $this->delete($listing->product);

            return true;
        } catch (EtsyException $exception) {
            $this->problem($listing, 'Não foi possível apagar o anúncio na Etsy', $exception->getMessage());

            return false;
        }
    }

    // ── Por dentro ─────────────────────────────────────────────────────────

    /**
     * Pergunta a Etsy em que estado o anuncio esta MESMO. Se bate com o que
     * ca esta, segue-se. Se nao bate, fica ca o estado verdadeiro e esta
     * volta nao mexe em nada: a decisao deixou de ser nossa (e por isso a
     * marca de "fomos nos que o desligamos" tambem sai).
     */
    private function stateStillHolds(EtsyListing $listing): bool
    {
        $remote = $this->lookUp($listing);

        if ($remote === null) {
            $this->forgetRemote($listing);

            return false;
        }

        $state = is_string($remote['state'] ?? null) ? Str::limit($remote['state'], 20, '') : $listing->state;

        if ($state === $listing->state) {
            $listing->update(['remote_checked_at' => now()]);

            return true;
        }

        $listing->update(['state' => $state, 'auto_deactivated_at' => null, 'remote_checked_at' => now()]);

        return false;
    }

    private function follows(EtsyListing $listing): bool
    {
        return $listing->sync_enabled
            && $listing->etsy_listing_id !== null
            // Ligado e nunca enviado: e um anuncio feito a mao, e so um
            // envio pedido lhe passa por cima.
            && $listing->last_pushed_at !== null
            && in_array($listing->state, self::FOLLOWED, true)
            // Inativo por decisao do dono: fica como esta.
            && ($listing->state !== 'inactive' || $listing->auto_deactivated_at !== null);
    }

    private function onEtsy(Product $product): EtsyListing
    {
        $listing = EtsyListing::query()->where('product_id', $product->getKey())->first();

        if ($listing === null || $listing->etsy_listing_id === null) {
            throw new EtsyException("\"{$product->name}\" ainda não tem anúncio na Etsy. Envia-o primeiro.");
        }

        return $listing;
    }

    private function setRemoteState(EtsyListing $listing, string $state): void
    {
        $response = $this->api->updateListing($this->connection()->shop_id, (int) $listing->etsy_listing_id, ['state' => $state]);

        $listing->update([
            'state' => is_string($response['state'] ?? null) ? Str::limit($response['state'], 20, '') : $state,
            'remote_checked_at' => now(),
        ]);
    }

    private function deactivateInstead(int $shopId, EtsyListing $listing, EtsyApiException $refusal): EtsyException
    {
        $deactivated = $listing->state !== 'active';

        if (! $deactivated) {
            try {
                $this->api->updateListing($shopId, (int) $listing->etsy_listing_id, ['state' => 'inactive']);
                $listing->update(['state' => 'inactive', 'auto_deactivated_at' => null]);
                $deactivated = true;
            } catch (EtsyException) {
                // Fica dito na mensagem que nem isso se conseguiu.
            }
        }

        return new EtsyException(sprintf(
            'A Etsy não deixou apagar o anúncio %d neste estado (%s). %s',
            $listing->etsy_listing_id,
            $refusal->etsyError ?? "HTTP {$refusal->status}",
            $deactivated
                ? 'Ficou desativado: já não está à venda. Apaga-o na Etsy, ou tenta outra vez mais tarde.'
                : 'Também não foi possível desativá-lo: continua à venda. Trata dele na Etsy.',
        ));
    }

    /**
     * Um anuncio que nao veio no lote: pergunta-se por ele antes de o dar
     * como perdido. Null = a Etsy disse que ja nao existe.
     *
     * @return array<array-key, mixed>|null
     */
    private function lookUp(EtsyListing $listing): ?array
    {
        try {
            return $this->api->listing((int) $listing->etsy_listing_id);
        } catch (EtsyApiException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * O anuncio ja nao existe la. Fica a linha, com o conteudo trabalhado ca;
     * sai tudo o que era do anuncio antigo.
     */
    private function forgetRemote(EtsyListing $listing): void
    {
        DB::transaction(function () use ($listing): void {
            $listing->variants()->update(['etsy_product_id' => null, 'etsy_offering_id' => null, 'last_quantity_pushed' => null]);
            // As que so existiam la (postas a mao) ja nao tem o que dizer.
            $listing->images()->whereNull('product_image_id')->delete();
            $listing->images()->update(['etsy_image_id' => null]);

            $listing->update([
                'etsy_listing_id' => null,
                'state' => EtsyListing::STATE_DELETED,
                'url' => null,
                'etsy_video_id' => null,
                'property_map' => null,
                'auto_deactivated_at' => null,
                'last_pushed_at' => null,
                'last_push_hash' => null,
                'last_error' => null,
                'remote_checked_at' => now(),
            ]);
        });
    }

    private function problem(EtsyListing $listing, string $what, string $reason): void
    {
        $listing->update(['last_error' => Str::limit($reason, 500, '')]);

        $this->alerts->etsyListingProblem($listing->id, $listing->product->name, $what, $reason);
    }

    private function connection(): EtsyConnection
    {
        return $this->tokens->connection() ?? throw EtsyException::notConnected();
    }
}
