import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { StoreBlock } from '@/types/store';

type Data = { items: { label: string; href: string | null }[] };

export function BreadcrumbBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const t = useT();

    return (
        <div className="mx-auto w-full max-w-6xl px-4 pt-6">
            <nav aria-label={t('breadcrumb')}>
                <ol className="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
                    {data.items.map((item, index) => (
                        <li key={index} className="flex items-center gap-1.5">
                            {index > 0 && (
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            )}
                            {item.href ? (
                                <Link
                                    href={item.href}
                                    className="transition-colors hover:text-foreground"
                                >
                                    {item.label}
                                </Link>
                            ) : (
                                <span
                                    aria-current="page"
                                    className="text-foreground"
                                >
                                    {item.label}
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
            </nav>
        </div>
    );
}
