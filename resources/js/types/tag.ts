/**
 * O segundo eixo de organização. Ao contrário da categoria, uma etiqueta não é
 * exclusiva do catálogo: o mesmo mecanismo serve o cliente e a encomenda.
 *
 * O âmbito é o que impede as sugestões de uma ficha de cliente de se encherem de
 * vocabulário da loja — "natal" em produtos e "natal" em encomendas são duas
 * etiquetas independentes, e é isso que se pretende.
 *
 * Mesma convenção do COLOR_STATES: singular na pastilha, plural na chip.
 */
export const TAG_SCOPES = [
    { value: 'product', label: 'Produto', chipLabel: 'Produtos' },
    { value: 'customer', label: 'Cliente', chipLabel: 'Clientes' },
    { value: 'order', label: 'Encomenda', chipLabel: 'Encomendas' },
] as const;

export type TagRow = {
    id: number;
    scope: string;
    name: string;
    slug: string;
    /** Quantos produtos, clientes ou encomendas a usam. */
    usageCount: number;
};

export type TagStats = {
    total: number;
    byScope: Record<string, number>;
    unusedCount: number;
};

/**
 * Criar ou editar no modal da listagem. Chaves em snake_case porque espelham as
 * regras do StoreTagRequest. Ao editar, o `scope` não viaja: uma etiqueta não
 * muda de âmbito depois de criada.
 */
export type TagFormData = {
    scope: string;
    name: string;
};
