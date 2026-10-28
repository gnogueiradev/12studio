import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { BlockStyle } from '@/types/store';

const BACKGROUNDS: Record<BlockStyle['bg'], string> = {
    default: '',
    muted: 'bg-muted',
    accent: 'bg-accent text-accent-foreground',
    // A classe `dark` vira os tokens desta secção para os do modo escuro (o
    // tema da loja também os define em `.store-theme .dark`), por isso tudo
    // o que está dentro — texto secundário, bordas, botões — acompanha.
    dark: 'dark bg-background text-foreground',
    gold: 'bg-gold text-gold-foreground',
};

const SPACINGS: Record<BlockStyle['spacing'], string> = {
    sm: 'py-8 md:py-10',
    md: 'py-12 md:py-16',
    lg: 'py-16 md:py-24',
};

/**
 * A casca de cada bloco: fundo, espaçamento, largura e alinhamento escolhidos
 * no construtor — sempre a partir dos tokens do tema, nunca cores livres.
 */
export function BlockSection({
    style,
    children,
    className,
    innerClassName,
    id,
}: {
    style: BlockStyle;
    children: ReactNode;
    className?: string;
    innerClassName?: string;
    id?: string;
}) {
    return (
        <section
            id={id}
            className={cn(
                BACKGROUNDS[style.bg],
                SPACINGS[style.spacing],
                style.align === 'center' && 'text-center',
                className,
            )}
        >
            <div
                className={cn(
                    style.width === 'contained'
                        ? 'mx-auto w-full max-w-6xl px-4'
                        : 'w-full px-4 md:px-8',
                    innerClassName,
                )}
            >
                {children}
            </div>
        </section>
    );
}

/** O cabeçalho comum (título e texto) dos blocos que o têm. */
export function BlockHeading({
    title,
    subtitle,
    align,
    children,
}: {
    title?: string;
    subtitle?: string;
    align: BlockStyle['align'];
    children?: ReactNode;
}) {
    if (!title && !subtitle && !children) {
        return null;
    }

    return (
        <div
            className={cn(
                'mb-8 flex flex-col gap-4 md:mb-10',
                align === 'center'
                    ? 'items-center'
                    : 'md:flex-row md:items-end md:justify-between',
            )}
        >
            <div className={cn('max-w-2xl', align === 'center' && 'mx-auto')}>
                {title && (
                    <h2 className="text-2xl font-semibold tracking-tight text-balance md:text-3xl">
                        {title}
                    </h2>
                )}
                {subtitle && (
                    <p className="mt-3 text-base text-muted-foreground md:text-lg">
                        {subtitle}
                    </p>
                )}
            </div>
            {children}
        </div>
    );
}
