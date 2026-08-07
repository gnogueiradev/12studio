export type Option = { value: string; label: string };

/** Etiqueta PT de um valor de enum ("in_stock" → "Em stock"). */
export function label(options: readonly Option[], value: string): string {
    return options.find((option) => option.value === value)?.label ?? value;
}
