import { Head, useForm } from '@inertiajs/react';
import CategoryForm from '@/components/admin/category-form';
import { ContentTranslations } from '@/components/admin/content-translations';
import type { ContentTranslation } from '@/components/admin/content-translations';
import { Panel } from '@/components/admin/panel';
import { index, traducoes, update } from '@/routes/admin/categorias';
import type { CategoryDetail, CategoryFormData } from '@/types/catalog';

type Props = {
    category: CategoryDetail;
    translations: Record<string, ContentTranslation>;
};

export default function CategoriesEdit({ category, translations }: Props) {
    const { data, setData, patch, processing, errors } =
        useForm<CategoryFormData>({
            name: category.name,
            description: category.description ?? '',
            status: category.status,
            color: category.color,
            sort_order: category.sortOrder,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update(category.id).url);
    };

    return (
        <>
            <Head title={`Editar ${category.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{category.name}</h1>
                    <p className="text-sm text-muted-foreground">
                        /{category.slug}
                    </p>
                </div>
                <CategoryForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Guardar alterações"
                />
                <Panel
                    title="Traduções da montra"
                    description="O nome e a descrição nos outros idiomas. O que ficar vazio aparece em português."
                    className="max-w-2xl"
                >
                    <ContentTranslations
                        url={(locale) => traducoes([category.id, locale]).url}
                        translations={translations}
                        original={{
                            name: category.name,
                            description: category.description,
                        }}
                    />
                </Panel>
            </div>
        </>
    );
}

CategoriesEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Categorias', href: index() },
        { title: 'Editar', href: '#' },
    ],
};
