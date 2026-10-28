<?php

namespace App\PageBuilder;

/**
 * Uma propriedade de estilo de um contentor ou elemento. Cada uma guarda um
 * valor por dispositivo ({desktop, tablet, mobile}); o que falta herda do
 * tamanho acima. Tal como o Field, a mesma definicao serve o editor
 * (toArray()) e a validacao (clean()).
 */
final class StyleProp
{
    public const DEVICES = ['desktop', 'tablet', 'mobile'];

    public const TYPES = ['enum', 'int', 'token', 'toggle', 'media'];

    public ?int $min = null;

    public ?int $max = null;

    public string $unit = 'px';

    public ?string $default = null;

    public ?int $defaultInt = null;

    /** @var array<string, string> */
    public array $options = [];

    private function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
    ) {}

    /**
     * @param  array<int|string, string>  $options  valor => rotulo
     */
    public static function enum(string $key, string $label, array $options, ?string $default = null): self
    {
        $prop = new self($key, 'enum', $label);
        $prop->options = self::strKeys($options);
        $prop->default = $default;

        return $prop;
    }

    public static function int(string $key, string $label, int $min, int $max, string $unit = 'px', ?int $default = null): self
    {
        $prop = new self($key, 'int', $label);
        $prop->min = $min;
        $prop->max = $max;
        $prop->unit = $unit;
        $prop->defaultInt = $default;

        return $prop;
    }

    /**
     * Um token do tema (fundo, cor): nunca uma cor livre.
     *
     * @param  array<int|string, string>  $tokens  token => rotulo
     */
    public static function token(string $key, string $label, array $tokens): self
    {
        $prop = new self($key, 'token', $label);
        $prop->options = self::strKeys($tokens);

        return $prop;
    }

    public static function toggle(string $key, string $label): self
    {
        return new self($key, 'toggle', $label);
    }

    /** Um id de media; se existe ou nao verifica-se a jusante. */
    public static function media(string $key, string $label): self
    {
        return new self($key, 'media', $label);
    }

    /**
     * Valida o valor por dispositivo. Larga dispositivos desconhecidos e
     * valores invalidos (erro em "$path.$device") e devolve so os
     * dispositivos com valor.
     *
     * Inteiros: aceita int, float inteiro (12.0) e texto numerico inteiro
     * ("12"), porque o JSON do editor pode trazer qualquer um. Toggle: aceita
     * bool, 0/1 e 'true'/'false'/'0'/'1', e devolve sempre bool.
     *
     * @param  array<string, string>  $errors
     * @return array<string, bool|int|string>
     */
    public function clean(mixed $byDevice, string $path, array &$errors): array
    {
        if (! is_array($byDevice)) {
            return [];
        }

        $clean = [];

        foreach (self::DEVICES as $device) {
            if (! array_key_exists($device, $byDevice) || $byDevice[$device] === null) {
                continue;
            }

            $value = $this->value($byDevice[$device]);

            if ($value === null) {
                $errors["$path.$device"] = "O valor de «{$this->label}» nao e valido.";

                continue;
            }

            $clean[$device] = $value;
        }

        return $clean;
    }

    /** O valor normalizado, ou null se nao serve. */
    private function value(mixed $raw): bool|int|string|null
    {
        switch ($this->type) {
            case 'int':
            case 'media':
                $int = $this->toInt($raw);

                if ($int === null) {
                    return null;
                }

                if ($this->type === 'media') {
                    return $int > 0 ? $int : null;
                }

                return $int >= $this->min && $int <= $this->max ? $int : null;

            case 'enum':
            case 'token':
                // Em texto: o PHP faz das chaves "4" inteiros.
                if (! is_string($raw) && ! is_int($raw)) {
                    return null;
                }

                return array_key_exists((string) $raw, $this->options) ? (string) $raw : null;

            case 'toggle':
                return match (true) {
                    is_bool($raw) => $raw,
                    $raw === 1, $raw === '1', $raw === 'true' => true,
                    $raw === 0, $raw === '0', $raw === 'false' => false,
                    default => null,
                };
        }

        return null;
    }

    private function toInt(mixed $raw): ?int
    {
        if (is_string($raw)) {
            $raw = trim($raw);

            if (! is_numeric($raw)) {
                return null;
            }

            $raw += 0;
        }

        if (is_float($raw)) {
            return is_finite($raw) && floor($raw) === $raw && abs($raw) < PHP_INT_MAX ? (int) $raw : null;
        }

        return is_int($raw) ? $raw : null;
    }

    /**
     * @param  array<int|string, string>  $options
     * @return array<string, string>
     */
    private static function strKeys(array $options): array
    {
        $out = [];

        foreach ($options as $value => $label) {
            $out[(string) $value] = $label;
        }

        return $out;
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
            'min' => $this->min,
            'max' => $this->max,
            'unit' => $this->type === 'int' ? $this->unit : null,
            'default' => $this->type === 'int' ? $this->defaultInt : $this->default,
            'options' => $this->options === [] ? null : collect($this->options)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ], fn (mixed $value): bool => $value !== null);
    }
}
