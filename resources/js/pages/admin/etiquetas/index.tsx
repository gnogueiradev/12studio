import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { FilterChip } from '@/components/admin/filter-chip';
import { PageHeader } from '@/components/admin/page-header';
import { StatCard } from '@/components/admin/stat-card';
import { TagCreateDialog } from '@/components/admin/tag-create-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { label } from '@/lib/options';
import { fold } from '@/lib/text';
import { destroy, index, limpar } from '@/routes/admin/etiquetas';
import type { TagRow, TagStats } from '@/types/tag';
import { TAG_SCOPES } from '@/types/tag';

type Props = {
    tags: TagRow[];
    stats: TagStats;
};

const ALL = 'all';

export default function TagsIndex({ tags, stats }: Props) {
    /*
     * Filtros no cliente, como nas cores e nos materiais: esta listagem não
     * pagina e a página já trouxe as linhas todas.
     */
    const [search, setSearch] = useState('');
    const [scope, setScope] = useState<string>(ALL);
    /*
     * `?novo=1` pede o modal já aberto. Lido do `usePage().url` e não do
     * `window.location` para o SSR não ir abaixo à procura de um `window`.
     */
    const { url } = usePage();
    const [creating, setCreating] = useState(() => url.includes('novo=1'));
    const [editing, setEditing] = useState<TagRow | null>(null);
    const [deleting, setDeleting] = useState<TagRow | null>(null);
    const [pruning, setPruning] = useState(false);

    const needle = fold(search.trim());

    const visible = useMemo(
        () =>
            tags.filter((tag) => {
                if (scope !== ALL && tag.scope !== scope) {
                    return false;
                }

                return needle === '' || fold(tag.name).includes(needle);
            }),
        [tags, needle, scope],
    );

    const columns: Column<TagRow>[] = [
        {
            key: 'name',
            header: 'Etiqueta',
            cell: (tag) => (
                <span className="flex items-center gap-3">
                    <Badge variant="secondary">{tag.name}</Badge>
                    <span className="font-mono text-xs text-muted-foreground">
                        {tag.slug}
                    </span>
                </span>
            ),
        },
        {
            key: 'scope',
            header: 'Âmbito',
            className: 'text-muted-foreground',
            cell: (tag) => label(TAG_SCOPES, tag.scope),
        },
        {
            key: 'usage',
            header: 'Usos',
            className: 'text-right tabular-nums',
            cell: (tag) => tag.usageCount,
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (tag) => (
                <div className="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(tag)}
                    >
                        Editar
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleting(tag)}
                    >
                        Apagar
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Etiquetas" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Etiquetas"
                    description="O segundo eixo de organização. Ao contrário da categoria, uma etiqueta não é só do catálogo — serve o cliente e a encomenda, cada um com o seu vocabulário."
                >
                    {stats.unusedCount > 0 && (
                        <Button
                            variant="outline"
                            onClick={() => setPruning(true)}
                        >
                            Limpar por usar ({stats.unusedCount})
                        </Button>
                    )}
                    <Button onClick={() => setCreating(true)}>
                        Nova etiqueta
                    </Button>
                </PageHeader>

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard label="Etiquetas" value={String(stats.total)} />
                    <StatCard
                        label="Por usar"
                        value={String(stats.unusedCount)}
                        tone={stats.unusedCount > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Âmbitos com etiquetas"
                        value={String(
                            Object.values(stats.byScope).filter(
                                (count) => count > 0,
                            ).length,
                        )}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Nome"
                        className="max-w-xs"
                        aria-label="Pesquisar etiquetas"
                    />
                    {/*
                     * Contagens sobre a lista COMPLETA, nunca sobre a filtrada:
                     * senão todas as chips excepto a ativa mostravam zero e
                     * deixavam de servir para navegar.
                     */}
                    <div className="flex flex-wrap gap-2">
                        <FilterChip
                            text="Todas"
                            count={stats.total}
                            active={scope === ALL}
                            onClick={() => setScope(ALL)}
                        />
                        {TAG_SCOPES.map((option) => (
                            <FilterChip
                                key={option.value}
                                text={option.chipLabel}
                                count={stats.byScope[option.value] ?? 0}
                                active={scope === option.value}
                                onClick={() => setScope(option.value)}
                            />
                        ))}
                    </div>

                    <span className="ml-auto text-xs text-muted-foreground">
                        {visible.length}{' '}
                        {visible.length === 1 ? 'etiqueta' : 'etiquetas'}
                    </span>
                </div>

                <AdminTable
                    columns={columns}
                    rows={visible}
                    rowKey={(tag) => tag.id}
                    rowClassName={(tag) =>
                        tag.usageCount === 0 ? 'opacity-60' : ''
                    }
                    empty={
                        tags.length === 0
                            ? 'Ainda não há etiquetas. Nascem sozinhas ao marcar um produto, um cliente ou uma encomenda — aqui corrigem-se e limpam-se.'
                            : 'Nenhuma etiqueta com estes filtros.'
                    }
                />
            </div>

            {/*
             * A `key` é o que sincroniza o formulário com o alvo: remontar o
             * modal quando se passa de "nova" para "editar a natal" é mais
             * previsível do que um useEffect a chamar setData por cima do que o
             * admin está a escrever.
             */}
            <TagCreateDialog
                key={editing?.id ?? 'new'}
                open={creating || editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreating(false);
                        setEditing(null);
                    }
                }}
                editing={editing}
                tags={tags}
            />

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Apagar etiqueta"
                description={
                    <>
                        A etiqueta <strong>{deleting?.name}</strong> desaparece
                        para sempre.{' '}
                        {deleting?.usageCount === 0
                            ? 'Não a usa ninguém.'
                            : `${deleting?.usageCount} ${deleting?.usageCount === 1 ? 'item fica' : 'itens ficam'} sem ela — mais nada se perde.`}
                    </>
                }
                confirmLabel="Apagar"
                destructive
                onConfirm={() => {
                    if (deleting) {
                        router.delete(destroy(deleting.id).url, {
                            onFinish: () => setDeleting(null),
                        });
                    }
                }}
            />

            <ConfirmDialog
                open={pruning}
                onOpenChange={setPruning}
                title="Limpar etiquetas por usar"
                description={
                    <>
                        {stats.unusedCount === 1
                            ? 'A etiqueta que nenhum produto, cliente ou encomenda usa é apagada.'
                            : `As ${stats.unusedCount} etiquetas que nenhum produto, cliente ou encomenda usa são apagadas.`}{' '}
                        Nada mais é afetado.
                    </>
                }
                confirmLabel="Limpar"
                destructive
                onConfirm={() => {
                    router.delete(limpar().url, {
                        onFinish: () => setPruning(false),
                    });
                }}
            />
        </>
    );
}

TagsIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Etiquetas', href: index() },
    ],
};
