import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ChevronDown, Printer } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { OrderProgress } from '@/components/admin/order-progress';
import { OrderTimeline } from '@/components/admin/order-timeline';
import {
    paymentTone,
    StatusBadge,
    StatusText,
} from '@/components/admin/status-badge';
import { TagInput } from '@/components/admin/tag-input';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { centsToInput, formatCents, inputToCents } from '@/lib/money';
import { label } from '@/lib/options';
import { cn } from '@/lib/utils';
import { index as clientes } from '@/routes/admin/clientes';
import {
    ajuste,
    detalhes,
    estado,
    index,
    pagamento,
} from '@/routes/admin/encomendas';
import { producao } from '@/routes/admin/itens';
import type { OrderDetail, OrderItemRow } from '@/types/order';
import {
    ORDER_STATUSES,
    nextProductionStatus,
    PAYMENT_METHODS,
    PAYMENT_STATUSES,
    PRODUCTION_STATUSES,
    SALES_CHANNELS,
} from '@/types/order';
import type { FlashToast } from '@/types/ui';

type Props = {
    order: OrderDetail;
    tagSuggestions: string[];
};

/** "Inês Alves" → "IA". */
function initials(name: string): string {
    const parts = name.trim().split(/\s+/);

    return (
        (parts[0]?.[0] ?? '') + (parts.length > 1 ? parts.at(-1)![0] : '')
    ).toUpperCase();
}

function Card({
    title,
    action,
    children,
    className,
}: {
    title: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'flex flex-col gap-3 rounded-xl border border-border/60 bg-card px-4 py-4',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2.5">
                <h2 className="text-sm font-semibold">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

/** O "Editar / Fechar" dos cartões laterais. */
function EditToggle({ open, onClick }: { open: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-expanded={open}
            className="text-[13px] font-medium underline-offset-4 hover:underline print:hidden"
        >
            {open ? 'Fechar' : 'Editar'}
        </button>
    );
}

function Row({ term, children }: { term: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-2.5 text-[13px]">
            <dt className="text-muted-foreground">{term}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

export default function OrdersShow({ order, tagSuggestions }: Props) {
    const isPaid = order.paymentStatus === 'paid';

    // Cancelada ou reembolsada não muda de valor. Espelha a guarda do
    // OrderService::setAdjustment — quem recusa continua a ser o servidor;
    // isto só evita mostrar um formulário que ia rebentar.
    const isClosed =
        order.status === 'cancelled' || order.status === 'refunded';

    const statusForm = useForm({
        status: '',
        note: '',
        force: false,
    });

    const [statusMenuOpen, setStatusMenuOpen] = useState(false);

    /*
     * O formulário enche-se a partir da encomenda de AGORA, cada vez que o
     * menu abre — e não uma vez só, quando a página monta. Era esse o bug do
     * "Avançar estado": o useForm guardava o primeiro estado disponível do
     * carregamento inicial; depois de avançar, a página recebia estados novos
     * mas o formulário continuava com o antigo, que já era o atual. O select
     * ficava em branco e voltar a aplicar pedia um estado onde a encomenda já
     * estava.
     */
    const openStatusMenu = () => {
        statusForm.clearErrors();
        statusForm.setData({
            status: order.availableStatuses[0] ?? '',
            note: '',
            force: false,
        });
        setStatusMenuOpen(true);
    };

    const applyStatus = () => {
        statusForm.patch(estado(order.id).url, {
            preserveScroll: true,
            onSuccess: () => setStatusMenuOpen(false),
        });
    };

    const paymentForm = useForm({
        payment_status: order.paymentStatus,
        payment_method: order.paymentMethod ?? '',
        note: '',
    });

    const adjustmentForm = useForm({
        adjustment_price: centsToInput(order.adjustmentCents),
        adjustment_reason: order.adjustmentReason ?? '',
    });

    const detailsForm = useForm({
        admin_note: order.adminNote ?? '',
        tracking_number: order.trackingNumber ?? '',
        tracking_url: order.trackingUrl ?? '',
        shipping_method_name: order.shippingMethodName ?? '',
        tags: order.tags,
    });

    const [editingPayment, setEditingPayment] = useState(false);
    const [editingShipping, setEditingShipping] = useState(false);

    // Total ao vivo enquanto se escreve, como no formulário de criação. O
    // servidor é que manda: isto é só para o admin ver onde vai parar antes
    // de gravar.
    const adjustedTotalCents =
        order.subtotalCents +
        order.shippingCents +
        inputToCents(adjustmentForm.data.adjustment_price);

    const forms = [paymentForm, adjustmentForm, detailsForm] as const;
    const dirty = forms.some((form) => form.isDirty);
    const saving = forms.some((form) => form.processing);

    /*
     * Um só "Guardar" para os três formulários, mas três pedidos: cada um tem
     * a sua rota e as suas regras no servidor. Vão em fila e param no
     * primeiro que falhe, para o erro aparecer ao lado do campo certo.
     *
     * O `setDefaults` depois de cada sucesso é a mesma lição do bug do estado:
     * sem ele o useForm continuava a comparar com os valores do carregamento
     * inicial e a barra de "alterações por guardar" nunca desaparecia.
     */
    const save = () => {
        const queue: (() => void)[] = [];
        const next = () => queue.shift()?.();

        const step = (form: (typeof forms)[number], url: string) => () =>
            form.patch(url, {
                preserveScroll: true,
                onSuccess: (page) => {
                    // As recusas do OrderService voltam como redirect com um
                    // toast de erro — para o Inertia isso é sucesso. Parar
                    // aqui deixa o formulário aberto e por guardar.
                    if (
                        (page.flash?.toast as FlashToast | undefined)?.type ===
                        'error'
                    ) {
                        return;
                    }

                    form.setDefaults();
                    next();
                },
            });

        if (paymentForm.isDirty) {
            queue.push(step(paymentForm, pagamento(order.id).url));
        }

        if (adjustmentForm.isDirty && !isClosed) {
            queue.push(step(adjustmentForm, ajuste(order.id).url));
        }

        if (detailsForm.isDirty) {
            queue.push(step(detailsForm, detalhes(order.id).url));
        }

        queue.push(() => {
            setEditingPayment(false);
            setEditingShipping(false);
        });
        next();
    };

    const discard = () => {
        // Um a um e não num forEach: os três têm campos diferentes e o
        // TypeScript não consegue chamar o `reset` da união.
        paymentForm.reset();
        paymentForm.clearErrors();
        adjustmentForm.reset();
        adjustmentForm.clearErrors();
        detailsForm.reset();
        detailsForm.clearErrors();
        setEditingPayment(false);
        setEditingShipping(false);
    };

    const [advancing, setAdvancing] = useState<number | null>(null);

    const advanceItem = (item: OrderItemRow) => {
        const next = nextProductionStatus(item.productionStatus);

        if (next === null) {
            return;
        }

        setAdvancing(item.id);
        router.patch(
            producao(item.id).url,
            { production_status: next },
            { preserveScroll: true, onFinish: () => setAdvancing(null) },
        );
    };

    const pieces = order.items.reduce((sum, item) => sum + item.qty, 0);
    const toProduce = order.items.filter(
        (item) =>
            item.productionStatus !== 'not_required' &&
            item.productionStatus !== 'ready',
    ).length;
    const vatRates = [...new Set(order.items.map((item) => item.vatRate))];

    const meta = [
        order.customerName,
        label(SALES_CHANNELS, order.salesChannel),
        order.createdAt,
        order.externalOrderReference
            ? `ref. ${order.externalOrderReference}`
            : null,
        order.createdBy ? `registada por ${order.createdBy}` : null,
    ].filter(Boolean);

    return (
        <>
            <Head title={`Encomenda ${order.orderNumber}`} />
            <div className="flex w-full max-w-[1320px] flex-1 flex-col gap-5 p-4 pb-10">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-col gap-1.5">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Encomenda {order.orderNumber}
                            </h1>
                            <StatusBadge
                                value={order.status}
                                label={label(ORDER_STATUSES, order.status)}
                            />
                        </div>
                        <p className="text-[13px] text-muted-foreground">
                            {meta.join(' · ')}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 print:hidden">
                        <Button
                            variant="outline"
                            className="rounded-full"
                            onClick={() => window.print()}
                        >
                            <Printer />
                            Imprimir
                        </Button>
                        {order.availableStatuses.length > 0 && (
                            <Button
                                className="rounded-full"
                                aria-expanded={statusMenuOpen}
                                onClick={() =>
                                    statusMenuOpen
                                        ? setStatusMenuOpen(false)
                                        : openStatusMenu()
                                }
                            >
                                Mudar estado
                                <ChevronDown />
                            </Button>
                        )}
                    </div>
                </div>

                {statusMenuOpen && order.availableStatuses.length > 0 && (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyStatus();
                        }}
                        className="flex flex-col gap-3 rounded-xl border border-border bg-card px-4 py-3.5 print:hidden"
                    >
                        <div className="flex flex-wrap items-end gap-2.5">
                            <div className="grid gap-1.5">
                                <Label
                                    htmlFor="next_status"
                                    className="text-xs font-normal text-muted-foreground"
                                >
                                    Novo estado
                                </Label>
                                <Select
                                    value={statusForm.data.status}
                                    onValueChange={(value) =>
                                        statusForm.setData('status', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="next_status"
                                        className="min-w-48"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {order.availableStatuses.map(
                                            (status) => (
                                                <SelectItem
                                                    key={status}
                                                    value={status}
                                                >
                                                    {label(
                                                        ORDER_STATUSES,
                                                        status,
                                                    )}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid min-w-56 flex-1 gap-1.5">
                                <Label
                                    htmlFor="status_note"
                                    className="text-xs font-normal text-muted-foreground"
                                >
                                    Nota (fica no histórico)
                                </Label>
                                <Input
                                    id="status_note"
                                    value={statusForm.data.note}
                                    onChange={(event) =>
                                        statusForm.setData(
                                            'note',
                                            event.target.value,
                                        )
                                    }
                                    placeholder={
                                        statusForm.data.force
                                            ? 'Obrigatória ao forçar'
                                            : 'Opcional'
                                    }
                                />
                            </div>
                            <Button
                                type="submit"
                                className="rounded-full"
                                disabled={
                                    statusForm.processing ||
                                    statusForm.data.status === ''
                                }
                            >
                                {statusForm.processing && <Spinner />}
                                Aplicar
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                className="rounded-full text-muted-foreground"
                                onClick={() => setStatusMenuOpen(false)}
                            >
                                Cancelar
                            </Button>
                        </div>
                        <InputError
                            message={
                                statusForm.errors.status ??
                                statusForm.errors.note
                            }
                        />
                        {!isPaid && (
                            <Label className="flex items-start gap-2 text-[13px] font-normal">
                                <Checkbox
                                    checked={statusForm.data.force}
                                    onCheckedChange={(checked) =>
                                        statusForm.setData(
                                            'force',
                                            checked === true,
                                        )
                                    }
                                />
                                <span>
                                    Avançar com o pagamento por regularizar —
                                    exige nota e fica registado com o meu nome.
                                </span>
                            </Label>
                        )}
                    </form>
                )}

                {order.stockIssue && (
                    <div className="flex items-center gap-2 rounded-xl border border-warning/50 bg-warning-soft p-4 text-sm text-warning-soft-foreground">
                        <AlertTriangle className="size-4 shrink-0" />O cliente
                        pagou mas o stock não pôde ser descontado. Resolver
                        manualmente antes de expedir.
                    </div>
                )}

                <OrderProgress steps={order.progress} />

                <div className="flex flex-wrap items-start gap-5">
                    <div className="flex min-w-0 flex-[1_1_430px] flex-col gap-5">
                        <section className="overflow-hidden rounded-xl border border-border/60 bg-card">
                            <div className="flex items-center justify-between gap-3 border-b border-border/60 px-4 py-3.5">
                                <h2 className="text-sm font-semibold">
                                    Artigos{' '}
                                    <span className="font-normal text-muted-foreground">
                                        · {order.items.length}{' '}
                                        {order.items.length === 1
                                            ? 'linha'
                                            : 'linhas'}
                                        , {pieces}{' '}
                                        {pieces === 1 ? 'peça' : 'peças'}
                                    </span>
                                </h2>
                                <span className="text-xs whitespace-nowrap text-muted-foreground">
                                    {toProduce === 0
                                        ? 'Nada por produzir'
                                        : `${toProduce} por produzir`}
                                    {vatRates.length === 1 &&
                                        ` · IVA ${vatRates[0]}%`}
                                </span>
                            </div>

                            {order.items.map((item) => {
                                const next = nextProductionStatus(
                                    item.productionStatus,
                                );

                                return (
                                    <div
                                        key={item.id}
                                        className="flex flex-wrap items-center gap-x-3.5 gap-y-1.5 border-b border-border/40 px-4 py-3 transition-colors hover:bg-secondary/40"
                                    >
                                        <div className="min-w-0 flex-[1_1_200px]">
                                            <p className="text-sm font-medium text-pretty">
                                                {item.productName}
                                                {item.variantLabel && (
                                                    <span className="font-normal text-muted-foreground">
                                                        {' '}
                                                        — {item.variantLabel}
                                                    </span>
                                                )}
                                            </p>
                                            <p className="flex flex-wrap gap-x-2 gap-y-1 text-[11px] text-muted-foreground tabular-nums">
                                                <span>
                                                    {item.sku ?? 'Sem SKU'}
                                                </span>
                                                <span>·</span>
                                                <span>
                                                    {item.qty} ×{' '}
                                                    {formatCents(
                                                        item.unitPriceCents,
                                                    )}
                                                </span>
                                                {vatRates.length > 1 && (
                                                    <>
                                                        <span>·</span>
                                                        <span>
                                                            IVA {item.vatRate}%
                                                        </span>
                                                    </>
                                                )}
                                            </p>

                                            {item.priceOverrideReason && (
                                                <p className="text-[11px] text-warning">
                                                    Preço alterado (catálogo{' '}
                                                    {formatCents(
                                                        item.catalogUnitPriceCents ??
                                                            0,
                                                    )}
                                                    ):{' '}
                                                    {item.priceOverrideReason}
                                                </p>
                                            )}

                                            {item.personalization.length >
                                                0 && (
                                                <dl className="mt-1 flex flex-wrap gap-x-4 text-xs">
                                                    {item.personalization.map(
                                                        (field) => (
                                                            <div
                                                                key={
                                                                    field.label
                                                                }
                                                                className="flex gap-1"
                                                            >
                                                                <dt className="text-muted-foreground">
                                                                    {
                                                                        field.label
                                                                    }
                                                                    :
                                                                </dt>
                                                                <dd>
                                                                    {
                                                                        field.value
                                                                    }
                                                                </dd>
                                                            </div>
                                                        ),
                                                    )}
                                                </dl>
                                            )}
                                        </div>

                                        <StatusBadge
                                            value={item.productionStatus}
                                            label={label(
                                                PRODUCTION_STATUSES,
                                                item.productionStatus,
                                            )}
                                        />
                                        {next !== null && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="rounded-full print:hidden"
                                                disabled={advancing === item.id}
                                                onClick={() =>
                                                    advanceItem(item)
                                                }
                                            >
                                                {advancing === item.id && (
                                                    <Spinner />
                                                )}
                                                →{' '}
                                                {label(
                                                    PRODUCTION_STATUSES,
                                                    next,
                                                )}
                                            </Button>
                                        )}
                                        <span className="min-w-[4.5rem] text-right text-sm font-medium tabular-nums">
                                            {formatCents(item.lineTotalCents)}
                                        </span>
                                    </div>
                                );
                            })}

                            <div className="flex justify-end px-4 py-3.5">
                                <dl className="flex w-full max-w-72 flex-col gap-2 tabular-nums">
                                    <Row term="Subtotal">
                                        {formatCents(order.subtotalCents)}
                                    </Row>
                                    <Row term="Portes">
                                        {formatCents(order.shippingCents)}
                                    </Row>
                                    {/*
                                     * Só aparece quando existe. Uma linha "Ajuste
                                     * 0,00 €" em todas as encomendas seria ruído
                                     * a fingir de informação.
                                     */}
                                    {order.adjustmentCents !== 0 && (
                                        <>
                                            <Row term="Ajuste manual">
                                                {order.adjustmentCents > 0 &&
                                                    '+'}
                                                {formatCents(
                                                    order.adjustmentCents,
                                                )}
                                            </Row>
                                            {order.adjustmentReason && (
                                                <p className="-mt-1 text-right text-[11px] text-muted-foreground">
                                                    {order.adjustmentReason}
                                                </p>
                                            )}
                                        </>
                                    )}
                                    <div className="flex items-baseline justify-between border-t border-border/60 pt-2">
                                        <dt className="text-[13px] font-semibold">
                                            Total
                                        </dt>
                                        <dd className="text-xl font-semibold tracking-tight">
                                            {formatCents(order.totalCents)}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </section>

                        <OrderTimeline entries={order.timeline} />
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-3.5 lg:sticky lg:top-5 lg:max-w-[332px]">
                        <section className="flex flex-col gap-3 rounded-xl border border-border/60 bg-card px-4 py-4">
                            <div className="flex items-center gap-3">
                                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-secondary text-xs font-semibold">
                                    {initials(order.customerName)}
                                </span>
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold">
                                        {order.customerName}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {order.email ??
                                            'Sem email · venda em mão'}
                                    </p>
                                </div>
                            </div>

                            {(order.phone || order.nif) && (
                                <p className="text-xs text-muted-foreground">
                                    {[
                                        order.phone,
                                        order.nif && `NIF ${order.nif}`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            )}

                            {order.customerId !== null && (
                                <div className="flex gap-2 print:hidden">
                                    {/*
                                     * A ficha do cliente não é uma página: o
                                     * `?editar` leva à listagem com o modal
                                     * aberto no cliente certo.
                                     */}
                                    <Link
                                        href={clientes({
                                            query: { editar: order.customerId },
                                        })}
                                        className="flex-1 rounded-lg border border-border/60 px-2.5 py-2 text-center text-[13px] transition-colors hover:bg-secondary/60"
                                    >
                                        Ver cliente
                                    </Link>
                                    <Link
                                        href={index({
                                            query: {
                                                search:
                                                    order.email ??
                                                    order.customerName,
                                            },
                                        })}
                                        className="flex-1 rounded-lg border border-border/60 px-2.5 py-2 text-center text-[13px] transition-colors hover:bg-secondary/60"
                                    >
                                        Encomendas ({order.customerOrdersCount})
                                    </Link>
                                </div>
                            )}
                        </section>

                        <Card
                            title="Pagamento"
                            action={
                                <EditToggle
                                    open={editingPayment}
                                    onClick={() =>
                                        setEditingPayment((open) => !open)
                                    }
                                />
                            }
                        >
                            {editingPayment ? (
                                <div className="flex flex-col gap-2.5">
                                    <div className="grid gap-1.5">
                                        <Label className="text-xs font-normal text-muted-foreground">
                                            Estado
                                        </Label>
                                        <Select
                                            value={
                                                paymentForm.data.payment_status
                                            }
                                            onValueChange={(value) =>
                                                paymentForm.setData(
                                                    'payment_status',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {PAYMENT_STATUSES.map(
                                                    (status) => (
                                                        <SelectItem
                                                            key={status.value}
                                                            value={status.value}
                                                        >
                                                            {status.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={
                                                paymentForm.errors
                                                    .payment_status
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label className="text-xs font-normal text-muted-foreground">
                                            Método
                                        </Label>
                                        <Select
                                            value={
                                                paymentForm.data
                                                    .payment_method || 'other'
                                            }
                                            onValueChange={(value) =>
                                                paymentForm.setData(
                                                    'payment_method',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {PAYMENT_METHODS.map(
                                                    (method) => (
                                                        <SelectItem
                                                            key={method.value}
                                                            value={method.value}
                                                        >
                                                            {method.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {!isClosed && (
                                        <>
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="adjustment_price"
                                                    className="text-xs font-normal text-muted-foreground"
                                                >
                                                    Ajuste ao total (€)
                                                </Label>
                                                <Input
                                                    id="adjustment_price"
                                                    inputMode="decimal"
                                                    className="tabular-nums"
                                                    value={
                                                        adjustmentForm.data
                                                            .adjustment_price
                                                    }
                                                    onChange={(event) =>
                                                        adjustmentForm.setData(
                                                            'adjustment_price',
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="-5,00"
                                                />
                                                <InputError
                                                    message={
                                                        adjustmentForm.errors
                                                            .adjustment_price
                                                    }
                                                />
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="adjustment_reason"
                                                    className="text-xs font-normal text-muted-foreground"
                                                >
                                                    Motivo do ajuste
                                                </Label>
                                                <Input
                                                    id="adjustment_reason"
                                                    value={
                                                        adjustmentForm.data
                                                            .adjustment_reason
                                                    }
                                                    onChange={(event) =>
                                                        adjustmentForm.setData(
                                                            'adjustment_reason',
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="Desconto acordado"
                                                />
                                                <InputError
                                                    message={
                                                        adjustmentForm.errors
                                                            .adjustment_reason
                                                    }
                                                />
                                            </div>

                                            <p className="text-xs text-muted-foreground">
                                                Total passa a{' '}
                                                <span className="font-medium text-foreground">
                                                    {formatCents(
                                                        adjustedTotalCents,
                                                    )}
                                                </span>
                                                . Negativo desconta, positivo
                                                acresce.
                                            </p>
                                        </>
                                    )}
                                </div>
                            ) : (
                                <dl className="flex flex-col gap-2">
                                    <Row term="Estado">
                                        <StatusText
                                            tone={paymentTone(
                                                order.paymentStatus,
                                            )}
                                            label={label(
                                                PAYMENT_STATUSES,
                                                order.paymentStatus,
                                            )}
                                            className="font-medium"
                                        />
                                    </Row>
                                    <Row term="Método">
                                        {order.paymentMethod
                                            ? label(
                                                  PAYMENT_METHODS,
                                                  order.paymentMethod,
                                              )
                                            : '—'}
                                    </Row>
                                    {order.paidAt && (
                                        <Row term="Confirmado">
                                            {order.paidAt}
                                        </Row>
                                    )}
                                </dl>
                            )}
                        </Card>

                        <Card
                            title="Envio e notas"
                            action={
                                <EditToggle
                                    open={editingShipping}
                                    onClick={() =>
                                        setEditingShipping((open) => !open)
                                    }
                                />
                            }
                        >
                            {editingShipping ? (
                                <div className="flex flex-col gap-2.5">
                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor="shipping_method_name"
                                            className="text-xs font-normal text-muted-foreground"
                                        >
                                            Método de envio
                                        </Label>
                                        <Input
                                            id="shipping_method_name"
                                            value={
                                                detailsForm.data
                                                    .shipping_method_name
                                            }
                                            onChange={(event) =>
                                                detailsForm.setData(
                                                    'shipping_method_name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor="tracking_number"
                                            className="text-xs font-normal text-muted-foreground"
                                        >
                                            Nº de seguimento
                                        </Label>
                                        <Input
                                            id="tracking_number"
                                            value={
                                                detailsForm.data.tracking_number
                                            }
                                            onChange={(event) =>
                                                detailsForm.setData(
                                                    'tracking_number',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Opcional"
                                        />
                                        <InputError
                                            message={
                                                detailsForm.errors
                                                    .tracking_number
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor="tracking_url"
                                            className="text-xs font-normal text-muted-foreground"
                                        >
                                            Link de seguimento
                                        </Label>
                                        <Input
                                            id="tracking_url"
                                            type="url"
                                            value={
                                                detailsForm.data.tracking_url
                                            }
                                            onChange={(event) =>
                                                detailsForm.setData(
                                                    'tracking_url',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Opcional"
                                        />
                                        <InputError
                                            message={
                                                detailsForm.errors.tracking_url
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor="admin_note"
                                            className="text-xs font-normal text-muted-foreground"
                                        >
                                            Nota interna
                                        </Label>
                                        <Textarea
                                            id="admin_note"
                                            value={detailsForm.data.admin_note}
                                            onChange={(event) =>
                                                detailsForm.setData(
                                                    'admin_note',
                                                    event.target.value,
                                                )
                                            }
                                            rows={3}
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor="tags"
                                            className="text-xs font-normal text-muted-foreground"
                                        >
                                            Etiquetas
                                        </Label>
                                        <TagInput
                                            id="tags"
                                            value={detailsForm.data.tags}
                                            onChange={(tags) =>
                                                detailsForm.setData(
                                                    'tags',
                                                    tags,
                                                )
                                            }
                                            suggestions={tagSuggestions}
                                        />
                                        <InputError
                                            message={detailsForm.errors.tags}
                                        />
                                    </div>
                                </div>
                            ) : (
                                <div className="flex flex-col gap-2.5">
                                    <dl className="flex flex-col gap-2">
                                        <Row term="Método">
                                            {order.shippingMethodName ?? '—'}
                                        </Row>
                                        <Row term="Seguimento">
                                            {order.trackingNumber === null ? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ) : order.trackingUrl ? (
                                                <a
                                                    href={order.trackingUrl}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="underline underline-offset-4"
                                                >
                                                    {order.trackingNumber}
                                                </a>
                                            ) : (
                                                order.trackingNumber
                                            )}
                                        </Row>
                                    </dl>

                                    {order.shippingAddress && (
                                        <address className="text-[13px] text-muted-foreground not-italic">
                                            {order.shippingAddress.line1}
                                            <br />
                                            {order.shippingAddress.line2 && (
                                                <>
                                                    {
                                                        order.shippingAddress
                                                            .line2
                                                    }
                                                    <br />
                                                </>
                                            )}
                                            {order.shippingAddress.postalCode}{' '}
                                            {order.shippingAddress.city}
                                            <br />
                                            {order.shippingAddress.country}
                                        </address>
                                    )}

                                    {order.adminNote && (
                                        <p className="rounded-r-lg border-l-2 border-border bg-secondary/50 px-3 py-2.5 text-[13px] whitespace-pre-line">
                                            {order.adminNote}
                                        </p>
                                    )}

                                    {order.tags.length > 0 && (
                                        <div className="flex flex-wrap gap-1.5">
                                            {order.tags.map((tag) => (
                                                <span
                                                    key={tag}
                                                    className="rounded-full border border-border/60 px-2.5 py-0.5 text-[11px]"
                                                >
                                                    {tag}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </Card>
                    </aside>
                </div>

                {dirty && (
                    <div className="sticky bottom-4 flex items-center gap-3.5 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-lg backdrop-blur print:hidden">
                        <span className="size-2 shrink-0 rounded-full bg-gold" />
                        <span className="flex-1 text-[13px]">
                            Tens alterações não guardadas nesta encomenda.
                        </span>
                        <Button
                            variant="ghost"
                            className="rounded-full text-muted-foreground"
                            disabled={saving}
                            onClick={discard}
                        >
                            Descartar
                        </Button>
                        <Button
                            className="rounded-full"
                            disabled={saving}
                            onClick={save}
                        >
                            {saving && <Spinner />}
                            Guardar alterações
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

OrdersShow.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Encomendas', href: index() },
        { title: 'Detalhe', href: '#' },
    ],
};
