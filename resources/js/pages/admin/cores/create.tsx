import { Head, Link, useForm } from '@inertiajs/react';
import ColorForm from '@/components/admin/color-form';
import { Button } from '@/components/ui/button';
import { index, store } from '@/routes/admin/cores';
import { index as materiaisIndex } from '@/routes/admin/materiais';
import type { ColorFormData, MaterialOption } from '@/types/catalog';

type Props = {
    materials: MaterialOption[];
};

export default function ColorsCreate({ materials }: Props) {
    const { data, setData, post, processing, errors } = useForm<ColorFormData>({
        material_id: materials[0]?.id ?? null,
        name: '',
        hex_color: '#000000',
        price_per_kg: '',
        is_active: true,
        sort_order: 0,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url);
    };

    return (
        <>
            <Head title="Nova cor" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Nova cor</h1>

                {materials.length === 0 ? (
                    <div className="flex max-w-xl flex-col items-start gap-3 rounded-xl border border-border/60 p-6">
                        <p className="text-sm text-muted-foreground">
                            Uma cor tem de pertencer a um material, e ainda não
                            há nenhum disponível.
                        </p>
                        {/* O material novo nasce no modal da listagem, e o
                            `?novo=1` leva-o já aberto. */}
                        <Button asChild>
                            <Link href={materiaisIndex({ query: { novo: 1 } })}>
                                Criar material
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <ColorForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        processing={processing}
                        onSubmit={submit}
                        submitLabel="Criar cor"
                        materials={materials}
                    />
                )}
            </div>
        </>
    );
}

ColorsCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Cores', href: index() },
        { title: 'Nova', href: '#' },
    ],
};
