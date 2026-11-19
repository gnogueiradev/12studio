<?php

namespace App\Customers;

use App\Mail\RepeatOfferMail;
use App\Mail\RepeatOfferReminderMail;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\RepeatOffer;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * As ofertas para voltar a comprar: uns dias depois do envio, um codigo
 * pessoal de desconto, e um lembrete antes de ele expirar.
 *
 * A base legal e a do cliente (Lei 41/2004, art. 13.º-A): so se manda a quem
 * comprou no checkout da loja depois de a caixa "nao quero ofertas" existir e
 * a deixou por marcar. Uma encomenda sem esse checkout — feita no backoffice,
 * paga por link, de uma conversa, ou da loja de antes — nunca da oferta. Quem
 * recusou (no checkout, na conta ou pelo link do email) nunca recebe, e cada
 * email leva a recusa de um clique.
 */
class RepeatOffers
{
    /** Os dias, depois da data de envio, em que a oferta ainda sai. */
    private const WINDOW_DAYS = 30;

    public function __construct(
        private StoreSettings $settings,
    ) {}

    /**
     * As encomendas que ja podem receber a oferta. A janela de 30 dias e para
     * nao mandar, ao ligar a funcionalidade, ofertas de compras antigas.
     *
     * @return Collection<int, Order>
     */
    public function due(): Collection
    {
        $days = $this->settings->offers()['days_after_shipping'];

        return Order::query()
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereBetween('shipped_at', [now()->subDays($days + self::WINDOW_DAYS), now()->subDays($days)])
            ->whereNotNull('email')
            // So o checkout da loja, com a caixa por marcar. Um `null` e um
            // checkout de antes da caixa: nao conta.
            ->whereExists(fn (QueryBuilder $query) => $query->select(DB::raw(1))
                ->from('checkouts')
                ->whereColumn('checkouts.order_id', 'orders.id')
                ->where('checkouts.marketing_opt_out', false))
            ->whereNotExists(fn (QueryBuilder $query) => $query->select(DB::raw(1))
                ->from('repeat_offers')
                ->whereColumn('repeat_offers.order_id', 'orders.id'))
            ->whereHas('user', fn (Builder $query) => $this->acceptsOffers($query))
            ->whereNotExists(fn (QueryBuilder $query) => $this->laterPaidOrder($query))
            // Um codigo destes por usar e ainda valido chega.
            ->whereNotExists(fn (QueryBuilder $query) => $query->select(DB::raw(1))
                ->from('promo_codes')
                ->where('promo_codes.channel', PromoCode::CHANNEL_REPEAT)
                ->whereRaw('promo_codes.email = lower(trim(orders.email))')
                ->where('promo_codes.expires_at', '>', now())
                ->whereNotExists(fn (QueryBuilder $redeemed) => $redeemed->select(DB::raw(1))
                    ->from('promo_code_redemptions')
                    ->whereColumn('promo_code_redemptions.promo_code_id', 'promo_codes.id')))
            ->orderBy('id')
            ->get();
    }

    /**
     * Cria o codigo e a oferta e poe o email na fila, tudo ou nada. O indice
     * unico de `repeat_offers.order_id` impede uma segunda oferta para a mesma
     * encomenda, mesmo com duas corridas ao mesmo tempo; o email so sai depois
     * do commit.
     */
    public function send(Order $order): void
    {
        $settings = $this->settings->offers();
        $email = (string) PromoCode::normalizeEmail($order->email);

        DB::transaction(function () use ($order, $settings, $email): void {
            $promoCode = PromoCode::query()->create([
                'code' => 'VOLTA-'.Str::upper(Str::random(8)),
                'type' => PromoCode::TYPE_PERCENT,
                'value' => $settings['percent'],
                'min_subtotal_cents' => 0,
                'expires_at' => now()->addDays($settings['valid_days']),
                'max_uses' => 1,
                'single_use_per_email' => true,
                'channel' => PromoCode::CHANNEL_REPEAT,
                'email' => $email,
                'is_active' => true,
            ]);

            RepeatOffer::query()->create([
                'order_id' => $order->id,
                'promo_code_id' => $promoCode->id,
                'email' => $email,
                'sent_at' => now(),
            ]);

            Mail::to($email)->queue(new RepeatOfferMail(
                $promoCode,
                $order->order_number,
                $this->unsubscribeUrl($order->user()->firstOrFail()),
            ));
        });
    }

    /**
     * As ofertas a lembrar: o codigo, ainda ativo e por usar, expira nos
     * proximos dias, e tudo o que deu direito a oferta ainda se mantem — a
     * encomenda paga e nao cancelada nem reembolsada, um cliente (nunca a
     * equipa) que nao recusou, e sem outra compra paga depois dela.
     *
     * @return Collection<int, RepeatOffer>
     */
    public function remindersDue(): Collection
    {
        $days = $this->settings->offers()['reminder_days'];

        return RepeatOffer::query()
            ->whereNull('reminded_at')
            ->whereHas('promoCode', fn (Builder $query) => $query
                ->where('is_active', true)
                ->whereBetween('expires_at', [now(), now()->addDays($days)])
                ->whereDoesntHave('redemptions'))
            ->whereHas('order', fn (Builder $query) => $query
                ->where('payment_status', 'paid')
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereNotExists(fn (QueryBuilder $later) => $this->laterPaidOrder($later)))
            ->whereHas('order.user', fn (Builder $query) => $this->acceptsOffers($query))
            ->orderBy('id')
            ->get();
    }

    /**
     * Marca o lembrete e poe-no na fila, juntos. A marca e condicional (so
     * se ainda nao havia): duas corridas que leram a mesma oferta tentam as
     * duas, mas so a primeira muda a linha e manda o email.
     */
    public function remind(RepeatOffer $offer): void
    {
        $order = $offer->order()->firstOrFail();

        DB::transaction(function () use ($offer, $order): void {
            $marked = RepeatOffer::query()
                ->whereKey($offer->getKey())
                ->whereNull('reminded_at')
                ->update(['reminded_at' => now()]);

            if ($marked !== 1) {
                return;
            }

            Mail::to($offer->email)->queue(new RepeatOfferReminderMail(
                $offer->promoCode()->firstOrFail(),
                $order->order_number,
                $this->unsubscribeUrl($order->user()->firstOrFail()),
            ));
        });
    }

    /**
     * O link de recusa de um clique, para o rodape e o `List-Unsubscribe`.
     *
     * Sem prazo: a lei quer que cada email deixe recusar, por mais antigo que
     * seja. Assinado sobre o caminho e nao sobre o endereco inteiro, e sempre
     * na rota sem idioma: um APP_URL com outro host (www, o proxy) nao parte a
     * assinatura. O host vem do APP_URL e nunca do pedido.
     */
    public function unsubscribeUrl(User $user): string
    {
        return rtrim((string) config('app.url'), '/')
            .URL::signedRoute('store.offers.unsubscribe', ['user' => $user->id], absolute: false);
    }

    /**
     * Uma compra paga do mesmo email depois desta encomenda (`orders`): ja
     * voltou a comprar, e a oferta ou o lembrete chegavam tarde. A consulta e
     * a de um `whereNotExists`.
     */
    private function laterPaidOrder(QueryBuilder $query): QueryBuilder
    {
        return $query->select(DB::raw(1))
            ->from('orders as later')
            ->whereRaw('lower(trim(later.email)) = lower(trim(orders.email))')
            ->where('later.payment_status', 'paid')
            ->whereColumn('later.created_at', '>', 'orders.created_at');
    }

    /**
     * Cliente (nunca a equipa, User::scopeCustomers) que nao recusou ofertas.
     * A consulta e a do `whereHas` sobre o utilizador.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function acceptsOffers(Builder $query): Builder
    {
        return $query->scopes('customers')->whereNull('marketing_opt_out_at');
    }
}
