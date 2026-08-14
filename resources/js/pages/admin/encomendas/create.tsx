import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CustomerPicker } from '@/components/admin/customer-picker';
import { FormTab } from '@/components/admin/form-tab';
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
import { Tabs, TabsContent, TabsList } from '@/components/ui/tabs';
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

/**
 * A venda em mão. É o único canal em que o email não é obrigatório: o cliente
 * está à frente, leva a peça, e não há nada para lhe enviar depois.
 */
const MANUAL = 'manual';

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

/**
 * Que campos vivem em que separador.
 *
 * Serve para pôr o ponto de erro no separador certo: um separador fechado
 * esconde os campos, e com eles as mensagens de validação — sem isto, submeter
 * com um erro noutro separador era um formulário a recusar sem dizer porquê.
 */
const TAB_FIELDS: Record<string, string[]> = {
    cliente: ['user_id', 'customer_name', 'email', 'phone', 'nif'],
    artigos: ['items'],
    envio: [
        'line1',
        'line2',
        'postal_code',
        'city',
        'country',
        'shipping_method_name',
        'shipping_price',
    ],
    pagamento: [
        'payment_method',
        'payment_status',
        'admin_note',
        'send_confirmation',
    ],
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

    const manual = data.sales_channel === MANUAL;

    /*
     * Vendas em mão não têm morada. O interruptor esconde os campos e, no
     * envio, limpa-os — esconder sem limpar mandava para a base de dados uma
     * morada escrita e depois desligada. No canal `manual` nem interruptor há:
     * é sempre em mão, e o separador do envio desaparece.
     */
    const [shipping, setShipping] = useState(
        draft === undefined ||
            draft.payload.line1 !== '' ||
            draft.payload.shipping_method_name !== '' ||
            inputToCents(draft.payload.shipping_price) > 0,
    );
    const shipped = shipping && !manual;

    /*
     * Com um cliente escolhido os dados dele não aparecem como campos — já
     * estão decididos, e um formulário preenchido a repeti-los só convida a
     * alterá-los sem intenção. Isto abre-os na mesma, porque a encomenda é um
     * *snapshot*: corrigir uma morada só para esta venda não pode obrigar a ir
     * mexer na ficha do cliente.
     */
    const [overriding, setOverriding] = useState(false);

    /*
     * Os separadores são controlados porque o do envio desaparece no canal
     * `manual`: com o Radix a guardar o valor sozinho, trocar para `manual`
     * estando no envio deixava o formulário sem separador nenhum aberto.
     */
    const [tab, setTab] = useState('cliente');
    const activeTab = manual && tab === 'envio' ? 'cliente' : tab;

    const chosen =
        customers.find((customer) => customer.id === data.user_id) ?? null;
    /** Campos do cliente à vista: sem ficha escolhida, ou a corrigir a mão. */
    const fieldsVisible = chosen === null || overriding;

    // Só os estados que fazem sentido registar à mão: o resto do ciclo
    // (reembolsos, falhas) acontece depois, no detalhe da encomenda.
    const manualPaymentStatuses = PAYMENT_STATUSES.filter((status) =>
        ['pending', 'paid'].includes(status.value),
    );

    const pickCustomer = (customer: CustomerOption) => {
        // Snapshot e não vínculo rígido: os dados da encomenda são copiados da
        // ficha e podem divergir dela a partir daqui.
        setOverriding(false);
        setData((current) => ({
            ...current,
            user_id: customer.id,
            customer_name: customer.name,
            email: customer.email ?? '',
            phone: customer.phone ?? '',
            nif: customer.nif ?? '',
            line1: customer.address?.line1 ?? '',
            line2: customer.address?.line2 ?? '',
            postal_code: customer.address?.postalCode ?? '',
            city: customer.address?.city ?? '',
            country: customer.address?.country ?? 'PT',
        }));
    };

    /*
     * Limpar tudo e não só o `user_id`: largar a ficha e ficar com o nome, o
     * email e a morada do cliente anterior no ecrã era a forma mais fácil de
     * registar uma venda no cliente errado.
     */
    const clearCustomer = () => {
        setOverriding(false);
        setData((current) => ({
            ...current,
            user_id: null,
            customer_name: '',
            email: '',
            phone: '',
            nif: '',
            line1: '',
            line2: '',
            postal_code: '',
            city: '',
            country: 'PT',
        }));
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

    /** Há erros em campos deste separador? (`items.0.qty` conta para `items`.) */
    const invalid = (tab: string) =>
        Object.keys(messages).some((key) =>
            TAB_FIELDS[tab].some(
                (field) => key === field || key.startsWith(`${field}.`),
            ),
        );

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

                    {/*
                     * O canal fica por cima dos separadores e não dentro de um
                     * deles: é ele que decide o que o resto do formulário pede,
                     * e um campo com esse peso não pode estar escondido atrás de
                     * um separador fechado.
                     */}
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
                                {manual && (
                                    <p className="text-xs text-muted-foreground">
                                        Venda em mão: só o nome é preciso — sem
                                        email, sem morada, sem portes.
                                    </p>
                                )}
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

                    <Tabs value={activeTab} onValueChange={setTab}>
                        <TabsList>
                            <FormTab
                                value="cliente"
                                invalid={invalid('cliente')}
                            >
                                Cliente
                            </FormTab>
                            <FormTab
                                value="artigos"
                                invalid={invalid('artigos')}
                            >
                                Artigos
                                {data.items.length > 0 && (
                                    <span className="text-muted-foreground tabular-nums">
                                        {data.items.length}
                                    </span>
                                )}
                            </FormTab>
                            {!manual && (
                                <FormTab
                                    value="envio"
                                    invalid={invalid('envio')}
                                >
                                    Envio
                                </FormTab>
                            )}
                            <FormTab
                                value="pagamento"
                                invalid={invalid('pagamento')}
                            >
                                Pagamento
                            </FormTab>
                        </TabsList>

                        <TabsContent value="cliente">
                            <Panel title="Cliente">
                                <div className="grid gap-4">
                                    <CustomerPicker
                                        customers={customers}
                                        selected={chosen}
                                        onPick={pickCustomer}
                                        onClear={clearCustomer}
                                    />

                                    {chosen !== null && (
                                        <ChosenCustomer
                                            data={data}
                                            overriding={overriding}
                                            onOverride={() =>
                                                setOverriding(true)
                                            }
                                        />
                                    )}

                                    {fieldsVisible && (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="customer_name">
                                                    Nome
                                                </Label>
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
                                                    message={
                                                        errors.customer_name
                                                    }
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="email">
                                                    Email
                                                    {manual && (
                                                        <span className="font-normal text-muted-foreground">
                                                            (opcional)
                                                        </span>
                                                    )}
                                                </Label>
                                                <Input
                                                    id="email"
                                                    type="email"
                                                    value={data.email}
                                                    onChange={(event) =>
                                                        setData(
                                                            'email',
                                                            event.target.value,
                                                        )
                                                    }
                                                    required={!manual}
                                                />
                                                <InputError
                                                    message={errors.email}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="phone">
                                                    Telefone
                                                </Label>
                                                <Input
                                                    id="phone"
                                                    value={data.phone}
                                                    onChange={(event) =>
                                                        setData(
                                                            'phone',
                                                            event.target.value,
                                                        )
                                                    }
                                                    maxLength={30}
                                                />
                                                <InputError
                                                    message={errors.phone}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="nif">NIF</Label>
                                                <Input
                                                    id="nif"
                                                    value={data.nif}
                                                    onChange={(event) =>
                                                        setData(
                                                            'nif',
                                                            event.target.value,
                                                        )
                                                    }
                                                    maxLength={20}
                                                />
                                                <InputError
                                                    message={errors.nif}
                                                />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </Panel>
                        </TabsContent>

                        <TabsContent value="artigos">
                            <Panel
                                title="Artigos"
                                description="Artigos do catálogo descontam stock; linhas livres não."
                            >
                                <ManualOrderItems
                                    items={data.items}
                                    variants={variants}
                                    defaultVatRate={defaultVatRate}
                                    onChange={(items) =>
                                        setData('items', items)
                                    }
                                />
                                <InputError
                                    className="mt-2"
                                    message={itemsError}
                                />
                            </Panel>
                        </TabsContent>

                        {!manual && (
                            <TabsContent value="envio">
                                <Panel
                                    title="Envio"
                                    aside={
                                        <Label className="gap-2 text-xs font-normal text-muted-foreground">
                                            <Checkbox
                                                checked={shipping}
                                                onCheckedChange={(checked) =>
                                                    setShipping(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            Esta encomenda é enviada
                                        </Label>
                                    }
                                >
                                    {shipped ? (
                                        <div className="grid gap-4">
                                            {fieldsVisible ? (
                                                <>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="line1">
                                                            Morada
                                                        </Label>
                                                        <Input
                                                            id="line1"
                                                            value={data.line1}
                                                            onChange={(event) =>
                                                                setData(
                                                                    'line1',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            maxLength={190}
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.line1
                                                            }
                                                        />
                                                    </div>

                                                    <div className="grid gap-2">
                                                        <Label htmlFor="line2">
                                                            Complemento
                                                        </Label>
                                                        <Input
                                                            id="line2"
                                                            value={data.line2}
                                                            onChange={(event) =>
                                                                setData(
                                                                    'line2',
                                                                    event.target
                                                                        .value,
                                                                )
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
                                                                value={
                                                                    data.postal_code
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setData(
                                                                        'postal_code',
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="1234-567"
                                                                maxLength={8}
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.postal_code
                                                                }
                                                            />
                                                        </div>

                                                        <div className="grid gap-2">
                                                            <Label htmlFor="city">
                                                                Localidade
                                                            </Label>
                                                            <Input
                                                                id="city"
                                                                value={
                                                                    data.city
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setData(
                                                                        'city',
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                                maxLength={80}
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.city
                                                                }
                                                            />
                                                        </div>

                                                        <div className="grid gap-2">
                                                            <Label htmlFor="country">
                                                                País
                                                            </Label>
                                                            <Input
                                                                id="country"
                                                                value={
                                                                    data.country
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setData(
                                                                        'country',
                                                                        event.target.value.toUpperCase(),
                                                                    )
                                                                }
                                                                maxLength={2}
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.country
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                </>
                                            ) : (
                                                <ChosenAddress
                                                    data={data}
                                                    onOverride={() =>
                                                        setOverriding(true)
                                                    }
                                                />
                                            )}

                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="shipping_method_name">
                                                        Método de envio
                                                    </Label>
                                                    <Input
                                                        id="shipping_method_name"
                                                        value={
                                                            data.shipping_method_name
                                                        }
                                                        onChange={(event) =>
                                                            setData(
                                                                'shipping_method_name',
                                                                event.target
                                                                    .value,
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
                                                        value={
                                                            data.shipping_price
                                                        }
                                                        onChange={(event) =>
                                                            setData(
                                                                'shipping_price',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        className="tabular-nums"
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.shipping_price
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            Venda em mão — sem morada nem
                                            portes.
                                        </p>
                                    )}
                                </Panel>
                            </TabsContent>
                        )}

                        <TabsContent value="pagamento">
                            <Panel title="Pagamento">
                                <div className="grid gap-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label>Método</Label>
                                            <Select
                                                value={data.payment_method}
                                                onValueChange={(value) =>
                                                    setData(
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
                                                                key={
                                                                    method.value
                                                                }
                                                                value={
                                                                    method.value
                                                                }
                                                            >
                                                                {method.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
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
                                                    setData(
                                                        'payment_status',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {manualPaymentStatuses.map(
                                                        (status) => (
                                                            <SelectItem
                                                                key={
                                                                    status.value
                                                                }
                                                                value={
                                                                    status.value
                                                                }
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
                                        Marcar como pago envia a encomenda para
                                        produção ou expedição de imediato.
                                    </p>

                                    <div className="grid gap-2">
                                        <Label htmlFor="admin_note">
                                            Nota interna
                                        </Label>
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
                                        <InputError
                                            message={errors.admin_note}
                                        />
                                    </div>

                                    {/*
                                     * Sem email não há para onde enviar. A
                                     * checkbox desaparece em vez de ficar
                                     * desligada e por explicar.
                                     */}
                                    {data.email.trim() === '' ? (
                                        <p className="text-sm text-muted-foreground">
                                            Sem email não há confirmação a
                                            enviar.
                                        </p>
                                    ) : (
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
                                            Enviar email de confirmação ao
                                            cliente
                                        </Label>
                                    )}
                                </div>
                            </Panel>
                        </TabsContent>
                    </Tabs>
                </div>

                {/*
                 * `flex-1 basis-72` com `max-w-sm`: empilhado no telemóvel
                 * ocupa a largura toda, ao lado da coluna principal pára nos
                 * 24rem — o `flex:1 1 280px;max-width:340px` do design.
                 *
                 * Fica fora dos separadores de propósito: o total e os botões
                 * são a única coisa que tem de estar à vista em qualquer passo.
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

type ChosenProps = {
    data: ManualOrderFormData;
    onOverride: () => void;
};

/**
 * Os dados que vão para a encomenda quando há uma ficha escolhida. É leitura e
 * não campos: já foram decididos na ficha do cliente.
 */
function ChosenCustomer({
    data,
    overriding,
    onOverride,
}: ChosenProps & { overriding: boolean }) {
    if (overriding) {
        return (
            <p className="text-xs text-muted-foreground">
                Os campos abaixo valem só para esta encomenda — a ficha do
                cliente fica como está.
            </p>
        );
    }

    return (
        <dl className="grid gap-2 rounded-xl border border-border/60 p-4 text-sm sm:grid-cols-2">
            <Field label="Nome" value={data.customer_name} />
            <Field label="Email" value={data.email} />
            <Field label="Telefone" value={data.phone} />
            <Field label="NIF" value={data.nif} />

            <div className="sm:col-span-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="-ml-2"
                    onClick={onOverride}
                >
                    Alterar só nesta encomenda
                </Button>
            </div>
        </dl>
    );
}

/** A morada da ficha, em leitura, no separador do envio. */
function ChosenAddress({ data, onOverride }: ChosenProps) {
    const lines = [
        data.line1,
        data.line2,
        [data.postal_code, data.city].filter(Boolean).join(' '),
        data.country,
    ].filter((line) => line.trim() !== '');

    return (
        <div className="rounded-xl border border-border/60 p-4 text-sm">
            {lines.length === 0 ? (
                <p className="text-muted-foreground">
                    Este cliente não tem morada na ficha.
                </p>
            ) : (
                <address className="not-italic">
                    {lines.map((line) => (
                        <span key={line} className="block">
                            {line}
                        </span>
                    ))}
                </address>
            )}

            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="mt-2 -ml-2"
                onClick={onOverride}
            >
                Alterar só nesta encomenda
            </Button>
        </div>
    );
}

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd>{value.trim() === '' ? '—' : value}</dd>
        </div>
    );
}

OrdersCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Encomendas', href: index() },
        { title: 'Nova', href: '#' },
    ],
};
