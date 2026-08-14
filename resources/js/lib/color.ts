/**
 * Conversões de cor e contraste, para o seletor de tom do backoffice.
 *
 * Não há `<input type="color">` em lado nenhum: o nativo abre o seletor do
 * sistema operativo, que não sabe nada da paleta da marca nem dos dois temas, e
 * é diferente em cada máquina. O seletor é o `ColorPicker`, e estas funções são
 * o que ele precisa para trabalhar.
 */

/** Só #rrggbb. O alfa cabe na coluna, mas nenhum formulário o oferece. */
export const isPlainHex = (value: string) => /^#[0-9a-fA-F]{6}$/.test(value);

export type Rgb = { r: number; g: number; b: number };

/** Matiz 0–360, saturação e valor 0–1 — os eixos do seletor. */
export type Hsv = { h: number; s: number; v: number };

export function hexToRgb(hex: string): Rgb {
    return {
        r: parseInt(hex.slice(1, 3), 16),
        g: parseInt(hex.slice(3, 5), 16),
        b: parseInt(hex.slice(5, 7), 16),
    };
}

export function rgbToHex({ r, g, b }: Rgb): string {
    const channel = (value: number) =>
        Math.round(Math.min(255, Math.max(0, value)))
            .toString(16)
            .padStart(2, '0');

    return `#${channel(r)}${channel(g)}${channel(b)}`.toUpperCase();
}

export function hexToHsv(hex: string): Hsv {
    const { r, g, b } = hexToRgb(hex);
    const [red, green, blue] = [r / 255, g / 255, b / 255];

    const max = Math.max(red, green, blue);
    const min = Math.min(red, green, blue);
    const delta = max - min;

    // Cinzento não tem matiz. Devolver 0 mandava o cursor do slider para o
    // vermelho de cada vez que se escolhia um preto ou um branco.
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

    return { h, s: max === 0 ? 0 : delta / max, v: max };
}

export function hsvToHex({ h, s, v }: Hsv): string {
    const chroma = v * s;
    const x = chroma * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = v - chroma;

    const [r, g, b] = (
        [
            [chroma, x, 0],
            [x, chroma, 0],
            [0, chroma, x],
            [0, x, chroma],
            [x, 0, chroma],
            [chroma, 0, x],
        ] as const
    )[Math.floor((h % 360) / 60)];

    return rgbToHex({ r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 });
}

/** Luminância relativa da WCAG 2.1 (§ relative luminance). */
export function relativeLuminance(hex: string): number {
    const { r, g, b } = hexToRgb(hex);

    const linear = (value: number) => {
        const channel = value / 255;

        return channel <= 0.03928
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
}

/** Rácio de contraste entre dois tons: 1 (igual) a 21 (preto sobre branco). */
export function contrastRatio(a: string, b: string): number {
    const [lighter, darker] = [relativeLuminance(a), relativeLuminance(b)].sort(
        (first, second) => second - first,
    );

    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Os dois fundos contra os quais uma cor da base de dados acaba por ser vista.
 *
 * Cópia dos `--background` do `app.css` — o hex vive lá e aqui, e é isso que o
 * `DesignTokensTest` não consegue vigiar (lê CSS, não TypeScript). Só se usam
 * para calcular o aviso do seletor; nada é desenhado com eles.
 */
export const THEME_BACKGROUNDS = {
    light: '#FAF8F5',
    dark: '#211E1C',
} as const;

/** Abaixo disto um tom deixa de se distinguir do fundo (WCAG 1.4.11). */
export const MIN_CONTRAST = 3;

/**
 * O pior dos dois temas. Uma cor pode portar-se bem no claro e desaparecer no
 * escuro — e quem escolhe está a olhar só para um deles.
 */
export function worstThemeContrast(hex: string): number {
    return Math.min(
        contrastRatio(hex, THEME_BACKGROUNDS.light),
        contrastRatio(hex, THEME_BACKGROUNDS.dark),
    );
}
