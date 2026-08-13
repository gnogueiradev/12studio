/**
 * O `type="color"` e a validação em espelho no servidor só aceitam #rrggbb — o
 * hex com alfa cabe na coluna, mas nenhum dos dois formulários o oferece.
 */
export const isPlainHex = (value: string) => /^#[0-9a-fA-F]{6}$/.test(value);
