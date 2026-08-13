import { useForm } from '@inertiajs/react';
import { CustomerFields } from '@/components/admin/customer-fields';
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
import { store } from '@/routes/admin/clientes';
import type { CustomerFormData } from '@/types/customer';
import { EMPTY_CUSTOMER_FORM, isCustomerFormReady } from '@/types/customer';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tagSuggestions: string[];
};

/**
 * Criar um cliente sem sair da listagem.
 *
 * A página /admin/clientes/create continua a existir para quem chega por URL e
 * usa os mesmos campos; o que muda é só o destino depois de gravar — daqui o
 * admin fica na lista (ou salta para uma encomenda nova), da página cai no
 * formulário de edição, como em todo o resto do backoffice.
 */
export function CustomerDialog({ open, onOpenChange, tagSuggestions }: Props) {
    /*
     * `after` diz ao servidor para onde ir depois de gravar. Viaja no próprio
     * formulário, e não em estado à parte, porque é isso que o `useForm`
     * submete — um `useState` paralelo teria de ser costurado ao payload na
     * submissão, e é aí que estas coisas se dessincronizam.
     */
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm<CustomerFormData & { after: string }>({
            ...EMPTY_CUSTOMER_FORM,
            after: 'list',
        });

    const close = () => {
        onOpenChange(false);
        clearErrors();
        reset();
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url, { onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? null : close())}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-[620px]">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Novo cliente</DialogTitle>
                        <DialogDescription>
                            Só o nome é obrigatório. O resto podes preencher
                            depois.
                        </DialogDescription>
                    </DialogHeader>

                    <CustomerFields
                        data={data}
                        setData={setData}
                        errors={errors}
                        tagSuggestions={tagSuggestions}
                    />

                    <DialogFooter className="items-center gap-3 sm:justify-between">
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
                                Guardar cliente
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
