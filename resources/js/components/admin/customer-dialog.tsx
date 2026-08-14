import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { CustomerFields } from '@/components/admin/customer-fields';
import { FormTab } from '@/components/admin/form-tab';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList } from '@/components/ui/tabs';
import { formatCents } from '@/lib/money';
import { label } from '@/lib/options';
import { destroy, store, update } from '@/routes/admin/clientes';
import { show } from '@/routes/admin/encomendas';
import type {
    CustomerEditing,
    CustomerFormData,
    CustomerOrderRow,
} from '@/types/customer';
import { EMPTY_CUSTOMER_FORM, isCustomerFormReady } from '@/types/customer';
import { ORDER_STATUSES, PAYMENT_STATUSES } from '@/types/order';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; um cliente edita. */
    editing: CustomerEditing | null;
    tagSuggestions: string[];
};

/**
 * Criar e editar um cliente sem sair da listagem.
 *
 * Os dois papéis que a página de edição acumulava — o formulário e o histórico
 * de encomendas — são agora os dois separadores. O histórico é uma lista e não
 * a `AdminTable` de seis colunas que estava na página: num modal de 620px essa
 * tabela não cabe sem espremer todas as colunas.
 *
 * Não há efeito nenhum a sincronizar o formulário com o `editing`: quem monta
 * dá-lhe uma `key` que muda com o alvo, e o remonte trata do resto. Um
 * `useEffect` a chamar `setData` competia com o que o admin está a escrever no
 * momento em que a resposta do servidor chega.
 */
export function CustomerDialog({
    open,
    onOpenChange,
    editing,
    tagSuggestions,
}: Props) {
    /*
     * `after` diz ao servidor para onde ir depois de gravar. Viaja no próprio
     * formulário, e não em estado à parte, porque é isso que o `useForm`
     * submete — um `useState` paralelo teria de ser costurado ao payload na
     * submissão, e é aí que estas coisas se dessincronizam. A editar não
     * existe: aí grava-se e fica-se na listagem.
     */
    const {
        data,
        setData,
        post,
        patch,
        processing,
        errors,
        reset,
        clearErrors,
    } = useForm<CustomerFormData & { after: string }>(
        editing === null
            ? { ...EMPTY_CUSTOMER_FORM, after: 'list' }
            : {
                  name: editing.customer.name,
                  email: editing.customer.email ?? '',
                  customer_type: editing.customer.customerType,
                  phone: editing.customer.phone ?? '',
                  nif: editing.customer.nif ?? '',
                  admin_note: editing.customer.adminNote ?? '',
                  tags: editing.customer.tags,
                  line1: editing.customer.line1 ?? '',
                  line2: editing.customer.line2 ?? '',
                  postal_code: editing.customer.postalCode ?? '',
                  city: editing.customer.city ?? '',
                  country: editing.customer.country,
                  after: 'list',
              },
    );

    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const close = () => {
        onOpenChange(false);
        clearErrors();
        reset();
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (editing !== null) {
            patch(update(editing.customer.id).url, { onSuccess: close });

            return;
        }

        post(store().url, { onSuccess: close });
    };

    const orders = editing?.orders ?? [];

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? null : close())}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-[620px]">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? editing.customer.name : 'Novo cliente'}
                        </DialogTitle>
                        <DialogDescription>
                            {editing
                                ? `Cliente desde ${editing.customer.createdAt ?? '—'}`
                                : 'Só o nome é obrigatório. O resto podes preencher depois.'}
                        </DialogDescription>
                    </DialogHeader>

                    {editing === null ? (
                        <CustomerFields
                            data={data}
                            setData={setData}
                            errors={errors}
                            tagSuggestions={tagSuggestions}
                        />
                    ) : (
                        <Tabs defaultValue="dados">
                            <TabsList>
                                <FormTab
                                    value="dados"
                                    invalid={Object.keys(errors).length > 0}
                                >
                                    Dados
                                </FormTab>
                                <FormTab value="encomendas">
                                    Encomendas
                                    <span className="text-muted-foreground tabular-nums">
                                        {orders.length}
                                    </span>
                                </FormTab>
                            </TabsList>

                            <TabsContent value="dados">
                                <CustomerFields
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    tagSuggestions={tagSuggestions}
                                />
                            </TabsContent>

                            <TabsContent value="encomendas">
                                <CustomerOrders orders={orders} />
                            </TabsContent>
                        </Tabs>
                    )}

                    <DialogFooter className="items-center gap-3 sm:justify-between">
                        {editing === null ? (
                            <Label className="font-normal text-muted-foreground">
                                <Checkbox
                                    checked={data.after === 'order'}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'after',
                                            checked === true ? 'order' : 'list',
                                        )
                                    }
                                />
                                Criar encomenda a seguir
                            </Label>
                        ) : editing.customer.canDelete ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="rounded-full"
                                onClick={() => setConfirmingDelete(true)}
                            >
                                Apagar
                            </Button>
                        ) : (
                            /*
                             * Sem botão e sem explicação, quem procura o Apagar
                             * fica a achar que se perdeu. A regra global é esta:
                             * com histórico comercial, o registo fica.
                             */
                            <span className="text-xs text-muted-foreground">
                                Tem encomendas — não se apaga.
                            </span>
                        )}

                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-full"
                                onClick={close}
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="submit"
                                className="rounded-full"
                                disabled={
                                    processing || !isCustomerFormReady(data)
                                }
                            >
                                {processing && <Spinner />}
                                {editing
                                    ? 'Guardar alterações'
                                    : 'Guardar cliente'}
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>

            {editing !== null && (
                <ConfirmDialog
                    open={confirmingDelete}
                    onOpenChange={setConfirmingDelete}
                    title="Apagar cliente"
                    description={
                        <>
                            <strong>{editing.customer.name}</strong> é apagado
                            definitivamente, com a morada. Só é possível porque
                            não tem encomendas associadas.
                        </>
                    }
                    confirmLabel="Apagar"
                    destructive
                    onConfirm={() => {
                        router.delete(destroy(editing.customer.id).url, {
                            onFinish: () => setConfirmingDelete(false),
                        });
                    }}
                />
            )}
        </Dialog>
    );
}

/**
 * O histórico, em lista. Cada linha leva à encomenda — e é por isso que o
 * `Link` fecha o modal ao navegar: deixá-lo aberto por cima de outra página era
 * voltar atrás e encontrar a ficha de um cliente que já não se estava a ver.
 */
function CustomerOrders({ orders }: { orders: CustomerOrderRow[] }) {
    if (orders.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                Este cliente ainda não tem encomendas.
            </p>
        );
    }

    return (
        <ul className="flex flex-col divide-y divide-border/60">
            {orders.map((order) => (
                <li key={order.id}>
                    <Link
                        href={show(order.id)}
                        className="flex items-center justify-between gap-3 py-3 hover:underline"
                    >
                        <span className="min-w-0">
                            <span className="block font-medium">
                                {order.orderNumber}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {order.createdAt ?? '—'}
                            </span>
                        </span>

                        <span className="flex flex-none items-center gap-2">
                            <StatusBadge
                                value={order.status}
                                label={label(ORDER_STATUSES, order.status)}
                            />
                            <StatusBadge
                                value={order.paymentStatus}
                                label={label(
                                    PAYMENT_STATUSES,
                                    order.paymentStatus,
                                )}
                            />
                            <span className="w-20 text-right tabular-nums">
                                {formatCents(order.totalCents)}
                            </span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
