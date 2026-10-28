<?php

namespace App\PageBuilder;

/**
 * Um campo de um bloco. A mesma definicao serve tres coisas:
 *
 *  - o editor gera o formulario do inspetor a partir dela (toArray());
 *  - o BlockSanitizer valida e limpa o que chega do editor;
 *  - o PageRenderer sabe onde ler o valor (content[idioma] ou settings) e
 *    como o resolver (uma imagem, um link, uma lista de produtos).
 *
 * Campos traduziveis vivem em content.{idioma}; os outros em settings.
 */
final class Field
{
    public const TYPES = [
        'text', 'textarea', 'richtext', 'image', 'link', 'select', 'toggle',
        'number', 'products', 'categories', 'tags', 'icon', 'repeater',
    ];

    /**
     * Os icones que os blocos podem usar (nomes do lucide). Uma lista curta
     * de proposito: cada um tem de existir no mapa do frontend
     * (resources/js/blocks/icons.tsx).
     */
    public const ICONS = [
        'sparkles', 'printer', 'package', 'truck', 'heart', 'palette', 'ruler',
        'message-circle', 'shield-check', 'leaf', 'clock', 'gift', 'star',
        'wrench', 'box', 'map-pin', 'pencil', 'recycle',
    ];

    public bool $translatable = false;

    public bool $required = false;

    public ?int $max = null;

    public ?int $min = null;

    /** @var array<int|string, string> */
    public array $options = [];

    /** @var array<int, Field> */
    public array $fields = [];

    public mixed $default = null;

    public ?string $help = null;

    private function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
    ) {}

    public static function text(string $key, string $label): self
    {
        return (new self($key, 'text', $label))->max(160);
    }

    public static function textarea(string $key, string $label): self
    {
        return (new self($key, 'textarea', $label))->max(1000);
    }

    public static function richtext(string $key, string $label): self
    {
        return (new self($key, 'richtext', $label))->max(20000);
    }

    public static function image(string $key, string $label): self
    {
        return new self($key, 'image', $label);
    }

    /** Rotulo e destino; traduzivel (o rotulo muda de lingua, o destino pode). */
    public static function link(string $key, string $label): self
    {
        return (new self($key, 'link', $label))->translatable();
    }

    /**
     * @param  array<int|string, string>  $options
     */
    public static function select(string $key, string $label, array $options): self
    {
        $field = new self($key, 'select', $label);
        $field->options = $options;
        // Em string: o PHP faz das chaves "4" inteiros, e o valor de um
        // select viaja sempre como texto.
        $field->default = (string) array_key_first($options);

        return $field;
    }

    public static function toggle(string $key, string $label, bool $default = false): self
    {
        $field = new self($key, 'toggle', $label);
        $field->default = $default;

        return $field;
    }

    public static function number(string $key, string $label, int $min, int $max, int $default): self
    {
        $field = (new self($key, 'number', $label))->min($min)->max($max);
        $field->default = $default;

        return $field;
    }

    public static function products(string $key, string $label, int $max = 24): self
    {
        return (new self($key, 'products', $label))->max($max);
    }

    public static function categories(string $key, string $label, int $max = 12): self
    {
        return (new self($key, 'categories', $label))->max($max);
    }

    public static function tags(string $key, string $label, int $max = 1): self
    {
        return (new self($key, 'tags', $label))->max($max);
    }

    public static function icon(string $key, string $label): self
    {
        $field = new self($key, 'icon', $label);
        $field->default = self::ICONS[0];

        return $field;
    }

    /**
     * Uma lista de itens com os mesmos campos (passos, perguntas, fotos).
     * Os sub-campos herdam o "traduzivel" da lista: e a lista inteira que
     * vive num idioma ou em todos.
     *
     * @param  array<int, Field>  $fields
     */
    public static function repeater(string $key, string $label, array $fields, int $max = 12): self
    {
        $field = (new self($key, 'repeater', $label))->max($max);
        $field->fields = $fields;

        return $field;
    }

    public function translatable(bool $translatable = true): self
    {
        $this->translatable = $translatable;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function max(int $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function min(int $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * O esquema para o editor.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'translatable' => $this->translatable,
            'required' => $this->required,
            'max' => $this->max,
            'min' => $this->min,
            'options' => $this->options === [] ? null : collect($this->options)
                ->map(fn (string $label, int|string $value): array => ['value' => (string) $value, 'label' => $label])
                ->values()
                ->all(),
            'fields' => $this->fields === [] ? null : array_map(fn (Field $field): array => $field->toArray(), $this->fields),
            'default' => $this->default,
            'help' => $this->help,
            'icons' => $this->type === 'icon' ? self::ICONS : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
