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
import { centsToInput, formatCents, inputToCents } from '@/lib/money';
import { store, update } from '@/routes/admin/impressoras';
import type {
    PrinterProfileFormData,
    PrinterProfileRow,
} from '@/types/pricing';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; uma linha da listagem edita essa impressora. */
    editing: PrinterProfileRow | null;
    /** True quando ainda não há impressora nenhuma — muda o texto de ajuda. */
    isFirst: boolean;
};

/** Peça de referência para o custo/hora deixar de ser um número abstrato. */
const EXAMPLE_MINUTES = 90;

/** Onde a primeira impressora começa: meio cêntimo por minuto de máquina. */
const DEFAULT_RATE = '0.50';

/**
 * Criar e editar uma impressora — os dois no mesmo sítio, para não haver dois
 * formulários da mesma coisa a divergir com o tempo.
 *
 * São quatro campos e só um deles precisa de explicação: o custo/hora, que quem
 * o preenche a pensar só em eletricidade deixa com peças a metade do preço.
 *
 * Nem "predefinida" nem "ativa" aparecem aqui. As duas são um clique na própria
 * linha da listagem ("Tornar predefinida", "Arquivar"/"Restaurar"), e uma chave
 * que não vai no payload é uma coluna que o servidor não toca — o
 * PrinterProfileService só promove a predefinida quando o `is_default` chega
 * verdadeiro, nunca despromove por omissão.
 *
 * Não há efeito nenhum a sincronizar o formulário com o `editing`: quem monta
 * este componente dá-lhe uma `key` que muda com o alvo, e o remonte trata do
 * resto. Um `useEffect` a chamar `setData` competia com o que o admin está a
 * escrever no momento em que a resposta do servidor chega.
 */
export function PrinterCreateDialog({
    open,
    onOpenChange,
    editing,
    isFirst,
}: Props) {
    const {
        data,
        setData,
        post,
        patch,
        processing,
        errors,
        reset,
        clearErrors,
    } = useForm<PrinterProfileFormData>({
        name: editing?.name ?? '',
        hourly_rate: editing
            ? centsToInput(editing.hourlyRateCents)
            : DEFAULT_RATE,
        notes: editing?.notes ?? '',
        sort_order: editing?.sortOrder ?? 0,
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

        const options = { onSuccess: () => close(false) };

        if (editing) {
            // A rota aceita PUT e PATCH; PATCH é o que diz a verdade sobre o
            // payload, que não traz o `is_default` nem o `active`.
            patch(update(editing.id).url, options);

            return;
        }

        post(store().url, options);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Editar impressora' : 'Nova impressora'}
                        </DialogTitle>
                        <DialogDescription>
                            {editing
                                ? 'O custo por hora entra em cada peça feita nesta máquina — mudá-lo muda o preço sugerido de tudo o que ela imprime.'
                                : isFirst
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
                            depreciação num número só. É por isso que a fórmula
                            não volta a somar energia nem desgaste em mais lado
                            nenhum.
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

                    {/*
                     * Só a editar: uma impressora acabada de nascer não tem
                     * posição para defender na listagem nem variantes para
                     * contar, e a coluna é `default(0)` na base de dados.
                     */}
                    {editing && (
                        <div className="grid gap-2 border-t border-border/60 pt-4">
                            <Label htmlFor="printer_sort_order">Ordem</Label>
                            <Input
                                id="printer_sort_order"
                                type="number"
                                min={0}
                                value={data.sort_order}
                                onChange={(event) =>
                                    setData(
                                        'sort_order',
                                        Number(event.target.value || 0),
                                    )
                                }
                                className="max-w-24 tabular-nums"
                            />
                            <p className="text-xs text-muted-foreground">
                                Menor primeiro, com a predefinida sempre à
                                frente. Em caso de empate manda o nome.
                            </p>
                            <InputError message={errors.sort_order} />

                            {editing.variantsCount > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    {editing.variantsCount}{' '}
                                    {editing.variantsCount === 1
                                        ? 'variante depende'
                                        : 'variantes dependem'}{' '}
                                    desta máquina. Arquivá-la tira-a do seletor
                                    sem lhes mexer.
                                </p>
                            )}
                        </div>
                    )}

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
                            {editing
                                ? 'Guardar alterações'
                                : 'Criar impressora'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
