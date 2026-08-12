/**
 * Slug ASCII a partir de um nome ("Decoração de Natal" → "decoracao-de-natal").
 *
 * Espelha o `Str::slug` que o `App\Support\Slug` usa no servidor, mas serve só
 * para PRÉ-VISUALIZAR: o slug que fica gravado é sempre o que o serviço deriva.
 * Os dois podem divergir num caso — quando o slug já existe, o servidor
 * acrescenta um sufixo ("gadgets-2") que aqui não há como adivinhar sem ir à
 * base de dados. É o preço de mostrar o endereço enquanto se escreve o nome.
 *
 * O NFD separa a letra do acento; o `\p{M}` (marcas de combinação) apaga os
 * acentos que ficam soltos, e é o que transforma "ç" em "c" em vez de o deixar
 * cair no filtro seguinte e virar hífen.
 */
export function slugify(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
