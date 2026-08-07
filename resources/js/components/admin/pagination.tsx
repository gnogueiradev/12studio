import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types/pagination';

type Props<T> = {
    page: Paginated<T>;
    /** Nome plural para o resumo ("32 encomendas"). */
    noun: string;
};

export function Pagination<T>({ page, noun }: Props<T>) {
    if (page.total === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
            <span>
                {page.from}–{page.to} de {page.total} {noun}
            </span>

            {page.last_page > 1 && (
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={page.prev_page_url === null}
                        asChild={page.prev_page_url !== null}
                    >
                        {page.prev_page_url !== null ? (
                            <Link
                                href={page.prev_page_url}
                                preserveScroll
                                preserveState
                            >
                                Anterior
                            </Link>
                        ) : (
                            <span>Anterior</span>
                        )}
                    </Button>

                    <span>
                        Página {page.current_page} de {page.last_page}
                    </span>

                    <Button
                        variant="outline"
                        size="sm"
                        disabled={page.next_page_url === null}
                        asChild={page.next_page_url !== null}
                    >
                        {page.next_page_url !== null ? (
                            <Link
                                href={page.next_page_url}
                                preserveScroll
                                preserveState
                            >
                                Seguinte
                            </Link>
                        ) : (
                            <span>Seguinte</span>
                        )}
                    </Button>
                </div>
            )}
        </div>
    );
}
