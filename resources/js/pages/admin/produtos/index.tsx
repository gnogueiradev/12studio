import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, destroy, edit, index } from '@/routes/admin/produtos';
import type { ProductRow } from '@/types/catalog';
import { FULFILLMENT_MODES, PRODUCT_STATUSES } from '@/types/catalog';

type Props = {
    products: ProductRow[];
};

function label(
    options: readonly { value: string; label: string }[],
    value: string,
): string {
    return options.find((option) => option.value === value)?.label ?? value;
}

export default function ProductsIndex({ products }: Props) {
    const archive = (product: ProductRow) => {
        if (confirm(`Arquivar o produto "${product.name}"?`)) {
            router.delete(destroy(product.id).url);
        }
    };

    return (
        <>
            <Head title="Produtos" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Produtos</h1>
                    <Button asChild>
                        <Link href={create()}>Novo produto</Link>
                    </Button>
                </div>

                {products.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">
                        Ainda não há produtos — cria o primeiro.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border/60">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border/60 text-left text-muted-foreground">
                                    <th className="px-4 py-3 font-medium">
                                        Nome
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Categoria
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Produção
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Estado
                                    </th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {products.map((product) => (
                                    <tr
                                        key={product.id}
                                        className="border-b border-border/40 last:border-0"
                                    >
                                        <td className="px-4 py-3">
                                            <span className="font-medium">
                                                {product.name}
                                            </span>
                                            {product.featured && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    Destaque
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {product.category ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {label(
                                                FULFILLMENT_MODES,
                                                product.fulfillmentMode,
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    product.status === 'active'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {label(
                                                    PRODUCT_STATUSES,
                                                    product.status,
                                                )}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={edit(product.id)}
                                                    >
                                                        Editar
                                                    </Link>
                                                </Button>
                                                {product.status !==
                                                    'archived' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            archive(product)
                                                        }
                                                    >
                                                        Arquivar
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
    ],
};
