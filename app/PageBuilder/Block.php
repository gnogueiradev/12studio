<?php

namespace App\PageBuilder;

/**
 * Um tipo de bloco da montra. Uma classe por tipo em app/PageBuilder/Blocks,
 * registada no BlockRegistry, e um componente React com o mesmo `type` em
 * resources/js/blocks. O editor nao precisa de mudar para haver um bloco novo.
 */
abstract class Block
{
    /** Fundos possiveis — so tokens do tema, nunca cores livres. */
    public const BACKGROUNDS = [
        'default' => 'Normal',
        'muted' => 'Suave',
        'accent' => 'Acento',
        'dark' => 'Escuro',
        'gold' => 'Dourado',
    ];

    public const SPACINGS = ['sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande'];

    public const WIDTHS = ['contained' => 'Contido', 'full' => 'Ecrã inteiro'];

    public const ALIGNS = ['start' => 'À esquerda', 'center' => 'Ao centro'];

    /** Templates onde um bloco generico pode entrar. */
    public const ALL_TEMPLATES = ['page', 'catalog', 'product'];

    abstract public function type(): string;

    /** Nome no catalogo de blocos do editor (em portugues: o backoffice e PT). */
    abstract public function label(): string;

    /**
     * @return array<int, Field>
     */
    abstract public function fields(): array;

    public function description(): string
    {
        return '';
    }

    /**
     * Variantes de desenho do bloco, a primeira e a de omissao.
     *
     * @return array<string, string>
     */
    public function variants(): array
    {
        return [];
    }

    /**
     * Em que templates o bloco pode entrar.
     *
     * @return array<int, string>
     */
    public function templates(): array
    {
        return self::ALL_TEMPLATES;
    }

    /** Obrigatorio nos seus templates: move-se, mas nao se apaga. */
    public function required(): bool
    {
        return false;
    }

    /** No maximo um por pagina. */
    public function unique(): bool
    {
        return false;
    }

    /**
     * O estilo com que o bloco nasce no editor.
     *
     * @return array{bg: string, spacing: string, width: string, align: string}
     */
    public function defaultStyle(): array
    {
        return ['bg' => 'default', 'spacing' => 'md', 'width' => 'contained', 'align' => 'start'];
    }

    /**
     * Os dados que o componente React recebe. Por omissao, os campos ja
     * resolvidos pelo PageRenderer (textos no idioma, imagens, links);
     * blocos que vao a BD (grelha de produtos, categorias) acrescentam aqui.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, RenderContext $context): array
    {
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'description' => $this->description(),
            'variants' => collect($this->variants())
                ->map(fn (string $label, int|string $value): array => ['value' => (string) $value, 'label' => $label])
                ->values()
                ->all(),
            'templates' => $this->templates(),
            'required' => $this->required(),
            'unique' => $this->unique(),
            'defaultStyle' => $this->defaultStyle(),
            'fields' => array_map(fn (Field $field): array => $field->toArray(), $this->fields()),
        ];
    }

    public function defaultVariant(): ?string
    {
        return array_key_first($this->variants());
    }
}
