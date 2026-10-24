<?php

namespace App\PageBuilder;

use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mews\Purifier\Facades\Purifier;

/**
 * Valida e limpa os blocos de uma pagina antes de os gravar — os que vem do
 * editor e os de origem (DefaultPages).
 *
 * O que sai daqui e o que a montra renderiza, por isso nada passa sem ser
 * conhecido: tipos e campos do registo, variantes e estilos da lista, rich
 * text pelo HTMLPurifier (perfil `page`), links so internos, https, mailto,
 * tel ou ancoras. Os erros vem com o caminho do campo
 * ("blocks.2.content.pt.title"), para o editor os por ao lado dele.
 */
class BlockSanitizer
{
    /** Teto de blocos numa pagina. */
    public const MAX_BLOCKS = 60;

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(
        private BlockRegistry $registry,
    ) {}

    /**
     * @param  array<mixed>  $blocks
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function clean(array $blocks, string $template): array
    {
        $this->errors = [];

        if (! array_is_list($blocks)) {
            throw ValidationException::withMessages(['blocks' => 'A lista de blocos é inválida.']);
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['blocks' => 'Uma página não pode ter mais de '.self::MAX_BLOCKS.' blocos.']);
        }

        $clean = [];
        $seen = [];
        $ids = [];

        foreach ($blocks as $index => $raw) {
            $path = "blocks.$index";

            if (! is_array($raw)) {
                $this->errors[$path] = 'Bloco inválido.';

                continue;
            }

            $block = $this->registry->get((string) ($raw['type'] ?? ''));

            if ($block === null) {
                $this->errors["$path.type"] = 'Tipo de bloco desconhecido.';

                continue;
            }

            if (! in_array($template, $block->templates(), true)) {
                $this->errors["$path.type"] = "O bloco \"{$block->label()}\" não pode entrar nesta página.";

                continue;
            }

            if ($block->unique() && isset($seen[$block->type()])) {
                $this->errors["$path.type"] = "Só pode haver um bloco \"{$block->label()}\" por página.";

                continue;
            }

            $seen[$block->type()] = true;

            $id = is_string($raw['id'] ?? null) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $raw['id']) && ! isset($ids[$raw['id']])
                ? $raw['id']
                : (string) Str::uuid();
            $ids[$id] = true;

            $clean[] = $this->block($block, $raw, $path, $id);
        }

        foreach ($this->registry->forTemplate($template) as $block) {
            if ($block->required() && ! isset($seen[$block->type()])) {
                $this->errors['blocks'] = "Falta o bloco \"{$block->label()}\": é obrigatório nesta página.";
            }
        }

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        return $clean;
    }

    /**
     * Um bloco novo, vazio, como o editor o insere.
     *
     * @return array<string, mixed>
     */
    public function blank(Block $block): array
    {
        $locales = array_keys((array) config('locales.available'));

        $settings = [];
        $content = array_fill_keys($locales, []);

        foreach ($block->fields() as $field) {
            $empty = $this->emptyValue($field);

            if ($field->translatable) {
                $content[(string) config('locales.default')][$field->key] = $empty;
            } else {
                $settings[$field->key] = $empty;
            }
        }

        return [
            'id' => (string) Str::uuid(),
            'type' => $block->type(),
            'v' => 1,
            'visible' => true,
            'variant' => $block->defaultVariant(),
            'style' => $block->defaultStyle(),
            'settings' => $settings,
            'content' => $content,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function block(Block $block, array $raw, string $path, string $id): array
    {
        $locales = array_keys((array) config('locales.available'));
        $default = (string) config('locales.default');

        $variants = $block->variants();
        $variant = is_string($raw['variant'] ?? null) && array_key_exists($raw['variant'], $variants)
            ? $raw['variant']
            : $block->defaultVariant();

        $settings = [];
        $content = array_fill_keys($locales, []);

        foreach ($block->fields() as $field) {
            if (! $field->translatable) {
                $settings[$field->key] = $this->value($field, $raw['settings'][$field->key] ?? null, "$path.settings.{$field->key}", true);

                continue;
            }

            foreach ($locales as $locale) {
                $value = $this->value(
                    $field,
                    $raw['content'][$locale][$field->key] ?? null,
                    "$path.content.$locale.{$field->key}",
                    $locale === $default,
                );

                // Nos outros idiomas so fica o que foi preenchido: o que falta
                // cai no idioma por omissao, campo a campo.
                if ($locale === $default || ! $this->isEmpty($value)) {
                    $content[$locale][$field->key] = $value;
                }
            }
        }

        return [
            'id' => $id,
            'type' => $block->type(),
            'v' => 1,
            'visible' => filter_var($raw['visible'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            'variant' => $variant,
            'style' => $this->style($block, (array) ($raw['style'] ?? [])),
            'settings' => $settings,
            'content' => $content,
        ];
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array{bg: string, spacing: string, width: string, align: string}
     */
    private function style(Block $block, array $style): array
    {
        $defaults = $block->defaultStyle();
        $pick = fn (string $key, array $allowed): string => is_string($style[$key] ?? null) && array_key_exists($style[$key], $allowed)
            ? $style[$key]
            : $defaults[$key];

        return [
            'bg' => $pick('bg', Block::BACKGROUNDS),
            'spacing' => $pick('spacing', Block::SPACINGS),
            'width' => $pick('width', Block::WIDTHS),
            'align' => $pick('align', Block::ALIGNS),
        ];
    }

    private function value(Field $field, mixed $value, string $path, bool $enforceRequired): mixed
    {
        $clean = match ($field->type) {
            'text', 'textarea' => $this->text($field, $value, $path),
            'richtext' => $this->richtext($field, $value, $path),
            'image' => $this->image($value, $path),
            'link' => $this->link($value, $path),
            'select' => is_scalar($value) && array_key_exists((string) $value, $field->options) ? (string) $value : $field->default,
            'toggle' => $value === null ? (bool) $field->default : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $field->default),
            'number' => $this->number($field, $value, $path),
            'products' => $this->ids($field, $value, $path, Product::class),
            'categories' => $this->ids($field, $value, $path, Category::class),
            'tags' => $this->ids($field, $value, $path, Tag::class),
            'icon' => is_string($value) && in_array($value, Field::ICONS, true) ? $value : $field->default,
            'repeater' => $this->repeater($field, $value, $path, $enforceRequired),
            default => null,
        };

        if ($enforceRequired && $field->required && $this->isEmpty($clean) && ! isset($this->errors[$path])) {
            $this->errors[$path] = "O campo \"{$field->label}\" é obrigatório.";
        }

        return $clean;
    }

    private function text(Field $field, mixed $value, string $path): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_scalar($value)) {
            $this->errors[$path] = "O campo \"{$field->label}\" tem de ser texto.";

            return '';
        }

        $text = trim((string) $value);

        if ($field->max !== null && mb_strlen($text) > $field->max) {
            $this->errors[$path] = "O campo \"{$field->label}\" não pode ter mais de {$field->max} caracteres.";
        }

        return $text;
    }

    private function richtext(Field $field, mixed $value, string $path): string
    {
        $html = $this->text($field, $value, $path);

        if ($html === '') {
            return '';
        }

        $clean = trim((string) Purifier::clean($html, 'page'));

        // Um editor vazio deixa "<p></p>" ou "<p><br></p>": isso nao e texto.
        return trim(strip_tags($clean)) === '' ? '' : $clean;
    }

    private function image(mixed $value, string $path): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || ! Media::query()->whereKey((int) $value)->exists()) {
            $this->errors[$path] = 'A imagem escolhida já não existe.';

            return null;
        }

        return (int) $value;
    }

    /**
     * @return array{label: string, href: string}|null
     */
    private function link(mixed $value, string $path): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $label = is_scalar($value['label'] ?? null) ? trim((string) $value['label']) : '';
        $href = is_scalar($value['href'] ?? null) ? trim((string) $value['href']) : '';

        if ($label === '' && $href === '') {
            return null;
        }

        if ($label === '') {
            $this->errors["$path.label"] = 'O botão precisa de um texto.';
        } elseif (mb_strlen($label) > 60) {
            $this->errors["$path.label"] = 'O texto do botão não pode ter mais de 60 caracteres.';
        }

        if (! self::isSafeHref($href)) {
            $this->errors["$path.href"] = 'Destino inválido: usa um caminho da loja (/produtos), um endereço https://, mailto: ou tel:.';
        }

        return ['label' => $label, 'href' => $href];
    }

    /**
     * Destinos que um link de bloco pode ter. Os internos nao podem comecar
     * por "//" nem "/\": o browser le-os como outro dominio.
     */
    public static function isSafeHref(string $href): bool
    {
        return preg_match('#^/(?![/\\\\])[^\s]*$#', $href) === 1
            || preg_match('#^https://[^\s/]+[^\s]*$#i', $href) === 1
            || preg_match('#^mailto:[^\s@]+@[^\s@]+$#i', $href) === 1
            || preg_match('#^tel:\+?[0-9 ()-]{6,}$#', $href) === 1
            || preg_match('#^\#[A-Za-z0-9_-]+$#', $href) === 1;
    }

    private function number(Field $field, mixed $value, string $path): int
    {
        if ($value === null || $value === '') {
            return (int) $field->default;
        }

        if (! is_numeric($value)) {
            $this->errors[$path] = "O campo \"{$field->label}\" tem de ser um número.";

            return (int) $field->default;
        }

        $number = (int) $value;

        if (($field->min !== null && $number < $field->min) || ($field->max !== null && $number > $field->max)) {
            $this->errors[$path] = "O campo \"{$field->label}\" tem de estar entre {$field->min} e {$field->max}.";
        }

        return $number;
    }

    /**
     * Ids de registos que existem, sem repetidos, pela ordem em que vieram.
     * Os que entretanto desapareceram saem em silencio.
     *
     * @param  class-string<Model>  $model
     * @return array<int, int>
     */
    private function ids(Field $field, mixed $value, string $path, string $model): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            $this->errors[$path] = 'Lista inválida.';

            return [];
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($value, 'is_numeric'))));

        if ($field->max !== null && count($ids) > $field->max) {
            $this->errors[$path] = "No máximo {$field->max}.";
        }

        if ($ids === []) {
            return [];
        }

        $existing = $model::query()->whereKey($ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $existing, true)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function repeater(Field $field, mixed $value, string $path, bool $enforceRequired): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $this->errors[$path] = 'Lista inválida.';

            return [];
        }

        if ($field->max !== null && count($value) > $field->max) {
            $this->errors[$path] = "\"{$field->label}\": no máximo {$field->max}.";
            $value = array_slice($value, 0, $field->max);
        }

        $items = [];

        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                $this->errors["$path.$index"] = 'Item inválido.';

                continue;
            }

            $clean = [];
            foreach ($field->fields as $sub) {
                $clean[$sub->key] = $this->value($sub, $item[$sub->key] ?? null, "$path.$index.{$sub->key}", $enforceRequired);
            }

            $items[] = $clean;
        }

        return $items;
    }

    private function emptyValue(Field $field): mixed
    {
        return match ($field->type) {
            'text', 'textarea', 'richtext' => '',
            'image', 'link' => null,
            'products', 'categories', 'tags', 'repeater' => [],
            default => $field->default,
        };
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
