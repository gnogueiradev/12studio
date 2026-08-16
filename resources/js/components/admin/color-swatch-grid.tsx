import { ColorSwatch } from '@/components/admin/color-swatch';
import { cn } from '@/lib/utils';

type Color = { id: number; name: string; hex: string };

type Common = {
    colors: Color[];
    /** Rótulo do botão que devolve a escolha a "sem cor". Sem ele não aparece. */
    emptyLabel?: string;
    /**
     * Cores que não se podem escolher agora — não existem no filamento que já
     * está escolhido. Ficam esbatidas e bloqueadas em vez de desaparecerem: o
     * cliente do backoffice precisa de ver que a cor existe e que é o material
     * que a exclui, senão fica a pensar que ela nunca se fez.
     *
     * Uma cor JÁ ESCOLHIDA nunca é bloqueada, mesmo que entre nesta lista —
     * senão, mudar de material deixava-a presa na selecção sem forma de a tirar.
     */
    disabledIds?: number[];
    /** Porquê, em linguagem de dono de loja. Vai no `title` do botão. */
    disabledReason?: (id: number) => string;
};

type Props = Common &
    (
        | {
              multiple?: false;
              value: number | null;
              onChange: (id: number | null) => void;
          }
        | {
              multiple: true;
              value: number[];
              onChange: (ids: number[]) => void;
          }
    );

/**
 * Escolher cores do catálogo — uma, no formulário da variante; várias, na
 * matriz que gera as variantes de um produto.
 *
 * É uma grelha de amostras e não um `<Select>` porque uma cor reconhece-se pelo
 * tom antes de se ler o nome: num seletor fechado, escolher entre "Verde musgo"
 * e "Verde azeitona" obrigava a abrir a lista e comparar duas bolinhas do
 * tamanho de uma letra.
 *
 * Não é o `ColorPicker`: aqui não se inventa um tom, escolhe-se um que já
 * existe. Cor nova cria-se em /admin/cores, que é onde ela ganha nome.
 */
export function ColorSwatchGrid({
    colors,
    emptyLabel,
    disabledIds,
    disabledReason,
    ...props
}: Props) {
    if (colors.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Ainda não há cores no catálogo.
            </p>
        );
    }

    const chosen = (id: number) =>
        props.multiple === true ? props.value.includes(id) : props.value === id;

    const toggle = (id: number) => {
        if (props.multiple === true) {
            props.onChange(
                props.value.includes(id)
                    ? props.value.filter((current) => current !== id)
                    : [...props.value, id],
            );

            return;
        }

        // Carregar na cor já escolhida limpa-a, como no seletor da categoria:
        // sem isso, a primeira escolha ficava para sempre.
        props.onChange(props.value === id ? null : id);
    };

    return (
        <div className="flex flex-wrap gap-2">
            {emptyLabel !== undefined && props.multiple !== true && (
                <button
                    type="button"
                    onClick={() => props.onChange(null)}
                    aria-pressed={props.value === null}
                    className={cn(
                        'rounded-lg border px-3 py-2 text-sm transition-colors',
                        props.value === null
                            ? 'border-ring bg-secondary'
                            : 'border-border text-muted-foreground hover:border-ring',
                    )}
                >
                    {emptyLabel}
                </button>
            )}

            {colors.map((color) => {
                const blocked =
                    !chosen(color.id) &&
                    (disabledIds?.includes(color.id) ?? false);
                const reason = blocked
                    ? (disabledReason?.(color.id) ?? null)
                    : null;

                return (
                    <button
                        key={color.id}
                        type="button"
                        onClick={() => toggle(color.id)}
                        disabled={blocked}
                        aria-pressed={chosen(color.id)}
                        title={reason ?? color.hex}
                        className={cn(
                            'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                            blocked
                                ? 'cursor-not-allowed border-dashed border-border text-muted-foreground opacity-50'
                                : chosen(color.id)
                                  ? 'border-ring bg-secondary'
                                  : 'border-border hover:border-ring',
                        )}
                    >
                        <ColorSwatch hex={color.hex} />
                        {color.name}
                    </button>
                );
            })}
        </div>
    );
}
