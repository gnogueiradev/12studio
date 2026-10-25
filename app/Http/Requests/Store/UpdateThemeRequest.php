<?php

namespace App\Http\Requests\Store;

use App\Models\Media;
use App\Services\ThemeSettings;
use App\Support\Contrast;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O tema da montra. So o dono: mexe na marca toda.
 *
 * O contraste confere-se aqui e nao so no ecra: texto e fundo, e o botao
 * principal e o fundo, tem de chegar a 3:1 nos dois modos (o minimo da WCAG
 * para titulos e botoes). Abaixo disso a montra deixava de se ler.
 */
class UpdateThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'radius' => ['required', Rule::in(array_keys(ThemeSettings::RADII))],
            'body_font' => ['required', Rule::in(array_diff(array_keys(ThemeSettings::FONTS), ThemeSettings::HEADING_ONLY_FONTS))],
            'heading_font' => ['required', Rule::in(array_keys(ThemeSettings::FONTS))],
            'logo' => ['nullable', 'integer', Rule::exists(Media::class, 'id')],
            'favicon' => ['nullable', 'integer', Rule::exists(Media::class, 'id')],
        ];

        foreach (ThemeSettings::MODES as $mode) {
            foreach (ThemeSettings::COLORS as $color) {
                $rules["$mode.$color"] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
            }
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (ThemeSettings::MODES as $mode) {
                $colors = (array) $this->input($mode);
                $label = $mode === 'light' ? 'modo claro' : 'modo escuro';

                if (Contrast::ratio($colors['foreground'], $colors['background']) < Contrast::AA_LARGE) {
                    $validator->errors()->add("$mode.foreground", "O texto e o fundo do {$label} quase não se distinguem (contraste abaixo de 3:1).");
                }

                if (Contrast::ratio($colors['primary'], $colors['background']) < Contrast::AA_LARGE) {
                    $validator->errors()->add("$mode.primary", "O botão principal e o fundo do {$label} quase não se distinguem (contraste abaixo de 3:1).");
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = ['radius' => 'cantos', 'body_font' => 'letra do texto', 'heading_font' => 'letra dos títulos'];

        foreach (ThemeSettings::MODES as $mode) {
            foreach (ThemeSettings::COLORS as $color) {
                $attributes["$mode.$color"] = 'cor';
            }
        }

        return $attributes;
    }
}
