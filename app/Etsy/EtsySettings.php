<?php

namespace App\Etsy;

use App\Services\SettingService;

/**
 * As escolhas do dono sobre a loja da Etsy que sao NOSSAS e nao dela: que
 * perfil de envio e de processamento leva um anuncio novo, e a que seccao da
 * loja vai cada categoria do 12studio.
 *
 * Vivem na tabela `settings` (chaves `etsy.*`), como as definicoes de precos
 * — sao preferencias, mudam poucas vezes e nao ha mal em ficarem na memoria
 * do processo. Os tokens, esses, estao em etsy_connections.
 */
class EtsySettings
{
    /** Nome curto => chave na tabela `settings`. Ids de coisas da Etsy. */
    private const IDS = [
        'default_shipping_profile_id' => 'etsy.default_shipping_profile_id',
        'ready_to_ship_profile_id' => 'etsy.ready_to_ship_profile_id',
        'made_to_order_profile_id' => 'etsy.made_to_order_profile_id',
        'default_return_policy_id' => 'etsy.default_return_policy_id',
        'default_taxonomy_id' => 'etsy.default_taxonomy_id',
    ];

    /** Numeros nossos (>= 0), com o valor de config/etsy.php por omissao. */
    private const NUMBERS = [
        'markup_bp' => 'etsy.markup_bp',
        'fee_percentage_bp' => 'etsy.fee_percentage_bp',
        'fee_fixed_cents' => 'etsy.fee_fixed_cents',
        'mto_quantity' => 'etsy.mto_quantity',
        'stock_buffer' => 'etsy.stock_buffer',
    ];

    /** Escolhas de uma lista fechada de config/etsy.php. */
    private const CHOICES = [
        'who_made' => 'etsy.who_made',
        'when_made' => 'etsy.when_made',
    ];

    private const KEY_SECTION_MAP = 'etsy.section_map';

    public function __construct(
        private SettingService $settings,
    ) {}

    /**
     * Grava so as chaves que vierem. Um id a null tira a predefinicao.
     *
     * @param  array<string, mixed>  $values  Chaves de IDS e/ou `section_map`.
     */
    public function save(array $values): void
    {
        foreach (self::IDS as $name => $key) {
            if (! array_key_exists($name, $values)) {
                continue;
            }

            $id = is_numeric($values[$name]) && (int) $values[$name] > 0 ? (int) $values[$name] : null;

            $id === null ? $this->settings->forget($key) : $this->settings->set($key, $id);
        }

        foreach (self::NUMBERS as $name => $key) {
            if (array_key_exists($name, $values) && is_numeric($values[$name])) {
                $this->settings->set($key, max(0, (int) $values[$name]));
            }
        }

        foreach (self::CHOICES as $name => $key) {
            if (array_key_exists($name, $values) && in_array($values[$name], (array) config("etsy.listing.{$name}"), true)) {
                $this->settings->set($key, $values[$name]);
            }
        }

        if (array_key_exists('section_map', $values)) {
            $this->settings->set(self::KEY_SECTION_MAP, $this->cleanMap($values['section_map']));
        }
    }

    // ── Anuncios e precos ──────────────────────────────────────────────────

    /** A categoria da Etsy de um anuncio que nao escolheu a sua. */
    public function defaultTaxonomyId(): ?int
    {
        return $this->id('default_taxonomy_id');
    }

    public function whoMade(): string
    {
        return $this->choice('who_made', 'i_did');
    }

    public function whenMade(): string
    {
        return $this->choice('when_made', 'made_to_order');
    }

    /**
     * A margem global sobre o preco do site, em pontos base. Zero por
     * omissao: enquanto o dono nao decidir, a Etsy leva o preco do site.
     */
    public function markupBp(): int
    {
        return $this->number('markup_bp', 0);
    }

    /** A parte das taxas da Etsy que e percentagem do que o comprador paga. */
    public function feePercentageBp(): int
    {
        return $this->number('fee_percentage_bp', (int) config('etsy.fees.percentage_bp', 0));
    }

    /** A parte das taxas da Etsy que e fixa por venda. */
    public function feeFixedCents(): int
    {
        return $this->number('fee_fixed_cents', (int) config('etsy.fees.fixed_cents', 0));
    }

    /** Quantas unidades anuncia um produto feito por encomenda. */
    public function mtoQuantity(): int
    {
        return max(1, $this->number('mto_quantity', (int) config('etsy.mto_quantity', 10)));
    }

    /** Unidades em stock que ficam por anunciar, de reserva. */
    public function stockBuffer(): int
    {
        return $this->number('stock_buffer', 0);
    }

    public function defaultShippingProfileId(): ?int
    {
        return $this->id('default_shipping_profile_id');
    }

    /** O perfil de processamento dos produtos que saem da prateleira. */
    public function readyToShipProfileId(): ?int
    {
        return $this->id('ready_to_ship_profile_id');
    }

    /** O perfil de processamento dos produtos feitos por encomenda. */
    public function madeToOrderProfileId(): ?int
    {
        return $this->id('made_to_order_profile_id');
    }

    public function defaultReturnPolicyId(): ?int
    {
        return $this->id('default_return_policy_id');
    }

    /**
     * Categoria do 12studio => seccao da loja na Etsy.
     *
     * @return array<int, int>
     */
    public function sectionMap(): array
    {
        return $this->cleanMap($this->settings->get(self::KEY_SECTION_MAP));
    }

    public function sectionFor(?int $categoryId): ?int
    {
        return $categoryId === null ? null : ($this->sectionMap()[$categoryId] ?? null);
    }

    /**
     * Para as paginas e o MCP: as quatro predefinicoes de uma vez.
     *
     * @return array<string, int|null>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach (array_keys(self::IDS) as $name) {
            $defaults[$name] = $this->id($name);
        }

        return $defaults;
    }

    /**
     * Um perfil ou uma politica foi apagado na Etsy: deixa de ser
     * predefinicao de alguma coisa.
     */
    public function forgetReference(int $id, string ...$names): void
    {
        foreach ($names as $name) {
            if ($this->id($name) === $id) {
                $this->settings->forget(self::IDS[$name]);
            }
        }
    }

    public function forgetSection(int $sectionId): void
    {
        $this->settings->set(
            self::KEY_SECTION_MAP,
            array_filter($this->sectionMap(), fn (int $mapped): bool => $mapped !== $sectionId),
        );
    }

    private function id(string $name): ?int
    {
        $value = $this->settings->get(self::IDS[$name]);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function number(string $name, int $default): int
    {
        $value = $this->settings->get(self::NUMBERS[$name]);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    private function choice(string $name, string $default): string
    {
        $value = $this->settings->get(self::CHOICES[$name]);

        return is_string($value) && in_array($value, (array) config("etsy.listing.{$name}"), true) ? $value : $default;
    }

    /**
     * @return array<int, int>
     */
    private function cleanMap(mixed $map): array
    {
        $clean = [];

        foreach (is_array($map) ? $map : [] as $categoryId => $sectionId) {
            if (is_numeric($categoryId) && is_numeric($sectionId) && (int) $sectionId > 0) {
                $clean[(int) $categoryId] = (int) $sectionId;
            }
        }

        return $clean;
    }
}
