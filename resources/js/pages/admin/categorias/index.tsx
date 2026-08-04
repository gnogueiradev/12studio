import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, destroy, edit, index } from '@/routes/admin/categorias';
import type { CategoryRow } from '@/types/catalog';

type Props = {
    categories: CategoryRow[];
};

export default function CategoriesIndex({ categories }: Props) {
    const archive = (category: CategoryRow) => {
        if (confirm(`Arquivar a categoria "${category.name}"?`)) {
            router.delete(destroy(category.id).url);
        }
    };

    return (
        <>
            <Head title="Categorias" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Categorias</h1>
                    <Button asChild>
                        <Link href={create()}>Nova categoria</Link>
                    </Button>
                </div>

                {categories.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">
                        Ainda não há categorias — cria a primeira.
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
                                        Slug
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Produtos
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Estado
                                    </th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {categories.map((category) => (
                                    <tr
                                        key={category.id}
                                        className="border-b border-border/40 last:border-0"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {category.name}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {category.slug}
                                        </td>
                                        <td className="px-4 py-3">
                                            {category.productsCount}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    category.active
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {category.active
                                                    ? 'Visível'
                                                    : 'Arquivada'}
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
                                                        href={edit(category.id)}
                                                    >
                                                        Editar
                                                    </Link>
                                                </Button>
                                                {category.active && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            archive(category)
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

CategoriesIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Categorias', href: index() },
    ],
};
