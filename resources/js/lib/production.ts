import type { VariantRow } from '@/types/catalog';

/** O tempo de impressão e a gramagem da peça — os mesmos para todas as variantes. */
export type ProductProduction = {
    /** Total em minutos: "1h30" são 90, nunca 1,30. */
    printingTimeMinutes: number | null;
    filamentWeightGrams: number | null;
};

/**
 * O tempo e a gramagem do PRODUTO, lidos da variante de referência.
 *
 * As colunas vivem na variante — é de lá que a calculadora, o quadro de
 * produção e a listagem as lêem — mas a peça é a mesma em todas: muda a cor e
 * o material, não o tempo de máquina nem o plástico gasto. A aba "Produção"
 * escreve o mesmo par em todas, por isso qualquer uma serve de referência; a
 * lista chega ordenada com a principal à cabeça, a mesma regra que a listagem
 * usa para mostrar estes dois números na tabela de produtos.
 */
export function productProduction(variants: VariantRow[]): ProductProduction {
    const reference = variants[0];

    return {
        printingTimeMinutes: reference?.printingTimeMinutes ?? null,
        filamentWeightGrams: reference?.filamentWeightGrams ?? null,
    };
}
