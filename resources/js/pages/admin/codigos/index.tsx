import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { label } from '@/lib/options';
import { cn } from '@/lib/utils';
import { create, edit, index } from '@/routes/admin/codigos';
import type { Paginated } from '@/types/pagination';
import type { PromoCodeRow } from '@/types/promo';
import { PROMO_CHANNELS } from '@/types/promo';

type Props = {
    promoCodes: Paginated<PromoCodeRow>;
    filters: { search: string };
};

/** Tempo de silêncio antes de a pesquisa ir ao servidor. */
const SEARCH_DEBOUNCE_MS = 350;

export default function PromoCodesIndex({ promoCodes, filters }: Props) {
    const [search, setSearch] = useState(filters.search);

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(
            () =>
                router.get(index().url, search === '' ? {} : { search }, {
                    preserveState: true,
                    replace: true,
                }),
            SEARCH_DEBOUNCE_MS,
        );

        return () => clearTimeout(timer);
    }, [search, filters]);

    const columns: Column<PromoCodeRow>[] = [
        {
            key: 'code',
            header: 'Código',
            cell: (promoCode) => (
                <div className="min-w-0">
                    <span className="block font-mono font-medium">
                        {promoCode.code}
                    </span>
                    {promoCode.email !== null && (
                        <span className="block truncate text-xs text-muted-foreground">
                            {promoCode.email}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'value',
            header: 'Desconto',
            className: 'tabular-nums',
            cell: (promoCode) =>
                promoCode.type === 'percent'
                    ? `${promoCode.value} %`
                    : formatCents(promoCode.value),
        },
        {
            key: 'channel',
            header: 'Canal',
            cell: (promoCode) => label(PROMO_CHANNELS, promoCode.channel),
        },
        {
            key: 'uses',
            header: 'Usos',
            className: 'tabular-nums',
            cell: (promoCode) =>
                promoCode.maxUses === null
                    ? promoCode.usesCount
                    : `${promoCode.usesCount} / ${promoCode.maxUses}`,
        },
        {
            key: 'expires',
            header: 'Validade',
            className: 'tabular-nums text-muted-foreground',
            cell: (promoCode) => promoCode.expiresAt ?? '—',
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (promoCode) => (
                <StatusBadge
                    value={promoCode.isActive ? 'active' : 'archived'}
                    label={promoCode.isActive ? 'Ativo' : 'Inativo'}
                />
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (promoCode) => (
                <Button variant="outline" size="sm" asChild>
                    <Link href={edit(promoCode.id)}>Editar</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Códigos de desconto" />
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col gap-4 p-6 pb-10">
                <PageHeader
                    title="Códigos de desconto"
                    description="Valem na encomenda manual e no link de pagamento. Um código não se apaga, desativa-se."
                >
                    <Button className="rounded-full" asChild>
                        <Link href={create()}>Novo código</Link>
                    </Button>
                </PageHeader>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-60 flex-1 sm:max-w-85">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Código ou email"
                            aria-label="Procurar códigos"
                            className="pl-9"
                        />
                    </div>
                </div>

                <AdminTable
                    columns={columns}
                    rows={promoCodes.data}
                    rowKey={(promoCode) => promoCode.id}
                    rowClassName={(promoCode) =>
                        cn(!promoCode.isActive && 'opacity-60')
                    }
                    empty="Nenhum código."
                />

                <Pagination page={promoCodes} noun="códigos" />
            </div>
        </>
    );
}

PromoCodesIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Códigos', href: index() },
    ],
};
