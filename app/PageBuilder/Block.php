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
     * As propriedades de estilo proprias do elemento (alem das avancadas).
     *
     * @return array<int, StyleProp>
     */
    public function styleFields(): array
    {
        return [];
    }

    /**
     * Com casca: o fundo, espaco, largura e alinhamento classicos do bloco.
     * Os elementos basicos novos vivem dentro de contentores e dispensam-na.
     */
    public function hasShell(): bool
    {
        return true;
    }

    /** So na raiz, sozinho num contentor (os blocos de template). */
    public function rootOnly(): bool
    {
        return false;
    }

    /**
     * Ultimo passo da limpeza de um elemento: um bloco pode validar ou
     * transformar os dados ja limpos (ex.: o video guarda o endereco de
     * embed). Por omissao nao mexe em nada.
     *
     * @param  array{settings: array<string, mixed>, content: array<string, array<string, mixed>>}  $clean
     * @param  array<string, string>  $errors  erros por caminho do campo
     * @return array{settings: array<string, mixed>, content: array<string, array<string, mixed>>}
     */
    public function finalize(array $clean, string $path, array &$errors): array
    {
        return $clean;
    }

    /**
     * Iguais para todos os nos.
     *
     * @return array<int, StyleProp>
     */
    public function advancedFields(): array
    {
        return self::advancedProps();
    }

    /**
     * O separador Avancado, partilhado por elementos e contentores.
     *
     * @return array<int, StyleProp>
     */
    public static function advancedProps(): array
    {
        return [
            StyleProp::int('margin_top', 'Margem superior', 0, 200),
            StyleProp::int('margin_bottom', 'Margem inferior', 0, 200),
            StyleProp::int('padding_y', 'Espaço vertical interior', 0, 200),
            StyleProp::int('padding_x', 'Espaço horizontal interior', 0, 120),
            StyleProp::toggle('hide', 'Esconder'),
        ];
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
            'styleFields' => array_map(fn (StyleProp $prop): array => $prop->toArray(), $this->styleFields()),
            'advancedFields' => array_map(fn (StyleProp $prop): array => $prop->toArray(), $this->advancedFields()),
            'hasShell' => $this->hasShell(),
            'rootOnly' => $this->rootOnly(),
        ];
    }

    public function defaultVariant(): ?string
    {
        return array_key_first($this->variants());
    }
}
