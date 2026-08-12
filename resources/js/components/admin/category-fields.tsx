import { cn } from '@/lib/utils';
import { CATEGORY_COLORS, CATEGORY_STATUSES } from '@/types/catalog';

/** O que cada estado significa para quem está do lado da loja. */
const STATUS_HINTS: Record<string, string> = {
    visible: 'Aparece no menu da loja.',
    hidden: 'Só se chega por link direto.',
    archived: 'Fora de circulação.',
};

type ColorPickerProps = {
    value: string | null;
    onChange: (hex: string | null) => void;
};

/**
 * Seletor da cor da categoria.
 *
 * Carregar na cor já escolhida limpa-a: sem isso, a primeira cor escolhida
 * ficava para sempre — não há nenhum outro sítio onde voltar a "sem cor".
 */
export function CategoryColorPicker({ value, onChange }: ColorPickerProps) {
    const selected = CATEGORY_COLORS.find((color) => color.hex === value);

    return (
        <div className="flex flex-wrap items-center gap-3">
            <div className="flex flex-wrap gap-2">
                {CATEGORY_COLORS.map((color) => (
                    <button
                        key={color.hex}
                        type="button"
                        onClick={() =>
                            onChange(color.hex === value ? null : color.hex)
                        }
                        aria-pressed={color.hex === value}
                        aria-label={color.name}
                        title={color.name}
                        className={cn(
                            'size-8 rounded-lg border-2 transition-colors',
                            color.hex === value
                                ? 'border-foreground'
                                : 'border-transparent hover:border-border',
                        )}
                        /*
                         * A cor vai em `style` e não numa classe: é um valor da
                         * base de dados, e uma classe de cor arbitrária (um
                         * `bg-` com o hex entre parênteses rectos) é uma cor
                         * solta fora da paleta da marca — o DesignTokensTest
                         * rejeita-as. Mesmo padrão do ColorSwatch.
                         */
                        style={{ backgroundColor: color.hex }}
                    />
                ))}
            </div>

            {/*
             * O design pinta o NOME da cor na própria cor. No tema claro esses
             * pastéis ficam abaixo dos 4.5:1 contra o bege do fundo, por isso a
             * cor vive na bolinha — que é decorativa e não tem mínimo de
             * contraste — e o nome fica no token de texto.
             */}
            <span className="inline-flex items-center gap-2 rounded-full border border-border bg-secondary px-3 py-1 text-xs">
                <span
                    aria-hidden
                    className="size-3 rounded-full border border-border"
                    style={{ backgroundColor: selected?.hex ?? 'transparent' }}
                />
                {selected?.name ?? 'Sem cor'}
            </span>
        </div>
    );
}

type StatusPickerProps = {
    value: string;
    onChange: (status: string) => void;
    /**
     * O modal de criação não oferece "Arquivada" — chega-se lá pela ação da
     * linha, e criar uma categoria já arquivada não quer dizer nada.
     */
    includeArchived?: boolean;
};

export function CategoryStatusPicker({
    value,
    onChange,
    includeArchived = false,
}: StatusPickerProps) {
    const options = CATEGORY_STATUSES.filter(
        (status) => includeArchived || status.value !== 'archived',
    );

    return (
        <div
            className={cn(
                'grid gap-2',
                options.length === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2',
            )}
        >
            {options.map((status) => {
                const active = status.value === value;

                return (
                    <button
                        key={status.value}
                        type="button"
                        onClick={() => onChange(status.value)}
                        aria-pressed={active}
                        className={cn(
                            'rounded-xl border p-3 text-left transition-colors',
                            active
                                ? 'border-ring bg-secondary'
                                : 'border-border hover:bg-secondary/60',
                        )}
                    >
                        <span className="block text-sm font-semibold">
                            {status.label}
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            {STATUS_HINTS[status.value]}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
