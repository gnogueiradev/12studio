import { hexToRgb } from '@/lib/color';

/**
 * As famílias de cor do filtro do catálogo, pela ordem em que aparecem.
 *
 * As cores da loja são filamentos: três laranjas quase iguais são três linhas
 * na base de dados, mas quem compra procura "laranja". A família tira-se do
 * hex, sem coluna nova — o preço é ser uma regra de olho: um tom na fronteira
 * (cobre, mostarda) pode cair na família ao lado.
 */
export const COLOR_FAMILIES = [
    'white',
    'beige',
    'grey',
    'black',
    'brown',
    'red',
    'orange',
    'yellow',
    'green',
    'blue',
    'purple',
    'pink',
] as const;

export type ColorFamily = (typeof COLOR_FAMILIES)[number];

export function colorFamily(hex: string): ColorFamily {
    const { r, g, b } = hexToRgb(hex);

    if ([r, g, b].some(Number.isNaN)) {
        return 'grey';
    }

    const [red, green, blue] = [r / 255, g / 255, b / 255];
    const max = Math.max(red, green, blue);
    const min = Math.min(red, green, blue);
    const delta = max - min;

    // HSL e não HSV: a luminosidade é o que separa o creme do castanho e o
    // rosa do vermelho, que têm a mesma matiz.
    const l = (max + min) / 2;
    const s = delta === 0 ? 0 : delta / (1 - Math.abs(2 * l - 1));

    let h = 0;

    if (delta !== 0) {
        if (max === red) {
            h = ((green - blue) / delta) % 6;
        } else if (max === green) {
            h = (blue - red) / delta + 2;
        } else {
            h = (red - green) / delta + 4;
        }

        h = (h * 60 + 360) % 360;
    }

    if (l < 0.13) {
        return 'black';
    }

    // Um branco sujo tem saturação "alta" no HSL (pouca margem até ao branco
    // puro), por isso o limite é mais largo quando a cor é muito clara.
    if (l > 0.93 || (l > 0.85 && s < 0.3)) {
        return 'white';
    }

    if (s < 0.1) {
        return l < 0.2 ? 'black' : 'grey';
    }

    // Tons quentes, claros e pouco vivos: creme, areia, natural. Quanto mais
    // escuro o tom, mais apagado tem de ser para ainda ser um bege e não um
    // caramelo.
    if (
        h >= 20 &&
        h < 65 &&
        ((l >= 0.72 && s < 0.65) || (l >= 0.58 && s < 0.45))
    ) {
        return 'beige';
    }

    // Tons quentes, escuros ou apagados: chocolate, madeira, caramelo.
    if (h >= 10 && h < 50 && (l < 0.38 || (s < 0.5 && l < 0.72))) {
        return 'brown';
    }

    if (h < 12 || h >= 345) {
        return l >= 0.65 ? 'pink' : 'red';
    }

    if (h < 35) {
        return 'orange';
    }

    if (h < 70) {
        // Um amarelo esverdeado e escuro é um azeitona.
        return h >= 50 && l < 0.35 ? 'green' : 'yellow';
    }

    if (h < 165) {
        return 'green';
    }

    if (h < 260) {
        return 'blue';
    }

    return h < 300 ? 'purple' : 'pink';
}

export type FamilyGroup<T> = { family: ColorFamily; colors: T[] };

/** As cores agrupadas por família, só as famílias que têm alguma. */
export function groupByFamily<T extends { hex: string }>(
    colors: T[],
): FamilyGroup<T>[] {
    return COLOR_FAMILIES.map((family) => ({
        family,
        colors: colors.filter((color) => colorFamily(color.hex) === family),
    })).filter((group) => group.colors.length > 0);
}

/**
 * O fundo da bolinha de uma família: a cor, ou uma roda com os tons que a
 * família junta (até quatro), para se ver que "Azul" é o marinho e o claro.
 */
export function familySwatch(hexes: string[]): string {
    const tones = [...new Set(hexes)].slice(0, 4);

    if (tones.length <= 1) {
        return tones[0] ?? 'transparent';
    }

    const slice = 100 / tones.length;

    return `conic-gradient(${tones
        .map(
            (tone, index) =>
                `${tone} ${index * slice}% ${(index + 1) * slice}%`,
        )
        .join(', ')})`;
}
