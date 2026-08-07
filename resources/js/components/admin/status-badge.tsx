import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type Tone = 'neutral' | 'info' | 'warning' | 'success' | 'danger';

const TONE_CLASSES: Record<Tone, string> = {
    neutral: 'bg-muted text-muted-foreground border-transparent',
    info: 'bg-sky-100 text-sky-800 border-transparent dark:bg-sky-950 dark:text-sky-200',
    warning:
        'bg-amber-100 text-amber-900 border-transparent dark:bg-amber-950 dark:text-amber-200',
    success:
        'bg-emerald-100 text-emerald-900 border-transparent dark:bg-emerald-950 dark:text-emerald-200',
    danger: 'bg-red-100 text-red-900 border-transparent dark:bg-red-950 dark:text-red-200',
};

/**
 * Tom por valor de estado. As três famílias vivem juntas de propósito:
 * numa encomenda vê-se sempre o estado de fulfilment ao lado do estado de
 * pagamento, e os dois nunca se podem confundir à vista.
 */
const TONES: Record<string, Tone> = {
    // orders.status
    pending_payment: 'warning',
    paid: 'info',
    in_production: 'info',
    ready_to_ship: 'info',
    shipped: 'success',
    delivered: 'success',
    cancelled: 'danger',
    refunded: 'danger',
    // orders.payment_status (paid/refunded partilham chave com o de cima —
    // o tom coincide, por isso um mapa único chega)
    pending: 'warning',
    partially_refunded: 'warning',
    failed: 'danger',
    // order_items.production_status
    not_required: 'neutral',
    awaiting_production: 'warning',
    printing: 'info',
    quality_check: 'info',
    ready: 'success',
};

type Props = {
    value: string;
    label: string;
    className?: string;
};

export function StatusBadge({ value, label, className }: Props) {
    return (
        <Badge
            variant="outline"
            className={cn(TONE_CLASSES[TONES[value] ?? 'neutral'], className)}
        >
            {label}
        </Badge>
    );
}
