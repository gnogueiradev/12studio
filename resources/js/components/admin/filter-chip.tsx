import { cn } from '@/lib/utils';

type Props = {
    text: string;
    count: number;
    active: boolean;
    onClick: () => void;
};

/**
 * Chip de filtro das listagens do backoffice: rótulo, contagem e estado ativo.
 *
 * A contagem vem sempre do servidor calculada SEM este filtro — senão todas as
 * chips exceto a ativa mostrariam zero e deixavam de servir para navegar.
 */
export function FilterChip({ text, count, active, onClick }: Props) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'rounded-full border border-border px-3.5 py-1.5 text-xs transition-colors',
                active
                    ? 'bg-secondary font-semibold text-foreground'
                    : 'font-medium text-muted-foreground hover:bg-secondary/60',
            )}
        >
            {text} <span className="text-muted-foreground">{count}</span>
        </button>
    );
}
