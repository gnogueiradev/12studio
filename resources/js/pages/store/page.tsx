import { Head } from '@inertiajs/react';
import { FileWarning } from 'lucide-react';
import { BlockList } from '@/blocks';
import { useT } from '@/lib/i18n';
import type { StorePageData } from '@/types/store';

/**
 * Uma página da montra montada por blocos (App\PageBuilder). O conteúdo, a
 * ordem e o estilo vêm todos do servidor.
 */
export default function StorePage({ page }: { page: StorePageData }) {
    const t = useT();

    return (
        <>
            <Head title={page.title} />

            {page.draft && (
                <p
                    role="status"
                    className="flex items-center justify-center gap-2 bg-warning-soft px-4 py-2 text-center text-sm text-warning-soft-foreground"
                >
                    <FileWarning aria-hidden="true" className="size-4" />
                    {t('preview.draft')}
                </p>
            )}

            <BlockList blocks={page.blocks} />
        </>
    );
}
