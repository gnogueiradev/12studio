import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import ProductForm from '@/components/admin/product-form';
import { ProductImages } from '@/components/admin/product-images';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { index, update } from '@/routes/admin/produtos';
import { create as createVariant } from '@/routes/admin/produtos/variantes';
import {
    destroy as destroyVariant,
    edit as editVariant,
} from '@/routes/admin/variantes';
import type {
    CategoryOption,
    ProductDetail,
    ProductFormData,
    ProductImageRow,
    VariantRow,
} from '@/types/catalog';

type Props = {
    product: ProductDetail;
    categories: CategoryOption[];
    tagSuggestions: string[];
    images: ProductImageRow[];
    variants: VariantRow[];
};

export default function ProductsEdit({
    product,
    categories,
    tagSuggestions,
    images,
    variants,
}: Props) {
    const [archiving, setArchiving] = useState<VariantRow | null>(null);

    const { data, setData, patch, processing, errors } =
        useForm<ProductFormData>({
            name: product.name,
            slug: product.slug,
            category_id: product.categoryId,
            description: product.description ?? '',
            tags: product.tags,
            status: product.status,
            featured: product.featured,
            vat_rate: product.vatRate,
            fulfillment_mode: product.fulfillmentMode,
            production_time_days: product.productionTimeDays,
            allow_backorder: product.allowBackorder,
            max_open_production_qty: product.maxOpenProductionQty,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update(product.id).url);
    };

    const columns: Column<VariantRow>[] = [
        {
            key: 'sku',
            header: 'SKU',
            cell: (variant) => (
                <>
                    <span className="font-medium">{variant.sku}</span>
                    {variant.isDefault && (
                        <Badge variant="outline" className="ml-2">
                            Principal
                        </Badge>
                    )}
                </>
            ),
        },
        {
            key: 'variação',
            header: 'Cor / material / tamanho',
            className: 'text-muted-foreground',
            cell: (variant) => (
                <span className="flex items-center gap-2">
                    {variant.color && (
                        <>
                            <ColorSwatch hex={variant.color.hex} />
                            <span>{variant.color.name}</span>
                        </>
                    )}
                    {variant.material && <span>{variant.material.name}</span>}
                    {variant.sizeLabel && <span>{variant.sizeLabel}</span>}
                    {!variant.color &&
                        !variant.material &&
                        !variant.sizeLabel &&
                        '—'}
                </span>
            ),
        },
        {
            key: 'price',
            header: 'Preço',
            cell: (variant) => (
                <>
                    {formatCents(variant.priceCents)}
                    {variant.compareAtCents !== null && (
                        <span className="ml-2 text-muted-foreground line-through">
                            {formatCents(variant.compareAtCents)}
                        </span>
                    )}
                    {variant.wholesalePriceCents !== null && (
                        <span className="block text-xs text-muted-foreground">
                            revenda {formatCents(variant.wholesalePriceCents)}
                        </span>
                    )}
                </>
            ),
        },
        {
            key: 'stock',
            header: 'Stock',
            cell: (variant) => (
                <span className={variant.lowStock ? 'text-warning' : ''}>
                    {variant.availableStock}
                    {variant.reservedStock > 0 && (
                        <span className="text-muted-foreground">
                            {' '}
                            ({variant.reservedStock} reservadas)
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (variant) => (
                <Badge variant={variant.active ? 'default' : 'secondary'}>
                    {variant.active ? 'Ativa' : 'Arquivada'}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (variant) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={editVariant(variant.id)}>Editar</Link>
                    </Button>
                    {variant.active && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setArchiving(variant)}
                        >
                            Arquivar
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={`Editar ${product.name}`} />
            <div className="flex h-full flex-1 flex-col gap-8 p-4">
                <div className="flex flex-col gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">
                            {product.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            /{product.slug}
                        </p>
                    </div>
                    <ProductForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        processing={processing}
                        onSubmit={submit}
                        submitLabel="Guardar alterações"
                        categories={categories}
                        tagSuggestions={tagSuggestions}
                    />
                </div>

                <div className="flex flex-col gap-4 border-t border-border/60 pt-8">
                    <div>
                        <h2 className="text-lg font-semibold">Fotografias</h2>
                        <p className="text-sm text-muted-foreground">
                            A principal é a que aparece nas listagens, no
                            carrinho e nos emails. As restantes formam a galeria
                            da página do produto.
                        </p>
                    </div>

                    <ProductImages productId={product.id} images={images} />
                </div>

                <div className="flex flex-col gap-4 border-t border-border/60 pt-8">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">Variantes</h2>
                            <p className="text-sm text-muted-foreground">
                                O preço e o stock vivem aqui, não no produto.
                                Sem variantes, o produto não pode ser vendido.
                            </p>
                        </div>
                        <Button asChild variant="outline">
                            <Link href={createVariant(product.id)}>
                                Nova variante
                            </Link>
                        </Button>
                    </div>

                    <AdminTable
                        columns={columns}
                        rows={variants}
                        rowKey={(variant) => variant.id}
                        empty="Este produto ainda não tem variantes — cria a primeira para lhe dar preço e stock."
                    />
                </div>
            </div>

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar variante"
                description={
                    <>
                        A variante <strong>{archiving?.sku}</strong> deixa de
                        poder ser vendida. Os movimentos de stock e as
                        encomendas onde aparece mantêm-se.
                    </>
                }
                confirmLabel="Arquivar"
                destructive
                onConfirm={() => {
                    if (archiving) {
                        router.delete(destroyVariant(archiving.id).url, {
                            onFinish: () => setArchiving(null),
                        });
                    }
                }}
            />
        </>
    );
}

ProductsEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
        { title: 'Editar', href: '#' },
    ],
};
