import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCents, inputToCents } from '@/lib/money';
import { store } from '@/routes/admin/impressoras';
import type { PrinterProfileFormData } from '@/types/pricing';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** True quando ainda não há impressora nenhuma — muda o texto de ajuda. */
    isFirst: boolean;
};

/** Peça de referência para o custo/hora deixar de ser um número abstrato. */
const EXAMPLE_MINUTES = 90;

/**
 * Nova impressora.
 *
 * Só três campos: um nome, um custo/hora e uma nota. O custo/hora é o campo que
 * importa e o único que precisa de explicação — quem o preenche a pensar só em
 * eletricidade fica com peças a metade do preço.
 */
export function PrinterCreateDialog({ open, onOpenChange, isFirst }: Props) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm<PrinterProfileFormData>({
            name: '',
            hourly_rate: '0.50',
            notes: '',
            // A primeira impressora fica predefinida de qualquer forma (o
            // serviço trata disso); marcá-la aqui é só honestidade visual.
            is_default: false,
            active: true,
            sort_order: 0,
        });

    const rateCents = inputToCents(data.hourly_rate);
    const exampleCents = Math.round((rateCents * EXAMPLE_MINUTES) / 60);

    const close = (next: boolean) => {
        if (!next) {
            reset();
            clearErrors();
        }

        onOpenChange(next);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url, { onSuccess: () => close(false) });
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Nova impressora</DialogTitle>
                        <DialogDescription>
                            {isFirst
                                ? 'A primeira máquina fica logo a predefinida — é ela que a calculadora usa quando ninguém escolhe outra.'
                                : 'Cada máquina tem o seu custo por hora, e é ele que faz uma peça lenta custar mais do que uma rápida com o mesmo plástico.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="printer_name">Nome</Label>
                        <Input
                            id="printer_name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            required
                            autoFocus
                            maxLength={60}
                            placeholder="Bambu Lab A1"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="printer_rate">Custo por hora (€)</Label>
                        <Input
                            id="printer_rate"
                            type="number"
                            step="0.01"
                            min={0}
                            value={data.hourly_rate}
                            onChange={(event) =>
                                setData('hourly_rate', event.target.value)
                            }
                            required
                            className="max-w-40"
                        />
                        <p className="text-xs text-muted-foreground">
                            Energia, desgaste, manutenção, consumíveis e
                            depreciação num número só.
                        </p>
                        {rateCents > 0 && (
                            <p className="text-sm text-muted-foreground">
                                Uma impressão de 1h30 custa{' '}
                                <strong className="text-foreground tabular-nums">
                                    {formatCents(exampleCents)}
                                </strong>{' '}
                                de máquina.
                            </p>
                        )}
                        <InputError message={errors.hourly_rate} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="printer_notes">Notas</Label>
                        <Input
                            id="printer_notes"
                            value={data.notes}
                            onChange={(event) =>
                                setData('notes', event.target.value)
                            }
                            maxLength={255}
                            placeholder="Opcional"
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => close(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Criar impressora
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
