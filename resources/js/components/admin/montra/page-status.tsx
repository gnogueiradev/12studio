import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { PageRow } from '@/types/page-builder';

const dateTime = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

export function formatDateTime(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '';
}

/**
 * O estado de uma página numa pastilha: rascunho, publicada (com ou sem
 * alterações por publicar), agendada ou fora do ar.
 */
export function PageStatusBadge({
    page,
    className,
}: {
    page: Pick<
        PageRow,
        'status' | 'hasChanges' | 'live' | 'startsAt' | 'endsAt'
    >;
    className?: string;
}) {
    const { label, tone } = describe(page);

    return (
        <Badge
            variant="outline"
            className={cn('border-transparent', tone, className)}
        >
            {label}
        </Badge>
    );
}

function describe(
    page: Pick<PageRow, 'status' | 'hasChanges' | 'live' | 'startsAt'>,
): { label: string; tone: string } {
    if (page.status === 'draft') {
        return { label: 'Rascunho', tone: 'bg-muted text-muted-foreground' };
    }

    if (page.status === 'unpublished') {
        return {
            label: 'Fora do ar',
            tone: 'bg-muted text-muted-foreground',
        };
    }

    if (!page.live) {
        const future = page.startsAt && new Date(page.startsAt) > new Date();

        return {
            label: future ? 'Agendada' : 'Terminada',
            tone: 'bg-info-soft text-info-soft-foreground',
        };
    }

    return page.hasChanges
        ? {
              label: 'Alterações por publicar',
              tone: 'bg-warning-soft text-warning-soft-foreground',
          }
        : {
              label: 'Publicada',
              tone: 'bg-success-soft text-success-soft-foreground',
          };
}
