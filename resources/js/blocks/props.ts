import type { CSSProperties } from 'react';
import { resolveDevices } from '@/lib/page-tree';
import { cn } from '@/lib/utils';
import type { StoreBlock, StoreDeviceValues } from '@/types/store';

/**
 * O valor único de uma propriedade que não pode variar por dispositivo (a tag
 * de um título): é o do computador, ou o primeiro que existir.
 */
export function styleProp<T extends string | number | boolean>(
    block: StoreBlock,
    key: string,
): T | undefined {
    const values = block.styleProps?.[key] as StoreDeviceValues<T> | undefined;

    return values?.desktop ?? values?.tablet ?? values?.mobile;
}

/** Os valores nos três tamanhos: o que falta herda do tamanho acima. */
export function deviceValues<T extends string | number | boolean>(
    block: StoreBlock,
    key: string,
    fallback: T,
): Required<StoreDeviceValues<T>> {
    return resolveDevices(
        block.styleProps?.[key] as StoreDeviceValues<T> | undefined,
        fallback,
    ) as Required<StoreDeviceValues<T>>;
}

/**
 * As classes de uma opção nos três tamanhos: telemóvel, tablet (md, 48rem) e
 * computador (lg, 64rem). Escritas por extenso, para o Tailwind as encontrar.
 */
export type DeviceClasses = readonly [string, string, string];

/** As classes de um enum ou token, cada tamanho com a sua opção. */
export function deviceClasses(
    block: StoreBlock,
    key: string,
    options: Record<string, DeviceClasses>,
    fallback: string,
): string {
    const { mobile, tablet, desktop } = deviceValues(block, key, fallback);
    const pick = (value: string) => options[value] ?? options[fallback];

    return cn(pick(mobile)[0], pick(tablet)[1], pick(desktop)[2]);
}

/**
 * Um número nos três tamanhos como variáveis (`--nome-m`, `-t` e `-d`), que o
 * componente lê com classes `md:` e `lg:` no próprio elemento.
 */
export function deviceVars(
    block: StoreBlock,
    key: string,
    name: string,
    fallback: number,
    unit: string,
): CSSProperties {
    const { mobile, tablet, desktop } = deviceValues(block, key, fallback);

    return {
        [`--${name}-m`]: `${mobile}${unit}`,
        [`--${name}-t`]: `${tablet}${unit}`,
        [`--${name}-d`]: `${desktop}${unit}`,
    } as CSSProperties;
}

/** Os tokens de cor do texto: nunca uma cor livre. */
export const TEXT_COLORS: Record<string, DeviceClasses> = {
    default: ['text-foreground', 'md:text-foreground', 'lg:text-foreground'],
    muted: [
        'text-muted-foreground',
        'md:text-muted-foreground',
        'lg:text-muted-foreground',
    ],
    // O fundo `accent` é claro demais para texto: o acento do texto é o primário.
    accent: ['text-primary', 'md:text-primary', 'lg:text-primary'],
    gold: ['text-gold-text', 'md:text-gold-text', 'lg:text-gold-text'],
};
