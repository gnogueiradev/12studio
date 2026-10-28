import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Copy,
    ExternalLink,
    FileText,
    LayoutTemplate,
    Pencil,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { MontraNav } from '@/components/admin/montra/montra-nav';
import {
    formatDateTime,
    PageStatusBadge,
} from '@/components/admin/montra/page-status';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
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
import { slugify } from '@/lib/slug';
import { duplicar, edit, store } from '@/routes/admin/montra/paginas';
import type { PageRow } from '@/types/page-builder';

type Props = {
    system: PageRow[];
    landings: PageRow[];
};

/**
 * Montra → Páginas. As de sistema (o site precisa delas: não se apagam) e as
 * landing pages, cada uma com o seu endereço e, se quiseres, datas.
 */
export default function PagesIndex({ system, landings }: Props) {
    const [creating, setCreating] = useState(false);

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

                <Panel
                    title="Páginas do site"
                    description="Fazem parte do site: podes mudar tudo o que têm, mas não apagá-las. O catálogo e a página de produto são modelos — o mesmo layout serve todas as categorias e todos os produtos."
                >
                    <PageList pages={system} />
                </Panel>

                <Panel
                    title="Landing pages"
                    description="Páginas tuas, com endereço próprio (ex.: /natal) e, se quiseres, datas de início e fim."
                >
                    {landings.length === 0 ? (
                        <div className="flex flex-col items-start gap-3 py-2 text-sm text-muted-foreground">
                            <p>
                                Ainda não há landing pages. Servem para
                                campanhas, datas especiais ou coleções.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => setCreating(true)}
                            >
                                <Plus />
                                Criar a primeira
                            </Button>
                        </div>
                    ) : (
                        <PageList pages={landings} />
                    )}
                </Panel>
            </div>

            <CreateLandingDialog open={creating} onOpenChange={setCreating} />
        </>
    );
}

function PageList({ pages }: { pages: PageRow[] }) {
    return (
        <ul className="divide-y divide-border/60">
            {pages.map((page) => (
                <li
                    key={page.id}
                    className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3"
                >
                    <div className="flex min-w-0 flex-1 items-center gap-3">
                        {page.template === 'page' ? (
                            <FileText
                                aria-hidden
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                        ) : (
                            <LayoutTemplate
                                aria-hidden
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                        )}
                        <div className="min-w-0">
                            <Link
                                href={edit(page.id)}
                                className="font-medium hover:underline"
                            >
                                {page.name}
                            </Link>
                            <p className="truncate text-sm text-muted-foreground">
                                {page.template === 'product'
                                    ? 'Modelo de todos os produtos'
                                    : page.template === 'catalog'
                                      ? 'Modelo do catálogo e das categorias'
                                      : page.path}
                                {page.startsAt || page.endsAt ? (
                                    <>
                                        {' · '}
                                        {page.startsAt
                                            ? formatDateTime(page.startsAt)
                                            : '…'}
                                        {' → '}
                                        {page.endsAt
                                            ? formatDateTime(page.endsAt)
                                            : '…'}
                                    </>
                                ) : null}
                            </p>
                        </div>
                    </div>
                    <PageStatusBadge page={page} />
                    <div className="flex items-center gap-1">
                        {page.path && page.live && (
                            <Button variant="ghost" size="icon" asChild>
                                <a
                                    href={page.path}
                                    target="_blank"
                                    rel="noreferrer"
                                    aria-label={`Ver ${page.name} no site`}
                                >
                                    <ExternalLink />
                                </a>
                            </Button>
                        )}
                        {page.template === 'page' && (
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={`Duplicar ${page.name}`}
                                onClick={() => router.post(duplicar(page.id))}
                            >
                                <Copy />
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={edit(page.id)}>
                                <Pencil />
                                Editar
                            </Link>
                        </Button>
                    </div>
                </li>
            ))}
        </ul>
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
                                onChange={(event) => {
                                    setSlugTouched(true);
                                    setData('slug', event.target.value);
                                }}
                            />
                        </div>
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
