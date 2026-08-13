/**
 * O `type="color"` e a validação em espelho no servidor só aceitam #rrggbb — o
 * hex com alfa cabe na coluna, mas nenhum dos dois formulários o oferece.
 */
export const isPlainHex = (value: string) => /^#[0-9a-fA-F]{6}$/.test(value);

/**
 * Duas cores são a mesma quando o nome bate certo sem distinguir maiúsculas —
 * a regra do `distinct:ignore_case` do StoreMaterialRequest. Comparar aqui do
 * mesmo modo é o que evita o formulário deixar escrever "preto" ao lado do
 * "Preto" que já existe e só o servidor dar pela coisa.
 */
export const sameColorName = (a: string, b: string) =>
    a.trim().toLocaleLowerCase() === b.trim().toLocaleLowerCase();
