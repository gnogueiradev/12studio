<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * O tema da montra: cinco cores de base por modo (claro e escuro), o
 * arredondamento dos cantos, a letra dos titulos e do texto, o logotipo e o
 * favicon. Fica na tabela `settings`, e nao no app.css, porque cada loja tem
 * a sua marca e o mesmo build serve-as a todas.
 *
 * So a montra muda: as variaveis saem num seletor `.store-theme`, que o
 * StoreLayout e a landing "em breve" poem na raiz. O backoffice fica com o
 * aspeto de sempre — uma cor mal escolhida nunca o torna ilegivel.
 *
 * Os restantes tokens (cartoes, bordas, texto secundario) derivam das cinco
 * com color-mix, para a paleta continuar coerente seja qual for a escolha.
 * Sem nada gravado nao sai CSS nenhum: valem os valores do app.css.
 */
class ThemeSettings
{
    public const KEY = 'theme';

    public const MODES = ['light', 'dark'];

    public const COLORS = ['background', 'foreground', 'primary', 'accent', 'gold'];

    /** Os valores do resources/css/app.css — o "Repor tema original". */
    public const DEFAULTS = [
        'light' => [
            'background' => '#faf8f5',
            'foreground' => '#332f2b',
            'primary' => '#332f2b',
            'accent' => '#f1ece5',
            'gold' => '#c6a77b',
        ],
        'dark' => [
            'background' => '#211e1c',
            'foreground' => '#faf8f5',
            'primary' => '#faf8f5',
            'accent' => '#332f2b',
            'gold' => '#c6a77b',
        ],
    ];

    /** Arredondamento => valor do --radius. */
    public const RADII = [
        'sharp' => '0.25rem',
        'soft' => '0.625rem',
        'round' => '1rem',
    ];

    public const DEFAULT_RADIUS = 'soft';

    /**
     * As letras que o tema pode usar: alias do @fonts (vite.config.ts) =>
     * [nome, pilha CSS]. Todas alojadas no proprio site (o CSP so deixa
     * `font-src 'self'`); o Blade so carrega as escolhidas.
     */
    public const FONTS = [
        'instrument-sans' => ['Instrument Sans', "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"],
        'inter' => ['Inter', "'Inter', ui-sans-serif, system-ui, sans-serif"],
        'space-grotesk' => ['Space Grotesk', "'Space Grotesk', ui-sans-serif, system-ui, sans-serif"],
        'fraunces' => ['Fraunces', "'Fraunces', ui-serif, Georgia, serif"],
        'dm-serif-display' => ['DM Serif Display', "'DM Serif Display', ui-serif, Georgia, serif"],
    ];

    /** Letras so para titulos: num paragrafo cansam. */
    public const HEADING_ONLY_FONTS = ['dm-serif-display'];

    public const DEFAULT_FONT = 'instrument-sans';

    public function __construct(
        private SettingService $settings,
    ) {}

    /**
     * O tema em vigor, completo: o gravado por cima dos valores de origem.
     * Uma cor gravada que nao seja um hex valido e ignorada — este valor vai
     * parar a um <style>, e nada que nao seja #rrggbb pode la chegar.
     *
     * @return array{light: array<string, string>, dark: array<string, string>, radius: string, body_font: string, heading_font: string, logo: int|null, favicon: int|null}
     */
    public function values(): array
    {
        $saved = $this->saved();
        $theme = ['radius' => self::DEFAULT_RADIUS];

        foreach (self::MODES as $mode) {
            foreach (self::COLORS as $color) {
                $value = $saved[$mode][$color] ?? null;
                $theme[$mode][$color] = self::isHex($value) ? strtolower($value) : self::DEFAULTS[$mode][$color];
            }
        }

        if (is_string($saved['radius'] ?? null) && array_key_exists($saved['radius'], self::RADII)) {
            $theme['radius'] = $saved['radius'];
        }

        $body = $saved['body_font'] ?? null;
        $theme['body_font'] = is_string($body) && array_key_exists($body, self::FONTS) && ! in_array($body, self::HEADING_ONLY_FONTS, true)
            ? $body
            : self::DEFAULT_FONT;

        $heading = $saved['heading_font'] ?? null;
        $theme['heading_font'] = is_string($heading) && array_key_exists($heading, self::FONTS) ? $heading : $theme['body_font'];

        $theme['logo'] = is_int($saved['logo'] ?? null) ? $saved['logo'] : null;
        $theme['favicon'] = is_int($saved['favicon'] ?? null) ? $saved['favicon'] : null;

        return $theme;
    }

    public function isCustom(): bool
    {
        $values = $this->values();

        return $values['radius'] !== self::DEFAULT_RADIUS
            || $values['light'] !== self::DEFAULTS['light']
            || $values['dark'] !== self::DEFAULTS['dark']
            || $values['body_font'] !== self::DEFAULT_FONT
            || $values['heading_font'] !== self::DEFAULT_FONT;
    }

    /**
     * O CSS a injetar no <head>, ou '' com o tema de origem. Montado so a
     * partir de values(), que so deixa passar hex e chaves conhecidas.
     */
    public function css(): string
    {
        return $this->isCustom() ? self::render($this->values()) : '';
    }

    /**
     * O CSS de um tema qualquer (o gravado, ou o que o editor esta a
     * experimentar). So le chaves conhecidas e valores ja normalizados.
     *
     * @param  array{light: array<string, string>, dark: array<string, string>, radius: string, body_font: string, heading_font: string}  $theme
     */
    public static function render(array $theme): string
    {
        $radius = self::RADII[$theme['radius']] ?? self::RADII[self::DEFAULT_RADIUS];
        $body = (self::FONTS[$theme['body_font']] ?? self::FONTS[self::DEFAULT_FONT])[1];
        $heading = (self::FONTS[$theme['heading_font']] ?? self::FONTS[self::DEFAULT_FONT])[1];

        foreach (self::MODES as $mode) {
            foreach (self::COLORS as $color) {
                if (! self::isHex($theme[$mode][$color] ?? null)) {
                    $theme[$mode][$color] = self::DEFAULTS[$mode][$color];
                }
            }
        }

        // O <html> primeiro: e a cor que se ve antes de o CSS carregar e no
        // "elastico" do scroll do telemovel.
        return "html{background-color:{$theme['light']['background']}}"
            ."html.dark{background-color:{$theme['dark']['background']}}"
            .'.store-theme{'.self::declarations($theme['light'], 'light')."--radius:{$radius};font-family:{$body};}"
            .'.dark .store-theme,.dark.store-theme,.store-theme .dark{'.self::declarations($theme['dark'], 'dark').'}'
            .".store-theme :is(h1,h2,h3){font-family:{$heading};}";
    }

    /**
     * As familias que o @fonts do Blade carrega numa pagina da montra.
     *
     * @return array<int, string>
     */
    public function fontAliases(): array
    {
        $theme = $this->values();

        return array_values(array_unique([$theme['body_font'], $theme['heading_font']]));
    }

    /** O logotipo carregado no tema, ou null (fica o "12" e o nome). */
    public function logoUrl(): ?string
    {
        return $this->mediaUrl($this->values()['logo']);
    }

    public function faviconUrl(): ?string
    {
        return $this->mediaUrl($this->values()['favicon']);
    }

    /**
     * Para o editor do tema.
     *
     * @return array<string, mixed>
     */
    public function toForm(): array
    {
        $values = $this->values();

        return $values + [
            'logo_url' => $this->mediaUrl($values['logo']),
            'favicon_url' => $this->mediaUrl($values['favicon']),
        ];
    }

    /**
     * O payload ja validado do UpdateThemeRequest -> o que se grava.
     *
     * @param  array<string, mixed>  $form
     * @return array{light: array<string, string>, dark: array<string, string>, radius: string, body_font: string, heading_font: string, logo: int|null, favicon: int|null}
     */
    public function fromForm(array $form): array
    {
        $theme = [];

        foreach (self::MODES as $mode) {
            foreach (self::COLORS as $color) {
                $theme[$mode][$color] = strtolower((string) $form[$mode][$color]);
            }
        }

        return $theme + [
            'radius' => (string) $form['radius'],
            'body_font' => (string) $form['body_font'],
            'heading_font' => (string) $form['heading_font'],
            'logo' => isset($form['logo']) ? (int) $form['logo'] : null,
            'favicon' => isset($form['favicon']) ? (int) $form['favicon'] : null,
        ];
    }

    /**
     * As opcoes de letra para o editor.
     *
     * @return array<int, array{value: string, label: string, headingOnly: bool}>
     */
    public static function fontOptions(): array
    {
        return collect(self::FONTS)
            ->map(fn (array $font, string $alias): array => [
                'value' => $alias,
                'label' => $font[0],
                'headingOnly' => in_array($alias, self::HEADING_ONLY_FONTS, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $colors
     */
    private static function declarations(array $colors, string $mode): string
    {
        $bg = $colors['background'];
        $fg = $colors['foreground'];
        $primary = $colors['primary'];
        $accent = $colors['accent'];
        $gold = $colors['gold'];

        // No claro os cartoes sao quase brancos; no escuro, um degrau acima
        // do fundo — como no app.css.
        $card = $mode === 'light'
            ? "color-mix(in srgb,{$bg} 40%,#ffffff)"
            : "color-mix(in srgb,{$bg} 92%,{$fg})";

        $tokens = [
            'background' => $bg,
            'foreground' => $fg,
            'card' => $card,
            'card-foreground' => $fg,
            'popover' => $card,
            'popover-foreground' => $fg,
            'primary' => $primary,
            'primary-foreground' => $bg,
            'primary-hover' => "color-mix(in srgb,{$primary} 75%,{$bg})",
            'secondary' => $accent,
            'secondary-foreground' => $fg,
            'secondary-hover' => "color-mix(in srgb,{$accent} 85%,{$fg})",
            'muted' => $accent,
            'muted-foreground' => "color-mix(in srgb,{$fg} 70%,{$bg})",
            'accent' => $accent,
            'accent-foreground' => $fg,
            'border' => "color-mix(in srgb,{$fg} 15%,{$bg})",
            'input' => "color-mix(in srgb,{$fg} 15%,{$bg})",
            'ring' => "color-mix(in srgb,{$gold} 70%,{$fg})",
            'gold' => $gold,
            // Texto sobre o dourado: sempre escuro, seja qual for o modo.
            'gold-foreground' => "color-mix(in srgb,#000000 82%,{$gold})",
        ];

        return implode('', array_map(
            fn (string $token, string $value): string => "--{$token}:{$value};",
            array_keys($tokens),
            $tokens,
        ));
    }

    private function mediaUrl(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $path = Media::query()->whereKey($id)->value('path');

        return is_string($path) ? Storage::disk('public')->url($path) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(): array
    {
        $saved = $this->settings->get(self::KEY);

        return is_array($saved) ? $saved : [];
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
