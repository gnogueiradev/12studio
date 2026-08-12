import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type Tone = 'neutral' | 'info' | 'warning' | 'success' | 'danger';

const TONE_CLASSES: Record<Tone, string> = {
    neutral: 'bg-muted text-muted-foreground border-transparent',
    info: 'bg-info-soft text-info-soft-foreground border-transparent',
    warning: 'bg-warning-soft text-warning-soft-foreground border-transparent',
    success: 'bg-success-soft text-success-soft-foreground border-transparent',
    danger: 'bg-destructive-soft text-destructive-soft-foreground border-transparent',
};

/**
 * Tom por valor de estado. As três famílias vivem juntas de propósito:
 * numa encomenda vê-se sempre o estado de fulfilment ao lado do estado de
 * pagamento, e os dois nunca se podem confundir à vista.
 *
 * Exportado porque o painel do backoffice pinta as barras da distribuição por
 * estado com os mesmos tons — mas com preenchimentos sólidos, não com os pares
 * `*-soft` do badge. O mapa é a única fonte de "que tom tem este estado"; cada
 * sítio decide como o desenha.
 */
export const TONES: Record<string, Tone> = {
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
