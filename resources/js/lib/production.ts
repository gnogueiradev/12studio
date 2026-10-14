import type { VariantRow } from '@/types/catalog';

/** O tempo de impressão e a gramagem da peça — os mesmos para todas as variantes. */
export type ProductProduction = {
    /** Total em minutos: "1h30" são 90, nunca 1,30. */
    printingTimeMinutes: number | null;
    filamentWeightGrams: number | null;
};

/**
 * O tempo e a gramagem de referência de uma lista de variantes: os da
 * primeira. A lista chega ordenada com a principal à cabeça — a mesma regra
 * que a listagem usa para mostrar estes dois números na tabela de produtos.
 *
 * Serve para pré-preencher uma variante nova. A aba "Produção" trabalha por
 * tamanho: ver `productionBySize`.
 */
export function productProduction(variants: VariantRow[]): ProductProduction {
    const reference = variants[0];

    return {
        printingTimeMinutes: reference?.printingTimeMinutes ?? null,
        filamentWeightGrams: reference?.filamentWeightGrams ?? null,
    };
}

/**
 * O tempo e a gramagem com que uma variante nova nasce. Com um tamanho só, os
 * do produto — a peça é a mesma. Com vários, nenhuns: ainda não se sabe de
 * que tamanho vai ser, e herdar os da principal (10cm) numa de 20cm dava-lhe
 * o preço calculado de uma de 10cm.
 */
export function newVariantProduction(
    variants: VariantRow[],
): ProductProduction {
    return productionBySize(variants).length > 1
        ? { printingTimeMinutes: null, filamentWeightGrams: null }
        : productProduction(variants);
}

/**
 * A chave de um tamanho — a mesma do VariantSizes::key() no servidor: sem
 * espaços nas pontas, em minúsculas. Sem tamanho é ''.
 */
export function sizeKey(label: string | null): string {
    return (label ?? '').trim().toLowerCase();
}

/** Um tamanho do produto, com as suas variantes e o par que a aba edita. */
export type SizeProduction = ProductProduction & {
    key: string;
    /** Como o dono o escreveu (sem espaços nas pontas). Null = sem tamanho. */
    sizeLabel: string | null;
    variants: VariantRow[];
    /**
     * As variantes deste tamanho não concordam no tempo ou na gramagem —
     * dados de antes de a aba ser por tamanho. Guardar iguala-as.
     */
    mixed: boolean;
};

/**
 * As variantes agrupadas por tamanho, pela ordem em que cada tamanho aparece
 * primeiro — o da principal à cabeça. O par de cada grupo é o da sua primeira
 * variante. Um produto sem tamanhos dá um grupo só.
 */
export function productionBySize(variants: VariantRow[]): SizeProduction[] {
    const groups = new Map<string, SizeProduction>();

    for (const variant of variants) {
        const key = sizeKey(variant.sizeLabel);
        const group = groups.get(key);

        if (group === undefined) {
            groups.set(key, {
                key,
                sizeLabel: key === '' ? null : (variant.sizeLabel ?? '').trim(),
                variants: [variant],
                printingTimeMinutes: variant.printingTimeMinutes,
                filamentWeightGrams: variant.filamentWeightGrams,
                mixed: false,
            });

            continue;
        }

        group.variants.push(variant);
        group.mixed ||=
            variant.printingTimeMinutes !== group.printingTimeMinutes ||
            variant.filamentWeightGrams !== group.filamentWeightGrams;
    }

    return [...groups.values()];
}
