/**
 * Achata acentos e maiúsculas para comparar texto escrito à pressa: "ceramica"
 * encontra "Cerâmica", "PLA silk" encontra "PLA Silk".
 *
 * Mesmo par NFD + `\p{M}` do `slugify`, mas sem lhe mexer no resto: aqui os
 * espaços e a pontuação têm de sobreviver para "PLA Silk" continuar a bater.
 */
export function fold(value: string): string {
    return value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
}
