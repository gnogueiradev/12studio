import { ChevronDown, Search, ShoppingBag } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { hasErrors, labelOf } from './menu-model';
import type { MenuErrors, MenuItem, MenuLocation } from './menu-model';

type Props = {
    location: MenuLocation;
    items: MenuItem[];
    locale: string;
    selected: string | null;
    errors: MenuErrors;
    onSelect: (uid: string) => void;
};

/**
 * Um esboço do menu como fica na loja, desenhado com divs. Não é a montra a
 * sério — serve para te situares e para escolher o item a editar.
 */
export function MenuPreview(props: Props) {
    return props.location === 'header' ? (
        <HeaderPreview {...props} />
    ) : (
        <FooterPreview {...props} />
    );
}

function HeaderPreview({ items, locale, selected, errors, onSelect }: Props) {
    // O submenu abre por baixo do item escolhido (ou do pai do link escolhido).
    const open = items.find(
        (item) =>
            item.children.length > 0 &&
            (item.uid === selected ||
                item.children.some((child) => child.uid === selected)),
    );

    return (
        <div className="rounded-lg border border-border/60 bg-background">
            <div className="flex items-center gap-4 border-b border-border/60 px-4 py-3">
                <span
                    aria-hidden
                    className="grid h-7 shrink-0 place-items-center rounded-md bg-muted px-2 text-[10px] font-semibold tracking-wider text-muted-foreground"
                >
                    LOGO
                </span>
                <nav
                    aria-label="Pré-visualização do menu do topo"
                    className="flex min-w-0 flex-1 flex-wrap items-center gap-1"
                >
                    {items.length === 0 && <EmptyNote />}
                    {items.map((item) => (
                        <div key={item.uid} className="relative">
                            <PreviewButton
                                item={item}
                                locale={locale}
                                selected={selected === item.uid}
                                error={hasErrors(errors, item.uid)}
                                onSelect={onSelect}
                                className="px-2.5 py-1.5 text-sm"
                            >
                                {item.children.length > 0 && (
                                    <ChevronDown
                                        aria-label="tem submenu"
                                        className="size-3.5 text-muted-foreground"
                                    />
                                )}
                            </PreviewButton>
                            {open?.uid === item.uid && (
                                <div className="absolute top-full left-0 z-10 mt-1 grid min-w-44 gap-0.5 rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md">
                                    {item.children.map((child) => (
                                        <PreviewButton
                                            key={child.uid}
                                            item={child}
                                            locale={locale}
                                            selected={selected === child.uid}
                                            error={hasErrors(errors, child.uid)}
                                            onSelect={onSelect}
                                            className="px-2 py-1.5 text-sm"
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </nav>
                <div
                    aria-hidden
                    className="hidden shrink-0 items-center gap-3 text-muted-foreground sm:flex"
                >
                    <Search className="size-4" />
                    <ShoppingBag className="size-4" />
                </div>
            </div>
            {/* Um bocado de página a fingir, para o submenu ter onde abrir. */}
            <div
                aria-hidden
                className="grid content-start gap-2 px-4 py-5"
                style={{
                    minHeight: open
                        ? open.children.length * 34 + 32
                        : undefined,
                }}
            >
                <div className="h-3 w-1/3 rounded bg-muted" />
                <div className="h-2 w-2/3 rounded bg-muted/70" />
                <div className="h-2 w-1/2 rounded bg-muted/70" />
            </div>
        </div>
    );
}

function FooterPreview({ items, locale, selected, errors, onSelect }: Props) {
    return (
        <div className="rounded-lg border border-border/60 bg-muted/40 px-4 py-5">
            {items.length === 0 ? (
                <EmptyNote />
            ) : (
                <div className="grid gap-6 sm:grid-cols-[repeat(auto-fit,minmax(9rem,1fr))]">
                    {items.map((column) => (
                        <div
                            key={column.uid}
                            className="grid content-start justify-items-start gap-1"
                        >
                            <PreviewButton
                                item={column}
                                locale={locale}
                                selected={selected === column.uid}
                                error={hasErrors(errors, column.uid)}
                                onSelect={onSelect}
                                className="mb-1 px-1.5 py-1 text-sm font-semibold"
                            />
                            {column.children.map((link) => (
                                <PreviewButton
                                    key={link.uid}
                                    item={link}
                                    locale={locale}
                                    selected={selected === link.uid}
                                    error={hasErrors(errors, link.uid)}
                                    onSelect={onSelect}
                                    className="px-1.5 py-0.5 text-xs text-muted-foreground"
                                />
                            ))}
                        </div>
                    ))}
                </div>
            )}
            <div
                aria-hidden
                className="mt-5 border-t border-border/60 pt-3 text-xs text-muted-foreground"
            >
                © A tua loja
            </div>
        </div>
    );
}

function PreviewButton({
    item,
    locale,
    selected,
    error,
    onSelect,
    className,
    children,
}: {
    item: MenuItem;
    locale: string;
    selected: boolean;
    error: boolean;
    onSelect: (uid: string) => void;
    className?: string;
    children?: ReactNode;
}) {
    const text = labelOf(item, locale);

    return (
        <button
            type="button"
            aria-pressed={selected}
            onClick={() => onSelect(item.uid)}
            className={cn(
                'flex items-center gap-1 rounded-md text-left transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                className,
                selected && 'bg-accent text-accent-foreground ring-2 ring-ring',
                error && 'text-destructive',
            )}
        >
            {error && (
                <span
                    aria-hidden
                    className="size-1.5 shrink-0 rounded-full bg-destructive"
                />
            )}
            {text || (
                <span className="text-muted-foreground italic">Sem texto</span>
            )}
            {children}
        </button>
    );
}

function EmptyNote() {
    return (
        <p className="text-sm text-muted-foreground">
            O menu está vazio — acrescenta o primeiro item em baixo.
        </p>
    );
}
