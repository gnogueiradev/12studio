import { Link, router } from '@inertiajs/react';
import {
    CalendarClock,
    Copy,
    ExternalLink,
    LayoutTemplate,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
} from 'lucide-react';
import {
    formatDateTime,
    PageStatusBadge,
} from '@/components/admin/montra/page-status';
import { PageThumbnail } from '@/components/admin/montra/pages/page-thumbnail';
import {
    formatRelative,
    useNow,
} from '@/components/admin/montra/pages/relative-time';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { duplicar, edit } from '@/routes/admin/montra/paginas';
import type { PageRow } from '@/types/page-builder';

const TEMPLATE_HINTS = {
    product: 'Modelo de todos os produtos',
    catalog: 'Modelo do catálogo e das categorias',
} as const;

const TEMPLATE_TIPS = {
    product: 'O mesmo layout serve todos os produtos',
    catalog: 'O mesmo layout serve o catálogo e todas as categorias',
} as const;

/**
 * Um cartão por página: a miniatura, o nome, o endereço, o estado e quando
 * se mexeu nela. O cartão inteiro abre o editor (o link estica-se por cima
 * dele); o "…" fica por cima do link, com o resto das ações.
 */
export function PageCard({ page }: { page: PageRow }) {
    const now = useNow();
    const hint =
        page.template === 'page' ? page.path : TEMPLATE_HINTS[page.template];

    return (
        <article className="group relative flex flex-col overflow-hidden rounded-xl border border-border/60 bg-card transition-[border-color,box-shadow] hover:border-border hover:shadow-md has-[a:focus-visible]:border-ring has-[a:focus-visible]:ring-[3px] has-[a:focus-visible]:ring-ring/50">
            <div className="relative border-b border-border/60">
                <PageThumbnail pageId={page.id} title={page.name} />
                <div className="pointer-events-none absolute inset-0 bg-foreground/0 transition-colors group-hover:bg-foreground/5" />
                <KindBadge page={page} />
            </div>

            <div className="flex flex-1 flex-col gap-2 p-3">
                <div className="flex items-start gap-2">
                    <div className="min-w-0 flex-1">
                        <h3 className="truncate text-sm font-medium">
                            <Link
                                href={edit(page.id)}
                                className="outline-none after:absolute after:inset-0 after:content-['']"
                            >
                                {page.name}
                            </Link>
                        </h3>
                        <p className="truncate text-xs text-muted-foreground">
                            {hint}
                        </p>
                    </div>
                    <PageActions page={page} />
                </div>

                <div className="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <PageStatusBadge page={page} />
                    {page.updatedAt && now !== null && (
                        <time
                            dateTime={page.updatedAt}
                            title={formatDateTime(page.updatedAt)}
                        >
                            Editada {formatRelative(page.updatedAt, now)}
                        </time>
                    )}
                </div>

                {(page.startsAt || page.endsAt) && (
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <CalendarClock aria-hidden className="size-3.5" />
                        <span className="truncate">
                            {page.startsAt
                                ? formatDateTime(page.startsAt)
                                : '…'}
                            {' → '}
                            {page.endsAt ? formatDateTime(page.endsAt) : '…'}
                        </span>
                    </p>
                )}
            </div>
        </article>
    );
}

/** O canto da miniatura: "Modelo" nos modelos, um cadeado nas do site. */
function KindBadge({ page }: { page: PageRow }) {
    if (page.type !== 'system') {
        return null;
    }

    const template = page.template === 'page' ? null : page.template;
    const Icon = template ? LayoutTemplate : Lock;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="absolute top-2 left-2 z-10 inline-flex items-center gap-1 rounded-md border border-border/60 bg-card/90 px-1.5 py-0.5 text-xs font-medium text-foreground shadow-sm backdrop-blur-sm">
                    <Icon aria-hidden className="size-3" />
                    {template ? 'Modelo' : 'Do site'}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {template
                    ? TEMPLATE_TIPS[template]
                    : 'Página do site — não se apaga'}
            </TooltipContent>
        </Tooltip>
    );
}

function PageActions({ page }: { page: PageRow }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative z-10 -mt-1 -mr-1 size-8 shrink-0 text-muted-foreground"
                    aria-label={`Mais ações para ${page.name}`}
                    onClick={(event) => event.stopPropagation()}
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuItem asChild>
                    <Link href={edit(page.id)}>
                        <Pencil />
                        Editar
                    </Link>
                </DropdownMenuItem>
                {page.path && page.live && (
                    <DropdownMenuItem asChild>
                        <a href={page.path} target="_blank" rel="noreferrer">
                            <ExternalLink />
                            Ver no site
                        </a>
                    </DropdownMenuItem>
                )}
                {page.template === 'page' && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => router.post(duplicar(page.id))}
                        >
                            <Copy />
                            Duplicar como landing
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * O primeiro mosaico das landing pages, tracejado: abre o "Nova landing
 * page". Sem landings, é ele o estado vazio.
 */
export function NewLandingTile({ onClick }: { onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="group flex min-h-56 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border p-6 text-center transition-colors outline-none hover:border-ring hover:bg-muted/50 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <span className="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground transition-colors group-hover:bg-primary group-hover:text-primary-foreground">
                <Plus aria-hidden className="size-5" />
            </span>
            <span className="text-sm font-medium">Nova landing page</span>
            <span className="max-w-56 text-xs text-muted-foreground">
                Campanhas, datas especiais ou coleções, com endereço próprio.
            </span>
        </button>
    );
}
