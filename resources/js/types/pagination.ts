/**
 * Forma do LengthAwarePaginator do Laravel serializado para o Inertia
 * (campos planos, sem `meta` — não usamos API Resources).
 *
 * O array `links` é deliberadamente ignorado: as suas labels trazem
 * entidades HTML (`&laquo;`) que obrigariam a injetar markup. Os URLs
 * `prev_page_url`/`next_page_url` dão a mesma navegação sem isso.
 */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
