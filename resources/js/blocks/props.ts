import type { StoreBlock } from '@/types/store';

/**
 * O valor de uma propriedade de estilo de um elemento. Cada uma chega por
 * dispositivo ({desktop, tablet, mobile}); aqui só se usa o primeiro que
 * existir — o desenho responsivo completo vem do renderizador de estilos.
 */
export function styleProp<T extends string | number | boolean>(
    block: StoreBlock,
    key: string,
): T | undefined {
    const values = block.styleProps?.[key] as
        Partial<Record<'desktop' | 'tablet' | 'mobile', T>> | undefined;

    return values?.desktop ?? values?.tablet ?? values?.mobile;
}

/** Os tokens de cor do texto: nunca uma cor livre. */
export const TEXT_COLORS: Record<string, string> = {
    default: 'text-foreground',
    muted: 'text-muted-foreground',
    // O fundo `accent` é claro demais para texto: o acento do texto é o primário.
    accent: 'text-primary',
    gold: 'text-gold-text',
};
