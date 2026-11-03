<?php

namespace App\Services;

use App\PageBuilder\BlockSanitizer;
use Illuminate\Validation\ValidationException;

/**
 * Valida e limpa os menus que o editor grava. O header tem itens (com, no
 * maximo, um nivel de submenu); o rodape tem colunas, cada uma com os seus
 * links. Destinos so internos, https ou email — o mesmo criterio dos blocos.
 */
class MenuSanitizer
{
    public const LIMITS = [
        'header' => ['items' => 8, 'children' => 8],
        'footer' => ['items' => 4, 'children' => 10],
    ];

    public const TYPES = ['path', 'url', 'email'];

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param  array<mixed>  $items
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function clean(string $location, array $items): array
    {
        $this->errors = [];
        $limits = self::LIMITS[$location];

        if (! array_is_list($items) || count($items) > $limits['items']) {
            throw ValidationException::withMessages(['items' => "No máximo {$limits['items']} itens."]);
        }

        $clean = [];

        foreach ($items as $index => $item) {
            $path = "items.$index";
            $entry = $this->item(is_array($item) ? $item : [], $path, requireTarget: $location === 'header');

            $children = is_array($item['children'] ?? null) ? array_values($item['children']) : [];

            if (count($children) > $limits['children']) {
                $this->errors["$path.children"] = "No máximo {$limits['children']} links.";
                $children = array_slice($children, 0, $limits['children']);
            }

            $entry['children'] = array_map(
                fn (mixed $child, int $childIndex): array => $this->item(is_array($child) ? $child : [], "$path.children.$childIndex", requireTarget: true),
                $children,
                array_keys($children),
            );

            $clean[] = $entry;
        }

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function item(array $item, string $path, bool $requireTarget): array
    {
        $default = (string) config('locales.default');
        $labels = [];

        foreach (array_keys((array) config('locales.available')) as $locale) {
            $label = $item['label'][$locale] ?? '';
            $label = is_scalar($label) ? trim((string) $label) : '';

            if (mb_strlen($label) > 40) {
                $this->errors["$path.label.$locale"] = 'O rótulo não pode ter mais de 40 caracteres.';
            }

            if ($label !== '') {
                $labels[$locale] = $label;
            }
        }

        if (($labels[$default] ?? '') === '') {
            $this->errors["$path.label.$default"] = 'Falta o rótulo em português.';
        }

        $type = in_array($item['type'] ?? null, self::TYPES, true) ? $item['type'] : 'path';
        $target = is_scalar($item['target'] ?? null) ? trim((string) $item['target']) : '';

        $valid = match ($type) {
            'path' => preg_match('#^/(?![/\\\\])[^\s]*$#', $target) === 1,
            'url' => BlockSanitizer::isSafeHref($target) && str_starts_with($target, 'https://'),
            'email' => filter_var($target, FILTER_VALIDATE_EMAIL) !== false,
        };

        if (($requireTarget || $target !== '') && ! $valid) {
            $this->errors["$path.target"] = match ($type) {
                'path' => 'Escolhe uma página da loja (começa por /).',
                'url' => 'O endereço tem de começar por https://.',
                default => 'Email inválido.',
            };
        }

        return ['label' => $labels, 'type' => $type, 'target' => $target];
    }
}
