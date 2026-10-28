<?php

namespace App\Services;

/**
 * A montra publica: se esta aberta, e os contactos e redes que mostra aos
 * clientes (rodape, pagina de contacto, botao "Fala connosco").
 *
 * O mesmo esquema do BrandSettings: config por omissao, tabela `settings` por
 * cima assim que o dono grava. O `STORE_OPEN` do .env passa a ser so o valor de
 * arranque — depois de gravado em Definicoes -> Montra, manda a BD, porque a
 * mesma instalacao vai servir mais do que uma loja.
 */
class StoreSettings
{
    public const KEY_OPEN = 'store.open';

    public const KEY_CONTACT_EMAIL = 'store.contact_email';

    public const KEY_PHONE = 'store.phone';

    public const KEY_ADDRESS = 'store.address';

    public const KEY_OPENING_HOURS = 'store.opening_hours';

    public const KEY_RESPONSE_HOURS = 'store.response_hours';

    public const KEY_SOCIAL = 'store.social';

    public const KEY_LOCALES = 'store.locales';

    /**
     * Redes que a montra sabe mostrar => [rotulo, hosts aceites]. O host
     * confere-se ao gravar: um link do TikTok colado no campo do Instagram
     * aparecia no rodape com o icone errado.
     */
    public const SOCIAL_NETWORKS = [
        'instagram' => ['Instagram', ['instagram.com']],
        'tiktok' => ['TikTok', ['tiktok.com']],
        'facebook' => ['Facebook', ['facebook.com', 'fb.com']],
        'pinterest' => ['Pinterest', ['pinterest.com', 'pinterest.pt']],
        'youtube' => ['YouTube', ['youtube.com', 'youtu.be']],
        'etsy' => ['Etsy', ['etsy.com']],
    ];

    public function __construct(
        private SettingService $settings,
    ) {}

    public function isOpen(): bool
    {
        $value = $this->settings->get(self::KEY_OPEN);

        return is_bool($value) ? $value : (bool) config('access.store_open');
    }

    /**
     * Os idiomas ligados nesta loja, pela ordem do config/locales.php. O de
     * omissao esta sempre — e o da raiz do site, sem ele nao havia montra.
     * Sem nada gravado, estao todos.
     *
     * @return array<int, string>
     */
    public function locales(): array
    {
        $all = array_keys((array) config('locales.available'));
        $default = (string) config('locales.default');
        $saved = $this->settings->get(self::KEY_LOCALES);

        if (! is_array($saved)) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            fn (string $locale): bool => $locale === $default || in_array($locale, $saved, true),
        ));
    }

    public function hasLocale(string $locale): bool
    {
        return in_array($locale, $this->locales(), true);
    }

    /**
     * Os valores tal como o formulario de Definicoes -> Montra os edita: todas
     * as redes presentes, vazias quando nao ha link.
     *
     * @return array{open: bool, contact_email: string, phone: string, address: string, opening_hours: string, response_hours: int|null, social: array<string, string>, locales: array<int, string>}
     */
    public function toForm(): array
    {
        $saved = $this->settings->get(self::KEY_SOCIAL);
        $saved = is_array($saved) ? $saved : [];

        $social = [];
        foreach (array_keys(self::SOCIAL_NETWORKS) as $network) {
            $social[$network] = is_string($saved[$network] ?? null) ? $saved[$network] : '';
        }

        $hours = $this->settings->get(self::KEY_RESPONSE_HOURS);

        return [
            'open' => $this->isOpen(),
            'contact_email' => $this->string(self::KEY_CONTACT_EMAIL),
            'phone' => $this->string(self::KEY_PHONE),
            'address' => $this->string(self::KEY_ADDRESS),
            'opening_hours' => $this->string(self::KEY_OPENING_HOURS),
            'response_hours' => is_int($hours) ? $hours : null,
            'social' => $social,
            'locales' => $this->locales(),
        ];
    }

    /**
     * O payload ja validado do UpdateStoreSettingsRequest -> o mapa
     * chave => valor pronto para o SettingService::setMany(). Redes que a
     * montra nao conhece ficam de fora.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function fromForm(array $form): array
    {
        $social = [];
        foreach (array_keys(self::SOCIAL_NETWORKS) as $network) {
            $social[$network] = trim((string) ($form['social'][$network] ?? ''));
        }

        $hours = $form['response_hours'] ?? null;

        $values = [
            self::KEY_OPEN => (bool) $form['open'],
            self::KEY_CONTACT_EMAIL => trim((string) ($form['contact_email'] ?? '')),
            self::KEY_PHONE => trim((string) ($form['phone'] ?? '')),
            self::KEY_ADDRESS => trim((string) ($form['address'] ?? '')),
            self::KEY_OPENING_HOURS => trim((string) ($form['opening_hours'] ?? '')),
            self::KEY_RESPONSE_HOURS => $hours === null ? null : (int) $hours,
            self::KEY_SOCIAL => $social,
        ];

        // So quando o formulario os traz: um pedido sem `locales` nao desliga
        // idioma nenhum.
        if (array_key_exists('locales', $form)) {
            $values[self::KEY_LOCALES] = array_values(array_unique([
                (string) config('locales.default'),
                ...array_map('strval', (array) $form['locales']),
            ]));
        }

        return $values;
    }

    /**
     * As redes como opcoes para o formulario: { value, label }, a forma do
     * `Option` do frontend (resources/js/lib/options.ts).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function networkOptions(): array
    {
        return collect(self::SOCIAL_NETWORKS)
            ->map(fn (array $network, string $key): array => ['value' => $key, 'label' => $network[0]])
            ->values()
            ->all();
    }

    /**
     * Os idiomas como opcoes para o formulario, com o de omissao marcado (nao
     * se pode desligar).
     *
     * @return array<int, array{value: string, label: string, locked: bool}>
     */
    public function localeOptions(): array
    {
        $default = (string) config('locales.default');

        return collect((array) config('locales.available'))
            ->map(fn (array $locale, string $code): array => [
                'value' => $code,
                'label' => (string) $locale['name'],
                'locked' => $code === $default,
            ])
            ->values()
            ->all();
    }

    private function string(string $key): string
    {
        $value = $this->settings->get($key);

        return is_string($value) ? $value : '';
    }
}
