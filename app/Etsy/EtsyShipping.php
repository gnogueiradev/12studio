<?php

namespace App\Etsy;

use App\Models\EtsyConnection;
use App\Models\EtsyListing;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Os envios da loja na Etsy: perfis de envio (com os destinos e os upgrades
 * de cada um) e perfis de processamento.
 *
 * Sao objetos da Etsy sem equivalente no 12studio, e por isso NAO se copiam
 * para a base de dados: le-se e escreve-se la. O que se guarda e uma cache
 * curta das leituras (uma tarde a acertar perfis nao pode gastar o limite
 * diario de pedidos) que qualquer escrita deita fora.
 *
 * Uma coisa a saber antes de mexer: na Etsy, EDITAR um perfil so muda o
 * titulo e a origem. Os custos vivem nos destinos.
 */
class EtsyShipping
{
    /** Minutos que as leituras da configuracao ficam em cache. */
    private const CACHE_MINUTES = 5;

    /** As transportadoras mudam uma vez por ano, se tanto. */
    private const CARRIERS_CACHE_DAYS = 7;

    /** Como a Etsy numera o tipo de um upgrade. */
    private const UPGRADE_TYPES = ['domestic' => 0, 'international' => 1];

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
        private EtsySettings $settings,
        private EtsyUsage $usage,
    ) {}

    // ── Ler ────────────────────────────────────────────────────────────────

    /**
     * Os perfis de envio, cada um com destinos, upgrades e quantos anuncios o
     * usam.
     *
     * @return array<int, array<string, mixed>>
     */
    public function profiles(): array
    {
        $usage = $this->usage->counts(EtsyUsage::SHIPPING_PROFILE);
        $default = $this->settings->defaultShippingProfileId();

        return array_map(fn (array $profile): array => [
            ...$profile,
            'listings' => $usage[$profile['shipping_profile_id']]['listings'] ?? 0,
            'active_listings' => $usage[$profile['shipping_profile_id']]['active'] ?? 0,
            'is_default' => $profile['shipping_profile_id'] === $default,
        ], $this->remoteProfiles());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function processingProfiles(): array
    {
        $usage = $this->usage->counts(EtsyUsage::PROCESSING_PROFILE);

        return array_map(fn (array $profile): array => [
            ...$profile,
            'listings' => $usage[$profile['readiness_state_id']]['listings'] ?? 0,
            'active_listings' => $this->activeListingsUsingProcessing($profile['readiness_state_id']),
            'is_default_for' => $this->defaultRole($profile['readiness_state_id']),
        ], $this->remoteProcessingProfiles());
    }

    /**
     * As transportadoras que a Etsy conhece a sair de um pais, com as
     * classes de correio de cada uma.
     *
     * @return array<int, array<string, mixed>>
     */
    public function carriers(string $originCountryIso = 'PT'): array
    {
        $iso = strtoupper($originCountryIso);

        /** @var array<int, array<string, mixed>> $carriers */
        $carriers = Cache::remember("etsy:carriers:{$iso}", now()->addDays(self::CARRIERS_CACHE_DAYS), function () use ($iso): array {
            $classes = fn (mixed $rows): array => array_map(fn (array $class): array => [
                'mail_class_key' => $class['mail_class_key'] ?? null,
                'name' => $class['name'] ?? null,
            ], EtsyPresenter::rows($rows));

            return array_map(fn (array $carrier): array => [
                'shipping_carrier_id' => $carrier['shipping_carrier_id'] ?? null,
                'name' => $carrier['name'] ?? null,
                'domestic_classes' => $classes($carrier['domestic_classes'] ?? null),
                'international_classes' => $classes($carrier['international_classes'] ?? null),
            ], EtsyPresenter::rows($this->api->shippingCarriers($iso)['results'] ?? null));
        });

        return $carriers;
    }

    public function profileExists(int $profileId): bool
    {
        return in_array($profileId, array_column($this->remoteProfiles(), 'shipping_profile_id'), true);
    }

    /**
     * Anuncios A VENDA que usam este perfil de envio — os que mudam de
     * portes, a vista dos compradores, quando se lhe mexe nos destinos.
     */
    public function activeListingsUsingProfile(int $profileId): int
    {
        return $this->usage->active(EtsyUsage::SHIPPING_PROFILE, $profileId);
    }

    /**
     * Um perfil de processamento esta em uso pelos anuncios que o escolheram
     * e, sendo a predefinicao, por todos os que nao escolheram nenhum.
     */
    public function activeListingsUsingProcessing(int $definitionId): int
    {
        $explicit = $this->usage->active(EtsyUsage::PROCESSING_PROFILE, $definitionId);

        if ($this->defaultRole($definitionId) === null) {
            return $explicit;
        }

        return $explicit + EtsyListing::query()->where('state', 'active')->whereNull('readiness_state_id')->count();
    }

    // ── Perfis de envio ────────────────────────────────────────────────────

    /**
     * Cria um perfil (com o primeiro destino) ou edita o titulo e a origem
     * de um que ja existe. Devolve o id.
     *
     * @param  array<string, mixed>  $data  Ja validado pelo SaveShippingProfileRequest.
     */
    public function saveProfile(array $data): int
    {
        $shopId = $this->connection()->shop_id;
        $profileId = $this->id($data['shipping_profile_id'] ?? null);

        $identity = [
            'title' => $data['title'],
            'origin_country_iso' => strtoupper((string) $data['origin_country_iso']),
            'origin_postal_code' => $data['origin_postal_code'] ?? null,
        ];

        try {
            if ($profileId !== null) {
                // So a identidade: um custo aqui era ignorado pela Etsy em
                // silencio, e quem o mandou ficava a pensar que tinha mudado.
                $this->api->updateShippingProfile($shopId, $profileId, $identity);

                return $profileId;
            }

            $created = $this->api->createShippingProfile($shopId, [...$identity, ...$this->destinationFields($data)]);

            return (int) ($created['shipping_profile_id'] ?? 0);
        } finally {
            $this->flush();
        }
    }

    public function deleteProfile(int $profileId): void
    {
        $users = $this->usage->products(EtsyUsage::SHIPPING_PROFILE, $profileId);

        if ($users !== []) {
            throw new EtsyException('Este perfil de envio está a ser usado por: '.implode(', ', $users).'. Dá-lhes outro perfil antes de o apagar.');
        }

        try {
            $this->api->deleteShippingProfile($this->connection()->shop_id, $profileId);
            $this->settings->forgetReference($profileId, 'default_shipping_profile_id');
        } finally {
            $this->flush();
        }
    }

    /**
     * @param  array<string, mixed>  $data  Ja validado pelo SaveShippingDestinationRequest.
     */
    public function saveDestination(int $profileId, array $data): int
    {
        $shopId = $this->connection()->shop_id;
        $destinationId = $this->id($data['destination_id'] ?? null);

        try {
            $saved = $destinationId === null
                ? $this->api->createShippingDestination($shopId, $profileId, $this->destinationFields($data))
                : $this->api->updateShippingDestination($shopId, $profileId, $destinationId, $this->destinationFields($data));

            return (int) ($saved['shipping_profile_destination_id'] ?? $destinationId ?? 0);
        } finally {
            $this->flush();
        }
    }

    public function deleteDestination(int $profileId, int $destinationId): void
    {
        try {
            $this->api->deleteShippingDestination($this->connection()->shop_id, $profileId, $destinationId);
        } finally {
            $this->flush();
        }
    }

    /**
     * @param  array<string, mixed>  $data  Ja validado pelo SaveShippingUpgradeRequest.
     */
    public function saveUpgrade(int $profileId, array $data): int
    {
        $shopId = $this->connection()->shop_id;
        $upgradeId = $this->id($data['upgrade_id'] ?? null);

        $fields = [
            'upgrade_name' => $data['upgrade_name'],
            'type' => self::UPGRADE_TYPES[(string) $data['type']],
            'price' => $this->euros($data['price']),
            'secondary_price' => $this->euros($data['secondary_price']),
            ...$this->deliveryFields($data),
        ];

        try {
            $saved = $upgradeId === null
                ? $this->api->createShippingUpgrade($shopId, $profileId, $fields)
                : $this->api->updateShippingUpgrade($shopId, $profileId, $upgradeId, $fields);

            return (int) ($saved['upgrade_id'] ?? $upgradeId ?? 0);
        } finally {
            $this->flush();
        }
    }

    public function deleteUpgrade(int $profileId, int $upgradeId): void
    {
        try {
            $this->api->deleteShippingUpgrade($this->connection()->shop_id, $profileId, $upgradeId);
        } finally {
            $this->flush();
        }
    }

    // ── Perfis de processamento ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  Ja validado pelo SaveProcessingProfileRequest.
     */
    public function saveProcessingProfile(array $data): int
    {
        $shopId = $this->connection()->shop_id;
        $definitionId = $this->id($data['definition_id'] ?? null);

        $fields = [
            'readiness_state' => $data['readiness_state'],
            'min_processing_time' => (int) $data['min_processing_time'],
            'max_processing_time' => (int) $data['max_processing_time'],
            'processing_time_unit' => $data['processing_time_unit'],
        ];

        try {
            $saved = $definitionId === null
                ? $this->api->createProcessingProfile($shopId, $fields)
                : $this->api->updateProcessingProfile($shopId, $definitionId, $fields);

            return (int) ($saved['readiness_state_id'] ?? $definitionId ?? 0);
        } finally {
            $this->flush();
        }
    }

    public function deleteProcessingProfile(int $definitionId): void
    {
        $role = $this->defaultRole($definitionId);

        if ($role !== null) {
            throw new EtsyException(sprintf(
                'Este perfil de processamento é a predefinição dos produtos %s. Escolhe outro em Etsy → Envios antes de o apagar.',
                $role === 'ready_to_ship' ? 'prontos a enviar' : 'feitos por encomenda',
            ));
        }

        $users = $this->usage->products(EtsyUsage::PROCESSING_PROFILE, $definitionId);

        if ($users !== []) {
            throw new EtsyException('Este perfil de processamento está a ser usado por: '.implode(', ', $users).'. Dá-lhes outro antes de o apagar.');
        }

        try {
            $this->api->deleteProcessingProfile($this->connection()->shop_id, $definitionId);
        } finally {
            $this->flush();
        }
    }

    // ── Atribuir ───────────────────────────────────────────────────────────

    /**
     * Os produtos que ja tem anuncio a venda — os que mudam de portes a vista
     * dos compradores se o perfil for trocado.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string>
     */
    public function activeAmong(array $productIds): array
    {
        return EtsyListing::query()
            ->whereIn('product_id', $productIds)
            ->where('state', 'active')
            ->whereNotNull('etsy_listing_id')
            ->with('product:id,name,deleted_at')
            ->get()
            ->map(fn (EtsyListing $listing): string => $listing->product->name)
            ->all();
    }

    /**
     * Da um perfil de envio a varios produtos.
     *
     * O anuncio que ja existe na Etsy muda la primeiro, e so depois ca: se a
     * Etsy recusar, o 12studio nao fica a dizer uma coisa e ela outra. O
     * produto que ainda nao tem anuncio fica so com o perfil escolhido, para
     * quando for enviado.
     *
     * @param  array<int, int>  $productIds
     * @return array{updated: array<int, string>, prepared: array<int, string>, failed: array<string, string>}
     */
    public function assign(array $productIds, int $profileId): array
    {
        $shopId = $this->connection()->shop_id;
        $result = ['updated' => [], 'prepared' => [], 'failed' => []];

        foreach (Product::query()->whereKey($productIds)->orderBy('name')->get() as $product) {
            $listing = EtsyListing::query()->firstOrNew(['product_id' => $product->id]);

            if ($listing->etsy_listing_id === null) {
                $listing->fill(['shipping_profile_id' => $profileId])->save();
                $result['prepared'][] = $product->name;

                continue;
            }

            try {
                $this->api->updateListing($shopId, $listing->etsy_listing_id, ['shipping_profile_id' => $profileId]);
            } catch (EtsyException $exception) {
                $result['failed'][$product->name] = $exception->getMessage();

                continue;
            }

            $listing->update(['shipping_profile_id' => $profileId]);
            $result['updated'][] = $product->name;
        }

        return $result;
    }

    /**
     * Deita fora o que se lembrava da configuracao: a seguir a uma escrita,
     * quem le tem de ver o que la ficou.
     */
    public function flush(): void
    {
        $shopId = $this->tokens->connection()?->shop_id;

        if ($shopId !== null) {
            Cache::forget("etsy:shipping:{$shopId}:profiles");
            Cache::forget("etsy:shipping:{$shopId}:processing");
        }
    }

    // ── Por dentro ─────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function remoteProfiles(): array
    {
        $shopId = $this->connection()->shop_id;

        /** @var array<int, array<string, mixed>> $profiles */
        $profiles = Cache::remember("etsy:shipping:{$shopId}:profiles", now()->addMinutes(self::CACHE_MINUTES), fn (): array => array_map(
            fn (array $profile): array => [
                'shipping_profile_id' => (int) ($profile['shipping_profile_id'] ?? 0),
                'title' => (string) ($profile['title'] ?? ''),
                'origin_country_iso' => $profile['origin_country_iso'] ?? null,
                'origin_postal_code' => $profile['origin_postal_code'] ?? null,
                'destinations' => array_map(fn (array $destination): array => [
                    'shipping_profile_destination_id' => (int) ($destination['shipping_profile_destination_id'] ?? 0),
                    'destination_country_iso' => $this->blankToNull($destination['destination_country_iso'] ?? null),
                    'destination_region' => $destination['destination_region'] ?? 'none',
                    'primary_cost' => EtsyPresenter::money($destination['primary_cost'] ?? null),
                    'secondary_cost' => EtsyPresenter::money($destination['secondary_cost'] ?? null),
                    'min_delivery_days' => $destination['min_delivery_days'] ?? null,
                    'max_delivery_days' => $destination['max_delivery_days'] ?? null,
                    'shipping_carrier_id' => $destination['shipping_carrier_id'] ?? null,
                    'mail_class' => $destination['mail_class'] ?? null,
                ], EtsyPresenter::rows($profile['shipping_profile_destinations'] ?? null)),
                'upgrades' => array_map(fn (array $upgrade): array => [
                    'upgrade_id' => (int) ($upgrade['upgrade_id'] ?? 0),
                    'upgrade_name' => (string) ($upgrade['upgrade_name'] ?? ''),
                    'type' => (int) ($upgrade['type'] ?? 0) === 1 ? 'international' : 'domestic',
                    'price' => EtsyPresenter::money($upgrade['price'] ?? null),
                    'secondary_price' => EtsyPresenter::money($upgrade['secondary_price'] ?? null),
                    'min_delivery_days' => $upgrade['min_delivery_days'] ?? null,
                    'max_delivery_days' => $upgrade['max_delivery_days'] ?? null,
                ], EtsyPresenter::rows($profile['shipping_profile_upgrades'] ?? null)),
            ],
            EtsyPresenter::rows($this->api->shippingProfiles($shopId)['results'] ?? null),
        ));

        return $profiles;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function remoteProcessingProfiles(): array
    {
        $shopId = $this->connection()->shop_id;

        /** @var array<int, array<string, mixed>> $profiles */
        $profiles = Cache::remember("etsy:shipping:{$shopId}:processing", now()->addMinutes(self::CACHE_MINUTES), fn (): array => array_map(
            fn (array $profile): array => [
                'readiness_state_id' => (int) ($profile['readiness_state_id'] ?? 0),
                'readiness_state' => (string) ($profile['readiness_state'] ?? ''),
                'min_processing_time' => $profile['min_processing_time'] ?? null,
                'max_processing_time' => $profile['max_processing_time'] ?? null,
                'processing_time_unit' => $profile['processing_time_unit'] ?? 'days',
            ],
            EtsyPresenter::rows($this->api->processingProfiles($shopId)['results'] ?? null),
        ));

        return $profiles;
    }

    private function defaultRole(int $definitionId): ?string
    {
        return match ($definitionId) {
            $this->settings->readyToShipProfileId() => 'ready_to_ship',
            $this->settings->madeToOrderProfileId() => 'made_to_order',
            default => null,
        };
    }

    /**
     * Para onde, por quanto e em quanto tempo — o que um destino e (e o que
     * um perfil novo leva do seu primeiro destino).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function destinationFields(array $data): array
    {
        $country = $this->blankToNull($data['destination_country_iso'] ?? null);

        return [
            'primary_cost' => $this->euros($data['primary_cost']),
            'secondary_cost' => $this->euros($data['secondary_cost']),
            // Um pais OU uma regiao; o que nao for vai a null e nao segue.
            'destination_country_iso' => $country === null ? null : strtoupper($country),
            'destination_region' => $country === null ? ($data['destination_region'] ?? null) : null,
            ...$this->deliveryFields($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function deliveryFields(array $data): array
    {
        return [
            'min_delivery_days' => $this->id($data['min_delivery_days'] ?? null),
            'max_delivery_days' => $this->id($data['max_delivery_days'] ?? null),
            'shipping_carrier_id' => $this->id($data['shipping_carrier_id'] ?? null),
            'mail_class' => $this->blankToNull($data['mail_class'] ?? null),
        ];
    }

    /**
     * "4,50" ou "4.5" -> "4.50": a Etsy quer euros com ponto, e os centimos
     * passam pelo Money como em todo o lado.
     */
    private function euros(mixed $value): string
    {
        return number_format(Money::fromDecimal((string) $value) / 100, 2, '.', '');
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function blankToNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function connection(): EtsyConnection
    {
        return $this->tokens->connection() ?? throw EtsyException::notConnected();
    }
}
