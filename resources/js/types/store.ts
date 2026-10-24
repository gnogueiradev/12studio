/**
 * Tipos da montra pública. Espelham os arrays que o servidor monta — nunca o
 * modelo inteiro: a montra só recebe o que mostra.
 */

/** ProductImage::responsive() */
export type ResponsiveImageData = {
    src: string;
    /** "…-320.webp 320w, …-640.webp 640w"; null até o job das versões reduzidas correr. */
    srcset: string | null;
    width: number | null;
    height: number | null;
    alt: string;
};
