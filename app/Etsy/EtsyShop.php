<?php

namespace App\Etsy;

use App\Models\EtsyConnection;
use App\Models\EtsyListing;
use Illuminate\Support\Facades\Cache;

/**
 * A loja em si: os textos que os compradores leem, as seccoes em que os
 * anuncios se arrumam, e as politicas de devolucao.
 *
 * Como os envios (EtsyShipping), nada disto se copia para a base de dados:
 * le-se e escreve-se na Etsy, com uma cache curta que as escritas deitam fora.
 */
class EtsyShop
{
    /** Os textos da loja que a Etsy deixa mudar (updateShop). */
    public const TEXT_FIELDS = ['title', 'announcement', 'sale_message', 'digital_sale_message', 'policy_additional'];

    private const CACHE_MINUTES = 5;

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
        private EtsySettings $settings,
        private EtsyUsage $usage,
    ) {}

    // ── Textos ─────────────────────────────────────────────────────────────

    /**
     * Os textos publicos da loja, como la estao.
     *
     * @return array<string, string|null>
     */
    public function texts(): array
    {
        $shopId = $this->connection()->shop_id;

        /** @var array<string, string|null> $texts */
        $texts = Cache::remember("etsy:shop:{$shopId}:texts", now()->addMinutes(self::CACHE_MINUTES), function () use ($shopId): array {
            $shop = $this->api->shop($shopId);
            $texts = [];

            foreach (self::TEXT_FIELDS as $field) {
                $texts[$field] = is_string($shop[$field] ?? null) ? $shop[$field] : null;
            }

            return $texts;
        });

        return $texts;
    }

    /**
     * Muda os textos que vierem em $data, e so esses. Uma string vazia limpa
     * o texto la; uma chave que nao vem fica como esta.
     *
     * @param  array<string, mixed>  $data
     * @return array{before: array<string, string|null>, after: array<string, string>}
     */
    public function updateTexts(array $data): array
    {
        $current = $this->texts();
        $after = [];

        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $after[$field] = (string) ($data[$field] ?? '');
            }
        }

        try {
            $this->api->updateShop($this->connection()->shop_id, $after);
        } finally {
            $this->flush();
        }

        return ['before' => array_intersect_key($current, $after), 'after' => $after];
    }

    // ── Seccoes ────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{shop_section_id: int, title: string, rank: int|null, active_listing_count: int|null}>
     */
    public function sections(): array
    {
        $shopId = $this->connection()->shop_id;

        /** @var array<int, array{shop_section_id: int, title: string, rank: int|null, active_listing_count: int|null}> $sections */
        $sections = Cache::remember("etsy:shop:{$shopId}:sections", now()->addMinutes(self::CACHE_MINUTES), fn (): array => array_map(
            fn (array $section): array => [
                'shop_section_id' => (int) ($section['shop_section_id'] ?? 0),
                'title' => (string) ($section['title'] ?? ''),
                'rank' => is_numeric($section['rank'] ?? null) ? (int) $section['rank'] : null,
                'active_listing_count' => is_numeric($section['active_listing_count'] ?? null) ? (int) $section['active_listing_count'] : null,
            ],
            EtsyPresenter::rows($this->api->sections($shopId)['results'] ?? null),
        ));

        return $sections;
    }

    public function saveSection(string $title, ?int $sectionId = null): int
    {
        $shopId = $this->connection()->shop_id;

        try {
            $saved = $sectionId === null
                ? $this->api->createSection($shopId, $title)
                : $this->api->updateSection($shopId, $sectionId, $title);

            return (int) ($saved['shop_section_id'] ?? $sectionId ?? 0);
        } finally {
            $this->flush();
        }
    }

    /**
     * Na Etsy os anuncios da seccao ficam sem seccao. Ca tambem tem de
     * ficar: um anuncio a apontar para uma seccao que ja nao existe fazia o
     * envio seguinte falhar.
     */
    public function deleteSection(int $sectionId): void
    {
        try {
            $this->api->deleteSection($this->connection()->shop_id, $sectionId);

            EtsyListing::query()->where('shop_section_id', $sectionId)->update(['shop_section_id' => null]);
            $this->settings->forgetSection($sectionId);
        } finally {
            $this->flush();
        }
    }

    // ── Politicas de devolucao ─────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function returnPolicies(): array
    {
        $shopId = $this->connection()->shop_id;
        $usage = $this->usage->counts(EtsyUsage::RETURN_POLICY);
        $default = $this->settings->defaultReturnPolicyId();

        /** @var array<int, array{return_policy_id: int, accepts_returns: bool, accepts_exchanges: bool, return_deadline: int|null}> $policies */
        $policies = Cache::remember("etsy:shop:{$shopId}:policies", now()->addMinutes(self::CACHE_MINUTES), fn (): array => array_map(
            fn (array $policy): array => [
                'return_policy_id' => (int) ($policy['return_policy_id'] ?? 0),
                'accepts_returns' => (bool) ($policy['accepts_returns'] ?? false),
                'accepts_exchanges' => (bool) ($policy['accepts_exchanges'] ?? false),
                'return_deadline' => is_numeric($policy['return_deadline'] ?? null) ? (int) $policy['return_deadline'] : null,
            ],
            EtsyPresenter::rows($this->api->returnPolicies($shopId)['results'] ?? null),
        ));

        return array_map(fn (array $policy): array => [
            ...$policy,
            'listings' => $usage[$policy['return_policy_id']]['listings'] ?? 0,
            'active_listings' => $usage[$policy['return_policy_id']]['active'] ?? 0,
            'is_default' => $policy['return_policy_id'] === $default,
        ], $policies);
    }

    public function activeListingsUsingReturnPolicy(int $policyId): int
    {
        return $this->usage->active(EtsyUsage::RETURN_POLICY, $policyId);
    }

    /**
     * @param  array<string, mixed>  $data  Ja validado pelo SaveReturnPolicyRequest.
     */
    public function saveReturnPolicy(array $data): int
    {
        $shopId = $this->connection()->shop_id;
        $policyId = is_numeric($data['policy_id'] ?? null) ? (int) $data['policy_id'] : null;

        $accepts = (bool) $data['accepts_returns'] || (bool) $data['accepts_exchanges'];

        $fields = [
            'accepts_returns' => (bool) $data['accepts_returns'],
            'accepts_exchanges' => (bool) $data['accepts_exchanges'],
            // Sem devolucoes nem trocas nao ha prazo nenhum para mandar.
            'return_deadline' => $accepts && is_numeric($data['return_deadline'] ?? null) ? (int) $data['return_deadline'] : null,
        ];

        try {
            $saved = $policyId === null
                ? $this->api->createReturnPolicy($shopId, $fields)
                : $this->api->updateReturnPolicy($shopId, $policyId, $fields);

            return (int) ($saved['return_policy_id'] ?? $policyId ?? 0);
        } finally {
            $this->flush();
        }
    }

    public function deleteReturnPolicy(int $policyId): void
    {
        $users = $this->usage->products(EtsyUsage::RETURN_POLICY, $policyId);

        if ($users !== []) {
            throw new EtsyException('Esta política de devolução está a ser usada por: '.implode(', ', $users).'. Dá-lhes outra antes de a apagar.');
        }

        try {
            $this->api->deleteReturnPolicy($this->connection()->shop_id, $policyId);
            $this->settings->forgetReference($policyId, 'default_return_policy_id');
        } finally {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $shopId = $this->tokens->connection()?->shop_id;

        if ($shopId !== null) {
            Cache::forget("etsy:shop:{$shopId}:texts");
            Cache::forget("etsy:shop:{$shopId}:sections");
            Cache::forget("etsy:shop:{$shopId}:policies");
        }
    }

    private function connection(): EtsyConnection
    {
        return $this->tokens->connection() ?? throw EtsyException::notConnected();
    }
}
