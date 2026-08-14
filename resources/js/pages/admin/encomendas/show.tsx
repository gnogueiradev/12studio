import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/admin/page-header';
import { StatusBadge } from '@/components/admin/status-badge';
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

type Props = {
    order: OrderDetail;
    tagSuggestions: string[];
};

export default function OrdersShow({ order, tagSuggestions }: Props) {
    const isPaid = order.paymentStatus === 'paid';

    const statusForm = useForm<{
        status: string;
        note: string;
        force: boolean;
    }>({
        status: order.availableStatuses[0] ?? '',
        note: '',
        force: false,
    });

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

    // Cancelada ou reembolsada não muda de valor. Espelha a guarda do
    // OrderService::setAdjustment — quem recusa continua a ser o servidor;
    // isto só evita mostrar um formulário que ia rebentar.
    const isClosed =
        order.status === 'cancelled' || order.status === 'refunded';

    // Total ao vivo enquanto se escreve, como no formulário de criação. O
    // servidor é que manda: isto é só para o admin ver onde vai parar antes
    // de gravar.
    const adjustedTotalCents =
        order.subtotalCents +
        order.shippingCents +
        inputToCents(adjustmentForm.data.adjustment_price);

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

    return (
        <>
            <Head title={`Encomenda ${order.orderNumber}`} />
            <div className="flex h-full flex-1 flex-col gap-8 p-4">
                <PageHeader
                    title={`Encomenda ${order.orderNumber}`}
                    description={`${label(SALES_CHANNELS, order.salesChannel)} · ${order.createdAt ?? ''}${
                        order.externalOrderReference
                            ? ` · ref. ${order.externalOrderReference}`
                            : ''
                    }`}
                >
                    <StatusBadge
                        value={order.status}
                        label={label(ORDER_STATUSES, order.status)}
                    />
                    <StatusBadge
                        value={order.paymentStatus}
                        label={`Pagamento: ${label(PAYMENT_STATUSES, order.paymentStatus)}`}
                    />
                </PageHeader>

                {order.stockIssue && (
                    <div className="flex items-center gap-2 rounded-xl border border-warning/50 bg-warning-soft p-4 text-sm text-warning-soft-foreground">
                        <AlertTriangle className="size-4 shrink-0" />O cliente
                        pagou mas o stock não pôde ser descontado. Resolver
                        manualmente antes de expedir.
                    </div>
                )}

                <div className="grid gap-8 lg:grid-cols-3">
                    <div className="flex flex-col gap-8 lg:col-span-2">
                        <section className="flex flex-col gap-4">
                            <h2 className="text-lg font-semibold">Artigos</h2>
                            <div className="flex flex-col gap-3">
                                {order.items.map((item) => {
                                    const next = nextProductionStatus(
                                        item.productionStatus,
                                    );

                                    return (
                                        <div
                                            key={item.id}
                                            className="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border/60 p-4"
                                        >
                                            <div className="flex flex-col gap-1">
                                                <div className="font-medium">
                                                    {item.productName}
                                                    {item.variantLabel && (
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            —{' '}
                                                            {item.variantLabel}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {item.sku ?? 'Sem SKU'} ·{' '}
                                                    {item.qty} ×{' '}
                                                    {formatCents(
                                                        item.unitPriceCents,
                                                    )}{' '}
                                                    · IVA {item.vatRate}%
                                                </div>

                                                {item.priceOverrideReason && (
                                                    <div className="text-xs text-warning">
                                                        Preço alterado (catálogo{' '}
                                                        {formatCents(
                                                            item.catalogUnitPriceCents ??
                                                                0,
                                                        )}
                                                        ):{' '}
                                                        {
                                                            item.priceOverrideReason
                                                        }
                                                    </div>
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

                                            <div className="flex items-center gap-3">
                                                <StatusBadge
                                                    value={
                                                        item.productionStatus
                                                    }
                                                    label={label(
                                                        PRODUCTION_STATUSES,
                                                        item.productionStatus,
                                                    )}
                                                />
                                                {next !== null && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={
                                                            advancing ===
                                                            item.id
                                                        }
                                                        onClick={() =>
                                                            advanceItem(item)
                                                        }
                                                    >
                                                        →{' '}
                                                        {label(
                                                            PRODUCTION_STATUSES,
                                                            next,
                                                        )}
                                                    </Button>
                                                )}
                                                <span className="w-24 text-right font-medium">
                                                    {formatCents(
                                                        item.lineTotalCents,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            <dl className="ml-auto flex w-56 flex-col gap-1 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        Subtotal
                                    </dt>
                                    <dd>{formatCents(order.subtotalCents)}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        Portes
                                    </dt>
                                    <dd>{formatCents(order.shippingCents)}</dd>
                                </div>
                                {/*
                                 * Só aparece quando existe. Uma linha "Ajuste
                                 * 0,00 €" em todas as encomendas seria ruído a
                                 * fingir de informação — e o total já bate
                                 * certo com o subtotal mais os portes.
                                 */}
                                {order.adjustmentCents !== 0 && (
                                    <div className="flex flex-col gap-0.5">
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">
                                                Ajuste
                                            </dt>
                                            <dd>
                                                {formatCents(
                                                    order.adjustmentCents,
                                                )}
                                            </dd>
                                        </div>
                                        {order.adjustmentReason !== null && (
                                            <dd className="text-xs text-muted-foreground">
                                                {order.adjustmentReason}
                                            </dd>
                                        )}
                                    </div>
                                )}
                                <div className="flex justify-between border-t border-border/60 pt-1 font-medium">
                                    <dt>Total</dt>
                                    <dd>{formatCents(order.totalCents)}</dd>
                                </div>
                            </dl>
                        </section>

                        <section className="flex flex-col gap-4 border-t border-border/60 pt-8">
                            <h2 className="text-lg font-semibold">Histórico</h2>
                            {order.timeline.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Sem movimentos registados.
                                </p>
                            ) : (
                                <ol className="flex flex-col gap-3">
                                    {order.timeline.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="flex flex-col gap-1 border-l-2 border-border/60 pl-4 text-sm"
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-muted-foreground">
                                                    {entry.at}
                                                </span>
                                                <StatusBadge
                                                    value={entry.toStatus}
                                                    label={label(
                                                        entry.kind === 'order'
                                                            ? ORDER_STATUSES
                                                            : PRODUCTION_STATUSES,
                                                        entry.toStatus,
                                                    )}
                                                />
                                                {entry.subject && (
                                                    <span className="text-muted-foreground">
                                                        {entry.subject}
                                                    </span>
                                                )}
                                            </div>
                                            {entry.note && <p>{entry.note}</p>}
                                            {entry.author && (
                                                <p className="text-xs text-muted-foreground">
                                                    por {entry.author}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    </div>

                    <aside className="flex flex-col gap-8">
                        <section className="flex flex-col gap-2 text-sm">
                            <h2 className="text-lg font-semibold">Cliente</h2>
                            <div>
                                {order.customerId !== null ? (
                                    /*
                                     * A ficha do cliente já não é uma página: o
                                     * `?editar` leva à listagem com o modal
                                     * aberto no cliente certo.
                                     */
                                    <Link
                                        href={clientes({
                                            query: { editar: order.customerId },
                                        })}
                                        className="font-medium hover:underline"
                                    >
                                        {order.customerName}
                                    </Link>
                                ) : (
                                    <span className="font-medium">
                                        {order.customerName}
                                    </span>
                                )}
                            </div>
                            <div className="text-muted-foreground">
                                {order.email ?? 'Sem email — venda em mão'}
                            </div>
                            {order.phone && (
                                <div className="text-muted-foreground">
                                    {order.phone}
                                </div>
                            )}
                            {order.nif && (
                                <div className="text-muted-foreground">
                                    NIF {order.nif}
                                </div>
                            )}

                            {order.shippingAddress && (
                                <address className="mt-2 text-muted-foreground not-italic">
                                    {order.shippingAddress.line1}
                                    <br />
                                    {order.shippingAddress.line2 && (
                                        <>
                                            {order.shippingAddress.line2}
                                            <br />
                                        </>
                                    )}
                                    {order.shippingAddress.postalCode}{' '}
                                    {order.shippingAddress.city}
                                    <br />
                                    {order.shippingAddress.country}
                                </address>
                            )}

                            {order.createdBy && (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Registada por {order.createdBy}
                                </p>
                            )}
                        </section>

                        {order.availableStatuses.length > 0 && (
                            <section className="flex flex-col gap-3 border-t border-border/60 pt-8">
                                <h2 className="text-lg font-semibold">
                                    Avançar estado
                                </h2>
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        statusForm.patch(estado(order.id).url, {
                                            preserveScroll: true,
                                        });
                                    }}
                                    className="flex flex-col gap-3"
                                >
                                    <Select
                                        value={statusForm.data.status}
                                        onValueChange={(value) =>
                                            statusForm.setData('status', value)
                                        }
                                    >
                                        <SelectTrigger>
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

                                    <Textarea
                                        value={statusForm.data.note}
                                        onChange={(event) =>
                                            statusForm.setData(
                                                'note',
                                                event.target.value,
                                            )
                                        }
                                        rows={2}
                                        placeholder="Nota (fica no histórico)"
                                    />
                                    <InputError
                                        message={statusForm.errors.note}
                                    />

                                    {!isPaid && (
                                        <Label className="flex items-start gap-2 text-sm font-normal">
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
                                                Avançar com o pagamento por
                                                regularizar — exige nota e fica
                                                registado com o meu nome.
                                            </span>
                                        </Label>
                                    )}

                                    <Button
                                        type="submit"
                                        disabled={statusForm.processing}
                                    >
                                        {statusForm.processing && <Spinner />}
                                        Aplicar
                                    </Button>
                                </form>
                            </section>
                        )}

                        <section className="flex flex-col gap-3 border-t border-border/60 pt-8">
                            <h2 className="text-lg font-semibold">Pagamento</h2>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    paymentForm.patch(pagamento(order.id).url, {
                                        preserveScroll: true,
                                    });
                                }}
                                className="flex flex-col gap-3"
                            >
                                <div className="grid gap-2">
                                    <Label>Estado</Label>
                                    <Select
                                        value={paymentForm.data.payment_status}
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
                                            {PAYMENT_STATUSES.map((status) => (
                                                <SelectItem
                                                    key={status.value}
                                                    value={status.value}
                                                >
                                                    {status.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-2">
                                    <Label>Método</Label>
                                    <Select
                                        value={
                                            paymentForm.data.payment_method ||
                                            'other'
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
                                            {PAYMENT_METHODS.map((method) => (
                                                <SelectItem
                                                    key={method.value}
                                                    value={method.value}
                                                >
                                                    {method.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={paymentForm.processing}
                                >
                                    {paymentForm.processing && <Spinner />}
                                    Guardar pagamento
                                </Button>
                            </form>
                        </section>

                        {!isClosed && (
                            <section className="flex flex-col gap-3 border-t border-border/60 pt-8">
                                <h2 className="text-lg font-semibold">
                                    Ajuste ao total
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Negativo desconta, positivo acrescenta. O
                                    total é sempre subtotal + portes + ajuste.
                                </p>
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        adjustmentForm.patch(
                                            ajuste(order.id).url,
                                            { preserveScroll: true },
                                        );
                                    }}
                                    className="flex flex-col gap-3"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="adjustment_price">
                                            Ajuste (€)
                                        </Label>
                                        <Input
                                            id="adjustment_price"
                                            inputMode="decimal"
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
                                        <p className="text-sm text-muted-foreground">
                                            Total:{' '}
                                            <span className="font-medium text-foreground">
                                                {formatCents(
                                                    adjustedTotalCents,
                                                )}
                                            </span>
                                        </p>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="adjustment_reason">
                                            Motivo
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

                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={adjustmentForm.processing}
                                    >
                                        {adjustmentForm.processing && (
                                            <Spinner />
                                        )}
                                        Guardar ajuste
                                    </Button>
                                </form>
                            </section>
                        )}

                        <section className="flex flex-col gap-3 border-t border-border/60 pt-8">
                            <h2 className="text-lg font-semibold">
                                Envio e notas
                            </h2>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    detailsForm.patch(detalhes(order.id).url, {
                                        preserveScroll: true,
                                    });
                                }}
                                className="flex flex-col gap-3"
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="shipping_method_name">
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

                                <div className="grid gap-2">
                                    <Label htmlFor="tracking_number">
                                        Nº de seguimento
                                    </Label>
                                    <Input
                                        id="tracking_number"
                                        value={detailsForm.data.tracking_number}
                                        onChange={(event) =>
                                            detailsForm.setData(
                                                'tracking_number',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            detailsForm.errors.tracking_number
                                        }
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="tracking_url">
                                        Link de seguimento
                                    </Label>
                                    <Input
                                        id="tracking_url"
                                        type="url"
                                        value={detailsForm.data.tracking_url}
                                        onChange={(event) =>
                                            detailsForm.setData(
                                                'tracking_url',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            detailsForm.errors.tracking_url
                                        }
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="admin_note">
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

                                {/*
                                 * Dentro deste form e não no cabeçalho: as
                                 * etiquetas gravam-se com o resto do que o admin
                                 * anota sobre a encomenda, num só PATCH.
                                 */}
                                <div className="grid gap-2">
                                    <Label htmlFor="tags">Etiquetas</Label>
                                    <TagInput
                                        id="tags"
                                        value={detailsForm.data.tags}
                                        onChange={(tags) =>
                                            detailsForm.setData('tags', tags)
                                        }
                                        suggestions={tagSuggestions}
                                    />
                                    <InputError
                                        message={detailsForm.errors.tags}
                                    />
                                </div>

                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={detailsForm.processing}
                                >
                                    {detailsForm.processing && <Spinner />}
                                    Guardar
                                </Button>
                            </form>
                        </section>
                    </aside>
                </div>
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
