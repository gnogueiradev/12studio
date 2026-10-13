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
    /** Nome curto => chave na tabela `settings`. */
    private const IDS = [
        'default_shipping_profile_id' => 'etsy.default_shipping_profile_id',
        'ready_to_ship_profile_id' => 'etsy.ready_to_ship_profile_id',
        'made_to_order_profile_id' => 'etsy.made_to_order_profile_id',
        'default_return_policy_id' => 'etsy.default_return_policy_id',
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

        if (array_key_exists('section_map', $values)) {
            $this->settings->set(self::KEY_SECTION_MAP, $this->cleanMap($values['section_map']));
        }
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
