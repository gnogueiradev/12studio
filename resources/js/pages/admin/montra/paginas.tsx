import { Head, useForm } from '@inertiajs/react';
import { Plus, Search, SearchX, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { MontraNav } from '@/components/admin/montra/montra-nav';
import {
    NewLandingTile,
    PageCard,
} from '@/components/admin/montra/pages/page-card';
import { PageHeader } from '@/components/admin/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { slugify } from '@/lib/slug';
import { store } from '@/routes/admin/montra/paginas';
import type { PageRow } from '@/types/page-builder';

type Props = {
    system: PageRow[];
    landings: PageRow[];
};

type Filter = 'all' | 'system' | 'landing';

const GRID =
    'grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4';

/**
 * Montra → Páginas. As de sistema (o site precisa delas: não se apagam) e as
 * landing pages, em cartões com a miniatura de cada uma.
 */
export default function PagesIndex({ system, landings }: Props) {
    const [creating, setCreating] = useState(false);
    const [filter, setFilter] = useState<Filter>('all');
    const [search, setSearch] = useState('');

    const query = normalize(search.trim());
    const shownSystem = useMemo(
        () => system.filter((page) => matches(page, query)),
        [system, query],
    );
    const shownLandings = useMemo(
        () => landings.filter((page) => matches(page, query)),
        [landings, query],
    );

    const withSystem = filter !== 'landing';
    const withLandings = filter !== 'system';
    const total =
        (withSystem ? shownSystem.length : 0) +
        (withLandings ? shownLandings.length : 0);
    const newTile = query === '' && (
        <NewLandingTile onClick={() => setCreating(true)} />
    );

    return (
        <>
            <Head title="Páginas da montra" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Montra"
                    description="As páginas do site público, montadas por blocos. O que mexes fica em rascunho até carregares em “Publicar”."
                >
                    <Button onClick={() => setCreating(true)}>
                        <Plus />
                        Nova landing page
                    </Button>
                </PageHeader>

                <MontraNav current="paginas" />

                <div className="flex flex-wrap items-center gap-2">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={filter}
                        onValueChange={(value) =>
                            value && setFilter(value as Filter)
                        }
                        aria-label="Mostrar"
                        className="max-w-full overflow-x-auto"
                    >
                        <FilterItem
                            value="all"
                            label="Todas"
                            count={system.length + landings.length}
                        />
                        <FilterItem
                            value="system"
                            label="Páginas do site"
                            count={system.length}
                        />
                        <FilterItem
                            value="landing"
                            label="Landing pages"
                            count={landings.length}
                        />
                    </ToggleGroup>

                    <div className="relative min-w-60 flex-1 sm:ml-auto sm:max-w-80 sm:flex-none">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nome ou endereço"
                            aria-label="Procurar páginas"
                            className="pr-9 pl-9 [&::-webkit-search-cancel-button]:hidden"
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                aria-label="Limpar pesquisa"
                                className="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                <X className="size-4" />
                            </button>
                        )}
                    </div>
                </div>

                {query !== '' && total === 0 ? (
                    <NoResults search={search} onClear={() => setSearch('')} />
                ) : filter === 'all' ? (
                    <div className="flex flex-col gap-8">
                        {shownSystem.length > 0 && (
                            <Section
                                title="Páginas do site"
                                description="Fazem parte do site: mudas tudo o que têm, mas não se apagam."
                                count={shownSystem.length}
                            >
                                <PageGrid pages={shownSystem} />
                            </Section>
                        )}
                        {(shownLandings.length > 0 || query === '') && (
                            <Section
                                title="Landing pages"
                                description="Páginas tuas, com endereço próprio e, se quiseres, datas."
                                count={shownLandings.length}
                            >
                                <PageGrid pages={shownLandings}>
                                    {newTile}
                                </PageGrid>
                            </Section>
                        )}
                    </div>
                ) : filter === 'system' ? (
                    <PageGrid pages={shownSystem} />
                ) : (
                    <PageGrid pages={shownLandings}>{newTile}</PageGrid>
                )}
            </div>

            <CreateLandingDialog open={creating} onOpenChange={setCreating} />
        </>
    );
}

function FilterItem({
    value,
    label,
    count,
}: {
    value: Filter;
    label: string;
    count: number;
}) {
    return (
        <ToggleGroupItem value={value} className="gap-1.5 px-3">
            {label}
            <span className="text-xs text-muted-foreground tabular-nums">
                {count}
            </span>
        </ToggleGroupItem>
    );
}

function Section({
    title,
    description,
    count,
    children,
}: {
    title: string;
    description: string;
    count: number;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <div>
                <h2 className="flex items-baseline gap-2 text-base font-semibold">
                    {title}
                    <span className="text-sm font-normal text-muted-foreground tabular-nums">
                        {count}
                    </span>
                </h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            {children}
        </section>
    );
}

function PageGrid({
    pages,
    children,
}: {
    pages: PageRow[];
    children?: ReactNode;
}) {
    return (
        <div className={GRID}>
            {children}
            {pages.map((page) => (
                <PageCard key={page.id} page={page} />
            ))}
        </div>
    );
}

function NoResults({
    search,
    onClear,
}: {
    search: string;
    onClear: () => void;
}) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border px-6 py-16 text-center">
            <span className="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <SearchX aria-hidden className="size-5" />
            </span>
            <div>
                <p className="text-sm font-medium">
                    Nenhuma página com “{search.trim()}”
                </p>
                <p className="text-sm text-muted-foreground">
                    Procura pelo nome ou pelo endereço (ex.: /natal).
                </p>
            </div>
            <Button variant="outline" size="sm" onClick={onClear}>
                Limpar pesquisa
            </Button>
        </div>
    );
}

/** Sem maiúsculas nem acentos: "catalogo" encontra o "Catálogo". */
function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

function matches(page: PageRow, query: string): boolean {
    return (
        query === '' ||
        normalize(page.name).includes(query) ||
        normalize(page.path ?? '').includes(query)
    );
}

function CreateLandingDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        slug: '',
    });
    const [slugTouched, setSlugTouched] = useState(false);
    const address = data.slug
        .trim()
        .replace(/^\/+|\/+$/g, '')
        .toLowerCase();

    const close = (value: boolean) => {
        onOpenChange(value);

        if (!value) {
            reset();
            setSlugTouched(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        post(store().url);
                    }}
                    className="grid gap-4"
                >
                    <DialogHeader>
                        <DialogTitle>Nova landing page</DialogTitle>
                        <DialogDescription>
                            Nasce vazia e em rascunho: juntas-lhe blocos no
                            editor e publicas quando estiver pronta.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="landing-name">Nome</Label>
                        <Input
                            id="landing-name"
                            value={data.name}
                            maxLength={120}
                            placeholder="Natal 2026"
                            autoFocus
                            onChange={(event) => {
                                const name = event.target.value;
                                setData((current) => ({
                                    name,
                                    slug: slugTouched
                                        ? current.slug
                                        : slugify(name),
                                }));
                            }}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="landing-slug">Endereço</Label>
                        <div className="flex items-center gap-1">
                            <span className="text-sm text-muted-foreground">
                                /
                            </span>
                            <Input
                                id="landing-slug"
                                value={data.slug}
                                maxLength={80}
                                placeholder="natal-2026"
                                aria-describedby={
                                    address !== ''
                                        ? 'landing-address'
                                        : undefined
                                }
                                onChange={(event) => {
                                    setSlugTouched(true);
                                    setData('slug', event.target.value);
                                }}
                            />
                        </div>
                        {/* Como o servidor o grava: sem barras e em minúsculas. */}
                        {address !== '' && (
                            <p
                                id="landing-address"
                                className="truncate text-xs text-muted-foreground"
                            >
                                Fica em{' '}
                                <span className="font-mono">/{address}</span>
                            </p>
                        )}
                        <InputError message={errors.slug} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => close(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Criar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

PagesIndex.layout = {
    breadcrumbs: [{ title: 'Montra', href: '/admin/montra/paginas' }],
};
