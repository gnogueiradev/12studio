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
 * ("blocks.2.children.0.content.pt.title"), para o editor os por ao lado dele.
 *
 * A pagina e uma arvore (v2): na raiz so contentores, e dentro deles
 * contentores ou elementos. Os blocos planos (v1) entram pelo
 * PageDocument::upgrade, por isso o que sai e sempre v2.
 */
class BlockSanitizer
{
    /** @var array<string, string> */
    private array $errors = [];

    /** Exigir os campos obrigatorios (ao publicar sim; no rascunho nao). */
    private bool $requireFields = true;

    /** @var array<string, true> ids ja dados na arvore */
    private array $ids = [];

    /** @var array<string, true> tipos ja vistos na arvore (unique e required) */
    private array $seen = [];

    /** Nos que ainda cabem na pagina (a pre-visualizacao corta aqui). */
    private int $budget = 0;

    public function __construct(
        private BlockRegistry $registry,
    ) {}

    /**
     * @param  array<mixed>  $nodes
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function clean(array $nodes, string $template, bool $requireFields = true): array
    {
        $this->requireFields = $requireFields;

        try {
            $clean = $this->run($nodes, $template, strict: true);
        } finally {
            $this->requireFields = true;
        }

        foreach ($this->registry->forTemplate($template) as $block) {
            if ($block->required() && ! isset($this->seen[$block->type()])) {
                $this->errors['blocks'] = "Falta o bloco \"{$block->label()}\": é obrigatório nesta página.";
            }
        }

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        return $clean;
    }

    /**
     * Para a pre-visualizacao do editor: limpa como o clean() (o rich text
     * passa pelo HTMLPurifier, os links pelo mesmo crivo), mas nao recusa
     * nada — um campo obrigatorio ainda vazio ou um texto comprido demais
     * nao impedem de ver a pagina enquanto se escreve. O que nao se consegue
     * mostrar (tipo desconhecido, fora do template, fundo demais, alem do
     * teto de nos) sai.
     *
     * @param  array<mixed>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public function preview(array $nodes, string $template): array
    {
        if (! array_is_list($nodes)) {
            return [];
        }

        $clean = $this->run($nodes, $template, strict: false);
        $this->errors = [];

        return $clean;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function run(array $nodes, string $template, bool $strict): array
    {
        $this->errors = [];
        $this->ids = [];
        $this->seen = [];
        $this->budget = Container::MAX_NODES;

        if (! array_is_list($nodes)) {
            throw ValidationException::withMessages(['blocks' => 'A lista de blocos é inválida.']);
        }

        // A raiz so tem contentores: um elemento solto (v1) ganha um a volta.
        // (um no com id ou tipo que nao e texto fica como veio: o node() recusa-o)
        $roots = array_map(fn (mixed $node): mixed => is_array($node) && $this->scalarKeys($node) ? PageDocument::upgrade([$node])[0] : $node, $nodes);

        if ($strict && PageDocument::count($roots) > Container::MAX_NODES) {
            throw ValidationException::withMessages(['blocks' => 'Uma página não pode ter mais de '.Container::MAX_NODES.' elementos e contentores.']);
        }

        $clean = [];

        foreach ($roots as $index => $raw) {
            $node = $this->node($raw, "blocks.$index", $template, 0, count($roots));

            if ($node !== null) {
                $clean[] = $node;
            }
        }

        return $clean;
    }

    /**
     * @param  int  $depth  contentores acima deste no
     * @param  int  $siblings  filhos do pai, este incluido
     * @return array<string, mixed>|null
     */
    private function node(mixed $raw, string $path, string $template, int $depth, int $siblings): ?array
    {
        if (! is_array($raw)) {
            $this->errors[$path] = 'Bloco inválido.';

            return null;
        }

        if (! $this->scalarKeys($raw)) {
            $this->errors[$path] = 'Bloco inválido.';

            return null;
        }

        if ($this->budget-- <= 0) {
            return null;
        }

        return ($raw['type'] ?? null) === 'container'
            ? $this->container($raw, $path, $template, $depth)
            : $this->element($raw, $path, $template, $depth, $siblings);
    }

    /**
     * O id e o tipo, quando vem, tem de ser texto ou numero: com um array o
     * PHP rebentava ao converter para string.
     *
     * @param  array<mixed>  $raw
     */
    private function scalarKeys(array $raw): bool
    {
        return is_scalar($raw['id'] ?? '') && is_scalar($raw['type'] ?? '');
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function container(array $raw, string $path, string $template, int $depth): ?array
    {
        if ($depth >= Container::MAX_DEPTH) {
            $this->errors[$path] = 'Não se pode meter mais de '.Container::MAX_DEPTH.' contentores uns dentro dos outros.';

            return null;
        }

        $id = $this->id($raw);
        $style = $this->props(Container::styleProps(), $raw['style'] ?? null, "$path.style");

        $children = $raw['children'] ?? [];

        if (! is_array($children) || ! array_is_list($children)) {
            $this->errors["$path.children"] = 'A lista de blocos é inválida.';
            $children = [];
        }

        if ($this->isRowOnAnyDevice($style['direction'] ?? []) && count($children) > Container::MAX_ROW_CHILDREN) {
            $this->errors[$path] = 'Um contentor em linha leva no máximo '.Container::MAX_ROW_CHILDREN.' elementos lado a lado.';
        }

        $clean = [];

        foreach ($children as $index => $child) {
            $node = $this->node($child, "$path.children.$index", $template, $depth + 1, count($children));

            if ($node !== null) {
                $clean[] = $node;
            }
        }

        return [
            'id' => $id,
            'type' => 'container',
            'v' => 2,
            'visible' => $this->visible($raw),
            'label' => $this->label($raw['label'] ?? null, $path),
            'settings' => [],
            'content' => [],
            'style' => $style,
            'advanced' => $this->props(Block::advancedProps(), $raw['advanced'] ?? null, "$path.advanced"),
            'children' => $clean,
        ];
    }

    /**
     * Em linha em algum dispositivo, depois de herdar computador -> tablet ->
     * telemovel (a direcao por omissao e coluna).
     *
     * @param  array<mixed>  $direction
     */
    private function isRowOnAnyDevice(array $direction): bool
    {
        $desktop = $direction['desktop'] ?? 'column';
        $tablet = $direction['tablet'] ?? $desktop;
        $mobile = $direction['mobile'] ?? $tablet;

        return in_array('row', [$desktop, $tablet, $mobile], true);
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function element(array $raw, string $path, string $template, int $depth, int $siblings): ?array
    {
        $block = $this->registry->get((string) ($raw['type'] ?? ''));

        if ($block === null) {
            $this->errors["$path.type"] = 'Tipo de bloco desconhecido.';

            return null;
        }

        if (! in_array($template, $block->templates(), true)) {
            $this->errors["$path.type"] = "O bloco \"{$block->label()}\" não pode entrar nesta página.";

            return null;
        }

        if ($block->unique() && isset($this->seen[$block->type()])) {
            $this->errors["$path.type"] = "Só pode haver um bloco \"{$block->label()}\" por página.";

            return null;
        }

        $this->seen[$block->type()] = true;

        // Os blocos de template ficam sozinhos num contentor de raiz.
        if ($block->rootOnly() && ($depth !== 1 || $siblings !== 1)) {
            $this->errors[$path] = "O bloco \"{$block->label()}\" só pode ficar sozinho numa secção da página.";
        }

        return $this->block($block, $raw, $path, $this->id($raw));
    }

    /**
     * O id que veio, se servir e ainda nao foi dado na arvore; senao um novo.
     *
     * @param  array<mixed>  $raw
     */
    private function id(array $raw): string
    {
        $id = is_string($raw['id'] ?? null) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $raw['id']) && ! isset($this->ids[$raw['id']])
            ? $raw['id']
            : (string) Str::uuid();

        $this->ids[$id] = true;

        return $id;
    }

    /**
     * @param  array<mixed>  $raw
     */
    private function visible(array $raw): bool
    {
        return filter_var($raw['visible'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** O nome dado no Navegador: texto simples, ate 60 caracteres. */
    private function label(mixed $value, string $path): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $label = trim(strip_tags($value));

        if ($label === '') {
            return null;
        }

        return mb_substr($label, 0, 60);
    }

    /**
     * Os valores por dispositivo de uma lista de StyleProp. So ficam as
     * propriedades com algum valor.
     *
     * @param  array<int, StyleProp>  $props
     * @return array<string, array<string, bool|int|string>>
     */
    private function props(array $props, mixed $raw, string $path): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($props as $prop) {
            $values = $prop->clean($raw[$prop->key] ?? null, "$path.{$prop->key}", $this->errors);

            if ($prop->type === 'media') {
                $values = $this->existingMedia($values, "$path.{$prop->key}");
            }

            if ($values !== []) {
                $clean[$prop->key] = $values;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, bool|int|string>  $values
     * @return array<string, bool|int|string>
     */
    private function existingMedia(array $values, string $path): array
    {
        if ($values === []) {
            return [];
        }

        $existing = Media::query()->whereKey(array_values($values))->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($values as $device => $id) {
            if (! in_array($id, $existing, true)) {
                $this->errors["$path.$device"] = 'A imagem escolhida já não existe.';
                unset($values[$device]);
            }
        }

        return $values;
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
            'label' => null,
            'variant' => $block->defaultVariant(),
            'style' => $block->defaultStyle(),
            'shell' => $block->hasShell(),
            'styleProps' => [],
            'advanced' => [],
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

        ['settings' => $settings, 'content' => $content] = $block->finalize(['settings' => $settings, 'content' => $content], $path, $this->errors);

        return [
            'id' => $id,
            'type' => $block->type(),
            'v' => 1,
            'visible' => $this->visible($raw),
            'label' => $this->label($raw['label'] ?? null, $path),
            'variant' => $variant,
            'style' => $this->style($block, (array) ($raw['style'] ?? [])),
            // A casca de seccao: so nos blocos que a tem; por omissao ligada.
            'shell' => $block->hasShell() && (is_bool($raw['shell'] ?? null) ? $raw['shell'] : true),
            'styleProps' => $this->props($block->styleFields(), $raw['styleProps'] ?? null, "$path.styleProps"),
            'advanced' => $this->props($block->advancedFields(), $raw['advanced'] ?? null, "$path.advanced"),
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

        if ($enforceRequired && $this->requireFields && $field->required && $this->isEmpty($clean) && ! isset($this->errors[$path])) {
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
