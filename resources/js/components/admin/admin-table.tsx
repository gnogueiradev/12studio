import type { ReactNode } from 'react';

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
};

/**
 * Casca de tabela do backoffice. Extraída do markup duplicado entre as
 * listagens de produtos e categorias — a partir daqui todas as listagens
 * (variantes, clientes, encomendas) partilham o mesmo aspeto.
 */
export function AdminTable<T>({ columns, rows, rowKey, empty }: Props<T>) {
    if (rows.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-xl border border-border/60">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-border/60 text-left text-muted-foreground">
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                className={`px-4 py-3 font-medium ${column.className ?? ''}`}
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
                            className="border-b border-border/40 last:border-0"
                        >
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={`px-4 py-3 ${column.className ?? ''}`}
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
