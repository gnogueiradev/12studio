import { Head, useForm } from '@inertiajs/react';
import {
    emptyItem,
    ManualOrderItems,
} from '@/components/admin/manual-order-items';
import { PageHeader } from '@/components/admin/page-header';
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
import { index, store } from '@/routes/admin/encomendas';
import type { VariantOption } from '@/types/catalog';
import type { CustomerOption } from '@/types/customer';
import type { ManualOrderFormData } from '@/types/order';
import {
    PAYMENT_METHODS,
    PAYMENT_STATUSES,
    SALES_CHANNELS,
} from '@/types/order';

type Props = {
    customers: CustomerOption[];
    variants: VariantOption[];
    defaultVatRate: number;
};

const GUEST = 'guest';

export default function OrdersCreate({
    customers,
    variants,
    defaultVatRate,
}: Props) {
    const { data, setData, post, processing, errors } =
        useForm<ManualOrderFormData>({
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
            items: [emptyItem(defaultVatRate)],
        });

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
                email: customer.email,
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

    const itemsTotal = data.items.reduce(
        (sum, item) => sum + inputToCents(item.unit_price) * item.qty,
        0,
    );
    const total = itemsTotal + inputToCents(data.shipping_price);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url);
    };

    return (
        <>
            <Head title="Nova encomenda" />
            <form
                onSubmit={submit}
                className="flex h-full flex-1 flex-col gap-8 p-4"
            >
                <PageHeader
                    title="Nova encomenda"
                    description="Registar uma venda feita fora da loja online — Vinted, Instagram ou em mão."
                />

                <section className="flex flex-col gap-4">
                    <h2 className="text-lg font-semibold">Canal</h2>
                    <div className="grid max-w-2xl grid-cols-2 gap-4">
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
                </section>

                <section className="flex flex-col gap-4 border-t border-border/60 pt-8">
                    <h2 className="text-lg font-semibold">Cliente</h2>

                    <div className="grid max-w-2xl gap-4">
                        <div className="grid gap-2">
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
                                            {customer.name} · {customer.email}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
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
                                <InputError message={errors.customer_name} />
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
                </section>

                <section className="flex flex-col gap-4 border-t border-border/60 pt-8">
                    <h2 className="text-lg font-semibold">Envio</h2>
                    <p className="text-sm text-muted-foreground">
                        Opcional — vendas em mão não precisam de morada.
                    </p>

                    <div className="grid max-w-2xl gap-4">
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

                        <div className="grid grid-cols-3 gap-4">
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
                                <InputError message={errors.postal_code} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="city">Localidade</Label>
                                <Input
                                    id="city"
                                    value={data.city}
                                    onChange={(event) =>
                                        setData('city', event.target.value)
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

                        <div className="grid grid-cols-2 gap-4">
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
                                    required
                                />
                                <InputError message={errors.shipping_price} />
                            </div>
                        </div>
                    </div>
                </section>

                <section className="flex flex-col gap-4 border-t border-border/60 pt-8">
                    <div>
                        <h2 className="text-lg font-semibold">Artigos</h2>
                        <p className="text-sm text-muted-foreground">
                            Artigos do catálogo descontam stock; linhas livres
                            não.
                        </p>
                    </div>

                    <ManualOrderItems
                        items={data.items}
                        variants={variants}
                        defaultVatRate={defaultVatRate}
                        errors={errors as Record<string, string>}
                        onChange={(items) => setData('items', items)}
                    />
                    <InputError message={errors.items} />
                </section>

                <section className="flex flex-col gap-4 border-t border-border/60 pt-8">
                    <h2 className="text-lg font-semibold">Pagamento</h2>

                    <div className="grid max-w-2xl grid-cols-2 gap-4">
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
                            <InputError message={errors.payment_method} />
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
                                    {manualPaymentStatuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.payment_status} />
                            <p className="text-xs text-muted-foreground">
                                Marcar como pago envia a encomenda para produção
                                ou expedição de imediato.
                            </p>
                        </div>
                    </div>

                    <div className="grid max-w-2xl gap-2">
                        <Label htmlFor="admin_note">Nota interna</Label>
                        <Textarea
                            id="admin_note"
                            value={data.admin_note}
                            onChange={(event) =>
                                setData('admin_note', event.target.value)
                            }
                            rows={3}
                        />
                        <InputError message={errors.admin_note} />
                    </div>

                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox
                            checked={data.send_confirmation}
                            onCheckedChange={(checked) =>
                                setData('send_confirmation', checked === true)
                            }
                        />
                        Enviar email de confirmação ao cliente
                    </Label>
                </section>

                <div className="flex items-center gap-4 border-t border-border/60 pt-8">
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        Criar encomenda
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        Total: <strong>{formatCents(total)}</strong>
                    </span>
                </div>
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
