<?php

namespace App\PageBuilder;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transforma os blocos gravados no que o React recebe: so os visiveis, no
 * idioma da pagina (campo a campo, com o idioma por omissao quando falta a
 * traducao), com imagens, links e listas de produtos ja resolvidos.
 *
 * Um bloco que nao se consegue mostrar — tipo que deixou de existir, uma
 * falha a resolver — sai da pagina e fica no log. A pagina nunca rebenta
 * por causa de um bloco.
 */
class PageRenderer
{
    public function __construct(
        private BlockRegistry $registry,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array{id: string, type: string, variant: string|null, style: array<string, string>, data: array<string, mixed>}>
     */
    public function render(array $blocks, RenderContext $context): array
    {
        $visible = array_values(array_filter($blocks, fn (mixed $block): bool => is_array($block) && ($block['visible'] ?? true) !== false));

        $context->preloadMedia($this->imageIds($visible, $context));

        $rendered = [];

        foreach ($visible as $block) {
            $definition = $this->registry->get((string) ($block['type'] ?? ''));

            if ($definition === null) {
                Log::warning('Bloco de tipo desconhecido ignorado na montra.', ['type' => $block['type'] ?? null]);

                continue;
            }

            try {
                $data = $definition->resolve($this->data($definition, $block, $context), $context);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            $rendered[] = [
                'id' => (string) ($block['id'] ?? ''),
                'type' => $definition->type(),
                'variant' => is_string($block['variant'] ?? null) && array_key_exists($block['variant'], $definition->variants())
                    ? $block['variant']
                    : $definition->defaultVariant(),
                'style' => array_merge($definition->defaultStyle(), array_intersect_key((array) ($block['style'] ?? []), $definition->defaultStyle())),
                'data' => $data,
            ];
        }

        return $rendered;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function data(Block $definition, array $block, RenderContext $context): array
    {
        $data = [];

        foreach ($definition->fields() as $field) {
            $data[$field->key] = $this->resolve($field, $this->raw($field, $block, $context), $context);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function raw(Field $field, array $block, RenderContext $context): mixed
    {
        if (! $field->translatable) {
            return $block['settings'][$field->key] ?? null;
        }

        $value = $block['content'][$context->locale][$field->key] ?? null;

        if ($value === null || $value === '' || $value === []) {
            $value = $block['content'][$context->defaultLocale][$field->key] ?? null;
        }

        return $value;
    }

    private function resolve(Field $field, mixed $value, RenderContext $context): mixed
    {
        return match ($field->type) {
            'text', 'textarea', 'richtext' => is_string($value) ? $value : '',
            'image' => $context->image($value),
            'link' => $this->link($value, $context),
            'select' => is_scalar($value) && array_key_exists((string) $value, $field->options) ? (string) $value : $field->default,
            'toggle' => is_bool($value) ? $value : (bool) $field->default,
            'number' => is_numeric($value) ? (int) $value : (int) $field->default,
            'products', 'categories', 'tags' => is_array($value) ? array_values(array_map('intval', array_filter($value, 'is_numeric'))) : [],
            'icon' => is_string($value) && in_array($value, Field::ICONS, true) ? $value : $field->default,
            'repeater' => $this->repeater($field, $value, $context),
            default => null,
        };
    }

    /**
     * @return array{label: string, href: string, external: bool}|null
     */
    private function link(mixed $value, RenderContext $context): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $label = is_string($value['label'] ?? null) ? $value['label'] : '';
        $href = is_string($value['href'] ?? null) ? $value['href'] : '';

        if ($label === '' || ! BlockSanitizer::isSafeHref($href)) {
            return null;
        }

        return [
            'label' => $label,
            'href' => $context->url($href),
            'external' => str_starts_with($href, 'https://'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function repeater(Field $field, mixed $value, RenderContext $context): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $resolved = [];
            foreach ($field->fields as $sub) {
                $resolved[$sub->key] = $this->resolve($sub, $item[$sub->key] ?? null, $context);
            }

            $items[] = $resolved;
        }

        return $items;
    }

    /**
     * Os ids de todas as imagens da pagina, para uma so query.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, int>
     */
    private function imageIds(array $blocks, RenderContext $context): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            $definition = $this->registry->get((string) ($block['type'] ?? ''));

            if ($definition === null) {
                continue;
            }

            foreach ($definition->fields() as $field) {
                $this->collectImages($field, $this->raw($field, $block, $context), $ids);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function collectImages(Field $field, mixed $value, array &$ids): void
    {
        if ($field->type === 'image' && is_int($value)) {
            $ids[] = $value;
        }

        if ($field->type === 'repeater' && is_array($value)) {
            foreach ($value as $item) {
                foreach ($field->fields as $sub) {
                    $this->collectImages($sub, is_array($item) ? ($item[$sub->key] ?? null) : null, $ids);
                }
            }
        }
    }
}
