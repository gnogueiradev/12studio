import { ColorSwatch } from '@/components/admin/color-swatch';
import { cn } from '@/lib/utils';

type Color = { id: number; name: string; hex: string };

type Common = {
    colors: Color[];
    /** Rótulo do botão que devolve a escolha a "sem cor". Sem ele não aparece. */
    emptyLabel?: string;
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
export function ColorSwatchGrid({ colors, emptyLabel, ...props }: Props) {
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

            {colors.map((color) => (
                <button
                    key={color.id}
                    type="button"
                    onClick={() => toggle(color.id)}
                    aria-pressed={chosen(color.id)}
                    title={color.hex}
                    className={cn(
                        'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                        chosen(color.id)
                            ? 'border-ring bg-secondary'
                            : 'border-border hover:border-ring',
                    )}
                >
                    <ColorSwatch hex={color.hex} />
                    {color.name}
                </button>
            ))}
        </div>
    );
}
