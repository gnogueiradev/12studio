/**
 * Contraste WCAG 2.x entre duas cores (1 a 21). Espelha o App\Support\Contrast,
 * que é quem decide ao gravar: aqui serve só para avisar enquanto se escolhe.
 */
export const AA_TEXT = 4.5;
export const AA_LARGE = 3;

function luminance(hex: string): number {
    const value = hex.replace('#', '').padEnd(6, '0').slice(0, 6);
    const [r, g, b] = [0, 2, 4]
        .map((index) => parseInt(value.slice(index, index + 2), 16) / 255)
        .map((channel) =>
            channel <= 0.03928
                ? channel / 12.92
                : ((channel + 0.055) / 1.055) ** 2.4,
        );

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(a: string, b: string): number {
    const [l1, l2] = [luminance(a), luminance(b)];

    return (
        Math.round(
            ((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)) * 100,
        ) / 100
    );
}
