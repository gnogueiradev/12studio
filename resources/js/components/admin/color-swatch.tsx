import { cn } from '@/lib/utils';

type Props = {
    /** Hex com # — 6 ou 8 dígitos (o 8.º par é alfa, aceite pela BD). */
    hex: string;
    className?: string;
};

/**
 * Círculo de cor com borda própria: sem ela, o branco e os tons muito claros
 * desaparecem no fundo claro do backoffice (e os pretos no modo escuro).
 * A borda usa o token `border` para acompanhar o tema, nunca um cinzento fixo.
 */
export function ColorSwatch({ hex, className }: Props) {
    return (
        <span
            aria-hidden
            className={cn(
                'inline-block size-4 shrink-0 rounded-full border border-border',
                className,
            )}
            style={{ backgroundColor: hex }}
        />
    );
}
