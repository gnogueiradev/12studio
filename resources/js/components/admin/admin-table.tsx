import { router } from '@inertiajs/react';
import type { MouseEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type Column<T> = {
    /** Chave estável para o React; não tem de existir no objeto. */
    key: string;
    header: ReactNode;
    /** Classes extra na célula (ex.: "text-right"). */
    className?: string;
    cell: (row: T) => ReactNode;
};

type Props<T> = {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    /** Mostrado em vez da tabela quando não há linhas. */
    empty: ReactNode;
    /**
     * Destino da linha inteira. Conveniência de rato: a linha continua a ter
     * de conter um `<a>` para quem navega por teclado — uma `<tr onClick>` é
     * invisível para um leitor de ecrã e não se alcança com Tab.
     */
    rowHref?: (row: T) => string;
    /** Classes por linha (ex.: esbater o que está arquivado). */
    rowClassName?: (row: T) => string;
};

/**
 * Casca de tabela do backoffice. Extraída do markup duplicado entre as
 * listagens de produtos e categorias — a partir daqui todas as listagens
 * (variantes, clientes, encomendas) partilham o mesmo aspeto.
 */
export function AdminTable<T>({
    columns,
    rows,
    rowKey,
    empty,
    rowHref,
    rowClassName,
}: Props<T>) {
    if (rows.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    const handleRowClick = rowHref
        ? (row: T) => (event: MouseEvent<HTMLTableRowElement>) => {
              // Um clique num link ou botão dentro da linha já se trata a si
              // próprio; sem esta guarda o link do nº da encomenda disparava
              // duas navegações.
              if ((event.target as HTMLElement).closest('a, button')) {
                  return;
              }

              router.visit(rowHref(row));
          }
        : undefined;

    return (
        <div className="overflow-x-auto rounded-xl border border-border/60 bg-card">
            {/* Mais respiro nas extremidades do cartão do que entre colunas. */}
            <table className="w-full text-sm [&_td:first-child]:pl-5 [&_td:last-child]:pr-5 [&_th:first-child]:pl-5 [&_th:last-child]:pr-5">
                <thead>
                    <tr className="border-b border-border/60 text-left text-xs tracking-wider text-muted-foreground uppercase">
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                className={cn(
                                    'px-3 py-3 font-medium',
                                    column.className,
                                )}
                            >
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={rowKey(row)}
                            className={cn(
                                'border-b border-border/40 last:border-0',
                                rowHref &&
                                    'cursor-pointer transition-colors hover:bg-secondary',
                                rowClassName?.(row),
                            )}
                            onClick={handleRowClick?.(row)}
                        >
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={cn(
                                        'px-3 py-3',
                                        column.className,
                                    )}
                                >
                                    {column.cell(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
