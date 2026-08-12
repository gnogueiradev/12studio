import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ManualOrderItems } from '@/components/admin/manual-order-items';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
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
import { formatCents, inputToCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index, store } from '@/routes/admin/encomendas';
import {
    store as storeDraft,
    update as updateDraft,
} from '@/routes/admin/encomendas/rascunhos';
import type { VariantOption } from '@/types/catalog';
import type { CustomerOption } from '@/types/customer';
import type { ManualOrderFormData, OrderDraft } from '@/types/order';
import {
    PAYMENT_METHODS,
    PAYMENT_STATUSES,
    SALES_CHANNELS,
} from '@/types/order';

type Props = {
    customers: CustomerOption[];
    variants: VariantOption[];
    defaultVatRate: number;
    /** Presente quando a página foi aberta a retomar um rascunho. */
    draft?: OrderDraft;
};

const GUEST = 'guest';

const BLANK: ManualOrderFormData = {
    user_id: null,
    customer_name: '',
    email: '',
    phone: '',
    nif: '',
    sales_channel: 'instagram',
    external_order_reference: '',
    payment_method: 'bank_transfer',
    payment_status: 'pending',
    shipping_price: '0',
    shipping_method_name: '',
    line1: '',
    line2: '',
    postal_code: '',
    city: '',
    country: 'PT',
    admin_note: '',
    send_confirmation: false,
    items: [],
    draft_id: null,
};

export default function OrdersCreate({
    customers,
    variants,
    defaultVatRate,
    draft,
}: Props) {
    const { data, setData, post, put, transform, processing, errors } =
        useForm<ManualOrderFormData>({
            // O spread por cima do BLANK protege de um rascunho antigo a que
            // falte um campo que o formulário entretanto ganhou.
            ...BLANK,
            ...draft?.payload,
            draft_id: draft?.id ?? null,
        });

    /*
     * Vendas em mão não têm morada. O interruptor esconde os campos e, no
     * envio, limpa-os — esconder sem limpar mandava para a base de dados uma
     * morada escrita e depois desligada.
     */
    const [shipped, setShipped] = useState(
        draft === undefined ||
            draft.payload.line1 !== '' ||
            draft.payload.shipping_method_name !== '' ||
            inputToCents(draft.payload.shipping_price) > 0,
    );

    // Só os estados que fazem sentido registar à mão: o resto do ciclo
    // (reembolsos, falhas) acontece depois, no detalhe da encomenda.
    const manualPaymentStatuses = PAYMENT_STATUSES.filter((status) =>
        ['pending', 'paid'].includes(status.value),
    );

    const pickCustomer = (value: string) => {
        if (value === GUEST) {
            setData('user_id', null);

            return;
        }

        const customer = customers.find(
            (option) => option.id === Number(value),
        );

        if (customer) {
            // Prefill, não vínculo rígido: os dados da encomenda são um
            // snapshot e podem divergir da ficha do cliente.
            setData((current) => ({
                ...current,
                user_id: customer.id,
                customer_name: customer.name,
                // Um cliente criado só com o nome não tem email; a encomenda
                // continua a precisar de um, e é o admin que o escreve aqui.
                email: customer.email ?? '',
                phone: customer.phone ?? '',
                nif: customer.nif ?? '',
                line1: customer.address?.line1 ?? '',
                line2: customer.address?.line2 ?? '',
                postal_code: customer.address?.postalCode ?? '',
                city: customer.address?.city ?? '',
                country: customer.address?.country ?? 'PT',
            }));
        }
    };

    const units = data.items.reduce((sum, item) => sum + item.qty, 0);
    const itemsTotal = data.items.reduce(
        (sum, item) => sum + inputToCents(item.unit_price) * item.qty,
        0,
    );
    const shippingCents = shipped ? inputToCents(data.shipping_price) : 0;
    const paid = data.payment_status === 'paid';

    const messages = errors as Record<string, string>;
    const itemsError =
        messages.items ??
        Object.entries(messages).find(([key]) => key.startsWith('items.'))?.[1];

    /**
     * Chamado antes de cada envio: manda o `shipped` de agora, e o `draft_id`
     * vem do prop e não do estado do formulário — depois de gravar o primeiro
     * rascunho o Inertia troca os props sem remontar a página, e um
     * `draft_id` copiado no arranque ficaria a null para sempre (a encomenda
     * criada a seguir deixava o rascunho para trás).
     */
    const clean = () =>
        transform((current) => ({
            ...current,
            draft_id: draft?.id ?? null,
            ...(shipped
                ? {}
                : {
                      line1: '',
                      line2: '',
                      postal_code: '',
                      city: '',
                      shipping_method_name: '',
                      shipping_price: '0',
                  }),
        }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        clean();
        post(store().url);
    };

    /*
     * Guardar a meio não interrompe o trabalho: fica-se na página. Sem
     * rascunho ainda, o servidor responde com o URL do rascunho novo — a
     * página é a mesma e o conteúdo é o que está no ecrã, mas a partir daí há
     * um id e as gravações seguintes atualizam em vez de duplicarem.
     */
    const saveDraft = () => {
        clean();

        if (draft === undefined) {
            post(storeDraft().url, { preserveScroll: true });

            return;
        }

        put(updateDraft(draft.id).url, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Nova encomenda" />
            <form
                onSubmit={submit}
                className="flex flex-wrap items-start gap-4 p-4"
            >
                <div className="flex min-w-0 flex-1 basis-128 flex-col gap-4">
                    <PageHeader
                        title="Nova encomenda"
                        description="Registar uma venda feita fora da loja online — Vinted, Instagram ou em mão."
                    />

                    <Panel title="Canal">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label>Canal de venda</Label>
                                <Select
                                    value={data.sales_channel}
                                    onValueChange={(value) =>
                                        setData('sales_channel', value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {SALES_CHANNELS.map((channel) => (
                                            <SelectItem
                                                key={channel.value}
                                                value={channel.value}
                                            >
                                                {channel.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.sales_channel} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="external_order_reference">
                                    Referência externa
                                </Label>
                                <Input
                                    id="external_order_reference"
                                    value={data.external_order_reference}
                                    onChange={(event) =>
                                        setData(
                                            'external_order_reference',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Nº da venda no Vinted, DM…"
                                    maxLength={100}
                                />
                                <InputError
                                    message={errors.external_order_reference}
                                />
                            </div>
                        </div>
                    </Panel>

                    <Panel title="Cliente">
                        <div className="grid gap-4">
                            <div className="grid gap-2 sm:max-w-md">
                                <Label>Cliente registado</Label>
                                <Select
                                    value={
                                        data.user_id === null
                                            ? GUEST
                                            : String(data.user_id)
                                    }
                                    onValueChange={pickCustomer}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={GUEST}>
                                            Sem conta — dados escritos abaixo
                                        </SelectItem>
                                        {customers.map((customer) => (
                                            <SelectItem
                                                key={customer.id}
                                                value={String(customer.id)}
                                            >
                                                {customer.name} ·{' '}
                                                {customer.email ?? 'sem email'}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="customer_name">Nome</Label>
                                    <Input
                                        id="customer_name"
                                        value={data.customer_name}
                                        onChange={(event) =>
                                            setData(
                                                'customer_name',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        maxLength={120}
                                    />
                                    <InputError
                                        message={errors.customer_name}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(event) =>
                                            setData('email', event.target.value)
                                        }
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="phone">Telefone</Label>
                                    <Input
                                        id="phone"
                                        value={data.phone}
                                        onChange={(event) =>
                                            setData('phone', event.target.value)
                                        }
                                        maxLength={30}
                                    />
                                    <InputError message={errors.phone} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="nif">NIF</Label>
                                    <Input
                                        id="nif"
                                        value={data.nif}
                                        onChange={(event) =>
                                            setData('nif', event.target.value)
                                        }
                                        maxLength={20}
                                    />
                                    <InputError message={errors.nif} />
                                </div>
                            </div>
                        </div>
                    </Panel>

                    <Panel
                        title="Envio"
                        aside={
                            <Label className="gap-2 text-xs font-normal text-muted-foreground">
                                <Checkbox
                                    checked={shipped}
                                    onCheckedChange={(checked) =>
                                        setShipped(checked === true)
                                    }
                                />
                                Esta encomenda é enviada
                            </Label>
                        }
                    >
                        {shipped ? (
                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="line1">Morada</Label>
                                    <Input
                                        id="line1"
                                        value={data.line1}
                                        onChange={(event) =>
                                            setData('line1', event.target.value)
                                        }
                                        maxLength={190}
                                    />
                                    <InputError message={errors.line1} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="line2">Complemento</Label>
                                    <Input
                                        id="line2"
                                        value={data.line2}
                                        onChange={(event) =>
                                            setData('line2', event.target.value)
                                        }
                                        maxLength={190}
                                    />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="postal_code">
                                            Código postal
                                        </Label>
                                        <Input
                                            id="postal_code"
                                            value={data.postal_code}
                                            onChange={(event) =>
                                                setData(
                                                    'postal_code',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="1234-567"
                                            maxLength={8}
                                        />
                                        <InputError
                                            message={errors.postal_code}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="city">Localidade</Label>
                                        <Input
                                            id="city"
                                            value={data.city}
                                            onChange={(event) =>
                                                setData(
                                                    'city',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={80}
                                        />
                                        <InputError message={errors.city} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="country">País</Label>
                                        <Input
                                            id="country"
                                            value={data.country}
                                            onChange={(event) =>
                                                setData(
                                                    'country',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                            maxLength={2}
                                        />
                                        <InputError message={errors.country} />
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="shipping_method_name">
                                            Método de envio
                                        </Label>
                                        <Input
                                            id="shipping_method_name"
                                            value={data.shipping_method_name}
                                            onChange={(event) =>
                                                setData(
                                                    'shipping_method_name',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="CTT Expresso"
                                            maxLength={120}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="shipping_price">
                                            Portes (€)
                                        </Label>
                                        <Input
                                            id="shipping_price"
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            value={data.shipping_price}
                                            onChange={(event) =>
                                                setData(
                                                    'shipping_price',
                                                    event.target.value,
                                                )
                                            }
                                            className="tabular-nums"
                                            required
                                        />
                                        <InputError
                                            message={errors.shipping_price}
                                        />
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Venda em mão — sem morada nem portes.
                            </p>
                        )}
                    </Panel>

                    <Panel title="Artigos">
                        <p className="mb-4 text-sm text-muted-foreground">
                            Artigos do catálogo descontam stock; linhas livres
                            não.
                        </p>

                        <ManualOrderItems
                            items={data.items}
                            variants={variants}
                            defaultVatRate={defaultVatRate}
                            onChange={(items) => setData('items', items)}
                        />
                        <InputError className="mt-2" message={itemsError} />
                    </Panel>

                    <Panel title="Pagamento">
                        <div className="grid gap-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Método</Label>
                                    <Select
                                        value={data.payment_method}
                                        onValueChange={(value) =>
                                            setData('payment_method', value)
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
                                    <InputError
                                        message={errors.payment_method}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Estado</Label>
                                    <Select
                                        value={data.payment_status}
                                        onValueChange={(value) =>
                                            setData('payment_status', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {manualPaymentStatuses.map(
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
                                        message={errors.payment_status}
                                    />
                                </div>
                            </div>

                            <p className="text-sm text-muted-foreground">
                                Marcar como pago envia a encomenda para produção
                                ou expedição de imediato.
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="admin_note">Nota interna</Label>
                                <Textarea
                                    id="admin_note"
                                    value={data.admin_note}
                                    onChange={(event) =>
                                        setData(
                                            'admin_note',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                                <InputError message={errors.admin_note} />
                            </div>

                            <Label className="font-normal">
                                <Checkbox
                                    checked={data.send_confirmation}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'send_confirmation',
                                            checked === true,
                                        )
                                    }
                                />
                                Enviar email de confirmação ao cliente
                            </Label>
                        </div>
                    </Panel>
                </div>

                {/*
                 * `flex-1 basis-72` com `max-w-sm`: empilhado no telemóvel
                 * ocupa a largura toda, ao lado da coluna principal pára nos
                 * 24rem — o `flex:1 1 280px;max-width:340px` do design.
                 */}
                <aside className="sticky top-4 flex flex-1 basis-72 flex-col gap-3 rounded-xl border border-border/60 bg-card p-4 sm:max-w-sm">
                    <h2 className="text-sm font-semibold">Resumo</h2>

                    <div className="flex justify-between text-sm">
                        <span className="text-muted-foreground">
                            {data.items.length === 1
                                ? '1 artigo'
                                : `${data.items.length} artigos`}
                            {units > 0 && ` · ${units} un.`}
                        </span>
                        <span className="tabular-nums">
                            {formatCents(itemsTotal)}
                        </span>
                    </div>

                    <div className="flex justify-between text-sm">
                        <span className="text-muted-foreground">Portes</span>
                        <span className="tabular-nums">
                            {formatCents(shippingCents)}
                        </span>
                    </div>

                    <div className="flex items-baseline justify-between border-t border-border/60 pt-3">
                        <span className="text-sm text-muted-foreground">
                            Total
                        </span>
                        <span className="text-2xl font-semibold tracking-tight tabular-nums">
                            {formatCents(itemsTotal + shippingCents)}
                        </span>
                    </div>

                    <p
                        className={cn(
                            'rounded-lg px-3 py-2.5 text-xs',
                            paid
                                ? 'bg-success-soft text-success-soft-foreground'
                                : 'bg-muted text-muted-foreground',
                        )}
                    >
                        {paid
                            ? 'Marcada como paga: entra já em produção ou expedição.'
                            : 'Fica a aguardar pagamento. Não entra em produção.'}
                    </p>

                    <Button
                        type="submit"
                        className="rounded-full"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Criar encomenda
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="rounded-full"
                        disabled={processing}
                        onClick={saveDraft}
                    >
                        Guardar rascunho
                    </Button>
                </aside>
            </form>
        </>
    );
}

OrdersCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Encomendas', href: index() },
        { title: 'Nova', href: '#' },
    ],
};
