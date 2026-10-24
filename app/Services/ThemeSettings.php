<?php

namespace App\Services;

/**
 * O tema da montra: cinco cores de base por modo (claro e escuro) e o
 * arredondamento dos cantos. Fica na tabela `settings`, e nao no app.css,
 * porque cada loja tem a sua marca e o mesmo build serve-as a todas.
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

    public function __construct(
        private SettingService $settings,
    ) {}

    /**
     * O tema em vigor, completo: o gravado por cima dos valores de origem.
     * Uma cor gravada que nao seja um hex valido e ignorada — este valor vai
     * parar a um <style>, e nada que nao seja #rrggbb pode la chegar.
     *
     * @return array{light: array<string, string>, dark: array<string, string>, radius: string}
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

        return $theme;
    }

    public function isCustom(): bool
    {
        $values = $this->values();

        return $values['radius'] !== self::DEFAULT_RADIUS
            || $values['light'] !== self::DEFAULTS['light']
            || $values['dark'] !== self::DEFAULTS['dark'];
    }

    /**
     * O CSS a injetar no <head>, ou '' com o tema de origem. Montado so a
     * partir de values(), que so deixa passar hex e chaves conhecidas.
     */
    public function css(): string
    {
        if (! $this->isCustom()) {
            return '';
        }

        $theme = $this->values();
        $radius = self::RADII[$theme['radius']];

        // O <html> primeiro: e a cor que se ve antes de o CSS carregar e no
        // "elastico" do scroll do telemovel.
        return "html{background-color:{$theme['light']['background']}}"
            ."html.dark{background-color:{$theme['dark']['background']}}"
            .'.store-theme{'.$this->declarations($theme['light'], 'light')."--radius:{$radius};}"
            .'.dark .store-theme,.dark.store-theme{'.$this->declarations($theme['dark'], 'dark').'}';
    }

    /**
     * @param  array<string, string>  $colors
     */
    private function declarations(array $colors, string $mode): string
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
        ];

        return implode('', array_map(
            fn (string $token, string $value): string => "--{$token}:{$value};",
            array_keys($tokens),
            $tokens,
        ));
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
