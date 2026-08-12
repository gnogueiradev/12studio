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

/** Os mesmos tons sem pastilha, para texto solto numa célula. */
const TONE_TEXT_CLASSES: Record<Tone, string> = {
    neutral: 'text-muted-foreground',
    info: 'text-info',
    warning: 'text-warning',
    success: 'text-success',
    danger: 'text-destructive',
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
    /*
     * orders.status — o tom diz o que o estado pede a quem esta do lado de ca,
     * nao o quao avancado esta o pipeline:
     *   neutral  nada a fazer, espera-se outra pessoa (o cliente que pague, a
     *            transportadora que entregue)
     *   info     o dinheiro entrou, ha trabalho para agendar
     *   warning  esta na bancada agora
     *   success  feito deste lado
     * Antes disto era info para tudo o que estivesse a meio, o que punha
     * "pagamento confirmado", "em producao" e "pronto a enviar" com o mesmo
     * aspeto — tres coisas com urgencias diferentes.
     */
    pending_payment: 'neutral',
    paid: 'info',
    in_production: 'warning',
    ready_to_ship: 'success',
    shipped: 'neutral',
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

/**
 * Único ponto onde `orders.payment_status` diverge do mapa acima. Na coluna de
 * pagamento, "Pago" é o fim da linha — o dinheiro entrou — e pinta-se de
 * sucesso; o mesmo `paid` em `orders.status` ("Pagamento confirmado") é só a
 * primeira etapa do pipeline e continua em info. Ver os dois tons lado a lado
 * na mesma linha é o que distingue as duas colunas à vista.
 */
export function paymentTone(value: string): Tone {
    return value === 'paid' ? 'success' : (TONES[value] ?? 'neutral');
}

type Props = {
    value: string;
    label: string;
    className?: string;
};

export function StatusBadge({ value, label, className }: Props) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'rounded-full',
                TONE_CLASSES[TONES[value] ?? 'neutral'],
                className,
            )}
        >
            {label}
        </Badge>
    );
}

/**
 * Estado em texto colorido, sem pastilha. Vive ao lado do `StatusBadge` numa
 * listagem: a pastilha marca o estado da encomenda, o texto marca o do
 * pagamento, e a diferença de forma chega para não os confundir.
 */
export function StatusText({
    tone,
    label,
    className,
}: {
    tone: Tone;
    label: string;
    className?: string;
}) {
    return (
        <span className={cn(TONE_TEXT_CLASSES[tone], className)}>{label}</span>
    );
}
