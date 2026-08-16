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
import {
    centsToInput,
    formatCents,
    formatMicros,
    inputToCents,
    inputToMicros,
    microsToInput,
} from '@/lib/money';
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
    /** A tarifa global, só para pré-visualizar o €/h enquanto se escreve. */
    electricityPriceMicrosPerKwh: number;
};

/** Peça de referência para o custo/hora deixar de ser um número abstrato. */
const EXAMPLE_MINUTES = 90;

/** Onde a primeira impressora começa: os números de uma Bambu Lab A1. */
const DEFAULTS = {
    watts: 145,
    purchasePrice: '400.00',
    lifetimeHours: 4000,
    maintenanceRate: '0.0400',
};

/**
 * Criar e editar uma impressora — os dois no mesmo sítio, para não haver dois
 * formulários da mesma coisa a divergir com o tempo.
 *
 * Aqui viveu um "custo por hora" único, e o problema dele era este: quem o
 * preenchia a pensar só em eletricidade ficava com peças a metade do preço, e
 * nada no ecrã dizia o que é que faltava lá dentro. Agora pedem-se os quatro
 * números de que ele se faz, e o €/h aparece calculado por baixo.
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
    electricityPriceMicrosPerKwh,
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
        average_power_watts: editing?.averagePowerWatts ?? DEFAULTS.watts,
        purchase_price: editing
            ? centsToInput(editing.purchasePriceCents)
            : DEFAULTS.purchasePrice,
        lifetime_hours: editing?.lifetimeHours ?? DEFAULTS.lifetimeHours,
        maintenance_rate: editing
            ? microsToInput(editing.maintenanceMicrosPerHour)
            : DEFAULTS.maintenanceRate,
        notes: editing?.notes ?? '',
        sort_order: editing?.sortOrder ?? 0,
    });

    /*
     * Pré-visualização e mais nada: a autoridade continua a ser o servidor, que
     * faz esta conta em inteiros. Aqui basta ser aproximadamente certa para o
     * admin ver o efeito do que está a escrever.
     */
    const hourlyCostMicros =
        (data.average_power_watts * electricityPriceMicrosPerKwh) / 1000 +
        (data.lifetime_hours > 0
            ? (inputToCents(data.purchase_price) * 10_000) / data.lifetime_hours
            : 0) +
        inputToMicros(data.maintenance_rate);

    const exampleCents = Math.round(
        (hourlyCostMicros * EXAMPLE_MINUTES) / 60 / 10_000,
    );

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
                                ? 'Estes quatro números entram em cada peça feita nesta máquina — mexer-lhes muda o preço sugerido de tudo o que ela imprime.'
                                : isFirst
                                  ? 'A primeira máquina fica logo a predefinida — é ela que a calculadora usa quando ninguém escolhe outra.'
                                  : 'Cada máquina tem os seus números, e são eles que fazem uma peça lenta custar mais do que uma rápida com o mesmo plástico.'}
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

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="printer_watts">
                                Potência média (W)
                            </Label>
                            <Input
                                id="printer_watts"
                                type="number"
                                min={0}
                                max={5000}
                                value={data.average_power_watts}
                                onChange={(event) =>
                                    setData(
                                        'average_power_watts',
                                        Number(event.target.value || 0),
                                    )
                                }
                                required
                                className="max-w-40 tabular-nums"
                            />
                            <p className="text-xs text-muted-foreground">
                                Durante a impressão, não em pico. 0,145 kWh/h
                                lê-se 145 W.
                            </p>
                            <InputError message={errors.average_power_watts} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="printer_maintenance">
                                Manutenção (€/h)
                            </Label>
                            <Input
                                id="printer_maintenance"
                                type="number"
                                step="0.0001"
                                min={0}
                                value={data.maintenance_rate}
                                onChange={(event) =>
                                    setData(
                                        'maintenance_rate',
                                        event.target.value,
                                    )
                                }
                                required
                                className="max-w-40 tabular-nums"
                            />
                            <p className="text-xs text-muted-foreground">
                                Reserva para nozzle, hotend, correias e peças de
                                desgaste.
                            </p>
                            <InputError message={errors.maintenance_rate} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="printer_purchase_price">
                                Preço de compra (€)
                            </Label>
                            <Input
                                id="printer_purchase_price"
                                type="number"
                                step="0.01"
                                min={0}
                                value={data.purchase_price}
                                onChange={(event) =>
                                    setData(
                                        'purchase_price',
                                        event.target.value,
                                    )
                                }
                                required
                                className="max-w-40 tabular-nums"
                            />
                            <InputError message={errors.purchase_price} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="printer_lifetime">
                                Vida útil (h)
                            </Label>
                            <Input
                                id="printer_lifetime"
                                type="number"
                                min={1}
                                value={data.lifetime_hours}
                                onChange={(event) =>
                                    setData(
                                        'lifetime_hours',
                                        Number(event.target.value || 0),
                                    )
                                }
                                required
                                className="max-w-40 tabular-nums"
                            />
                            <p className="text-xs text-muted-foreground">
                                A máquina paga-se a si própria nas peças que
                                faz.
                            </p>
                            <InputError message={errors.lifetime_hours} />
                        </div>
                    </div>

                    {hourlyCostMicros > 0 && (
                        <p className="rounded-lg border border-border/60 bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                            Sai a{' '}
                            <strong className="text-foreground tabular-nums">
                                {formatMicros(hourlyCostMicros)}
                            </strong>
                            /h de máquina — uma impressão de 1h30 custa{' '}
                            <strong className="text-foreground tabular-nums">
                                {formatCents(exampleCents)}
                            </strong>
                            . A tarifa da luz vem das definições; o valor a
                            valer é sempre o que o servidor calcula.
                        </p>
                    )}

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
