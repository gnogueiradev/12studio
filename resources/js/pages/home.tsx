import { Head } from '@inertiajs/react';

type ProductCard = {
    id: number;
    name: string;
    slug: string;
    category: string | null;
    priceCents: number | null;
    imageUrl: string | null;
    fulfillmentMode: string;
};

type Props = {
    products: ProductCard[];
};

function formatPrice(cents: number | null): string {
    if (cents === null) {
        return '—';
    }

    return new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
    }).format(cents / 100);
}

export default function Home({ products }: Props) {
    return (
        <>
            <Head title="Peças impressas em 3D" />

            <section className="mx-auto w-full max-w-6xl px-4 py-16">
                <div className="max-w-2xl">
                    <h1 className="text-4xl font-semibold tracking-tight text-balance">
                        Peças impressas em 3D, feitas em Portugal.
                    </h1>
                    <p className="mt-4 text-lg text-muted-foreground">
                        Decoração, gadgets e peças personalizadas — desenhadas e
                        impressas peça a peça no nosso estúdio.
                    </p>
                </div>
            </section>

            <section className="mx-auto w-full max-w-6xl px-4 pb-16">
                {products.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border p-12 text-center text-muted-foreground">
                        Os primeiros produtos estão a caminho — volta em breve.
                    </p>
                ) : (
                    <div className="grid grid-cols-2 gap-6 md:grid-cols-3 lg:grid-cols-4">
                        {products.map((product) => (
                            <article key={product.id} className="group">
                                <div className="aspect-square overflow-hidden rounded-xl border border-border/60 bg-muted">
                                    {product.imageUrl ? (
                                        <img
                                            src={product.imageUrl}
                                            alt={product.name}
                                            className="size-full object-cover transition-transform group-hover:scale-105"
                                        />
                                    ) : (
                                        <div className="flex size-full items-center justify-center text-xs text-muted-foreground">
                                            Sem imagem
                                        </div>
                                    )}
                                </div>
                                <div className="mt-3">
                                    {product.category && (
                                        <p className="text-xs text-muted-foreground">
                                            {product.category}
                                        </p>
                                    )}
                                    <h2 className="text-sm font-medium">
                                        {product.name}
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {formatPrice(product.priceCents)}
                                    </p>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </section>
        </>
    );
}
