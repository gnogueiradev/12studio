import { cn } from '@/lib/utils';

type Props = {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
};

/**
 * Chip de seleção múltipla dos modais de criação (cores, tamanhos, materiais).
 *
 * Irmão do FilterChip mas com outro trabalho: o FilterChip navega — escolhe o
 * que a listagem mostra — e este faz parte do formulário. Daí o `aria-pressed`
 * com a borda em destaque: o que aqui fica ligado vai ser gravado.
 */
export function ToggleChip({ active, onClick, children }: Props) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs transition-colors',
                active
                    ? 'border-ring bg-secondary font-semibold text-foreground'
                    : 'border-border font-medium text-muted-foreground hover:bg-secondary/60',
            )}
        >
            {children}
        </button>
    );
}
