import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCents, inputToCents } from '@/lib/money';
import type { PrinterProfileFormData } from '@/types/pricing';

type Props = {
    data: PrinterProfileFormData;
    setData: <K extends keyof PrinterProfileFormData>(
        key: K,
        value: PrinterProfileFormData[K],
    ) => void;
    errors: Partial<Record<keyof PrinterProfileFormData, string>>;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
    /** Quantas variantes já dependem desta máquina. Só na edição. */
    variantsCount?: number;
};

/** Uma peça de 1h30 nesta impressora — a leitura que torna a taxa concreta. */
const EXAMPLE_MINUTES = 90;

export default function PrinterForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
    variantsCount = 0,
}: Props) {
    const rateCents = inputToCents(data.hourly_rate);
    const exampleCents = Math.round((rateCents * EXAMPLE_MINUTES) / 60);

    return (
        <form onSubmit={onSubmit} className="flex max-w-xl flex-col gap-6">
            <div className="grid gap-2">
                <Label htmlFor="name">Nome</Label>
                <Input
                    id="name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    required
                    autoFocus
                    maxLength={60}
                    placeholder="Bambu Lab A1"
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="hourly_rate">Custo por hora (€)</Label>
                <Input
                    id="hourly_rate"
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
                    Não é só eletricidade: junta desgaste, correias, rolamentos,
                    hotend, nozzle, mesa, manutenção, consumíveis e depreciação
                    num número só. É por isso que a fórmula não volta a somar
                    energia nem desgaste em mais lado nenhum.
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
                <Label htmlFor="notes">Notas</Label>
                <Input
                    id="notes"
                    value={data.notes}
                    onChange={(event) => setData('notes', event.target.value)}
                    maxLength={255}
                    placeholder="Opcional"
                />
                <InputError message={errors.notes} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="sort_order">Ordem</Label>
                <Input
                    id="sort_order"
                    type="number"
                    min={0}
                    value={data.sort_order}
                    onChange={(event) =>
                        setData('sort_order', Number(event.target.value || 0))
                    }
                    className="max-w-24"
                />
                <InputError message={errors.sort_order} />
            </div>

            <div className="flex flex-col gap-3 border-t border-border/60 pt-6">
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox
                        checked={data.is_default}
                        onCheckedChange={(checked) =>
                            setData('is_default', checked === true)
                        }
                    />
                    Impressora predefinida da calculadora
                </Label>

                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox
                        checked={data.active}
                        onCheckedChange={(checked) =>
                            setData('active', checked === true)
                        }
                    />
                    Ativa
                </Label>

                {variantsCount > 0 && (
                    <p className="text-xs text-muted-foreground">
                        {variantsCount}{' '}
                        {variantsCount === 1
                            ? 'variante depende'
                            : 'variantes dependem'}{' '}
                        desta máquina. Arquivá-la tira-a do seletor sem lhes
                        mexer.
                    </p>
                )}
            </div>

            <div>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
