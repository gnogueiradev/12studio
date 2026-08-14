import { ColorPicker } from '@/components/admin/color-picker';
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
 * Hex livre, com os sete tons do design como atalhos. Foi paleta fechada
 * enquanto a cor pintava texto e um tom qualquer podia deixar de se ler; agora
 * a cor vive numa bolinha decorativa, e o seletor avisa quando o contraste cai
 * — a decisão fica de quem escolhe, que é quem conhece a categoria.
 *
 * "Sem cor" é um botão e não o carregar-outra-vez: aqui há um espectro inteiro,
 * e acertar no mesmo hex ao pixel para voltar a limpar não era um caminho.
 */
export function CategoryColorPicker({ value, onChange }: ColorPickerProps) {
    return (
        <ColorPicker
            value={value ?? ''}
            onChange={onChange}
            presets={[...CATEGORY_COLORS]}
            onClear={() => onChange(null)}
            idPrefix="category-color"
        />
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
