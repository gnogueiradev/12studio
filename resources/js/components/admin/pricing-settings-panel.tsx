import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { precos } from '@/routes/admin/definicoes';
import type { PricingSettingsForm } from '@/types/pricing';

type Props = {
    pricing: PricingSettingsForm;
};

/**
 * Os parâmetros da calculadora de preços.
 *
 * O que NÃO está aqui, de propósito: o degrau de 0,50 € do preço de revenda e as
 * faixas de arredondamento do preço ao cliente. São regras comerciais fixas —
 * ninguém anuncia uma peça a 63,40 € — e mexer-lhes partia a lista de preços em
 * vez de a afinar. O custo/hora também não: esse pertence a cada impressora.
 */
export function PricingSettingsPanel({ pricing }: Props) {
    const { data, setData, patch, processing, errors } =
        useForm<PricingSettingsForm>({ ...pricing });

    const [saved, setSaved] = useState(false);

    const dirty = JSON.stringify(data) !== JSON.stringify(pricing);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(precos().url, { onSuccess: () => setSaved(true) });
    };

    /*
     * Atualiza pelo objeto inteiro e não por chave: o `setData(key, value)` do
     * Inertia tem um tipo condicional que não sobrevive a um wrapper genérico.
     */
    const change = <K extends keyof PricingSettingsForm>(
        key: K,
        value: PricingSettingsForm[K],
    ) => {
        setSaved(false);
        setData((current) => ({ ...current, [key]: value }));
    };

    const status = saved
        ? {
              text: 'Guardado. Os preços novos valem já.',
              className: 'text-success',
          }
        : dirty
          ? { text: 'Alterações por guardar.', className: 'text-warning' }
          : {
                text: 'Sem alterações por guardar.',
                className: 'text-muted-foreground',
            };

    return (
        <form onSubmit={submit} className="flex max-w-2xl flex-col gap-4">
            <Panel
                title="Preços 3D"
                description="Como a calculadora transforma o custo de produção num preço de revenda e num preço ao cliente. O custo por hora de cada máquina vive em Impressoras."
            >
                <div className="flex flex-col gap-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="failure_reserve_percent"
                            label="Reserva para falhas (%)"
                            hint="Sobre filamento + máquina. Os custos extra não pagam risco — só se juntam depois de a peça estar feita."
                            value={data.failure_reserve_percent}
                            error={errors.failure_reserve_percent}
                            onChange={(value) =>
                                change('failure_reserve_percent', value)
                            }
                        />
                        <Field
                            id="handling_cost"
                            label="Manuseamento por peça (€)"
                            hint="Preparar o ficheiro, tirar da mesa, limpar, separar suportes, embalar. Trabalho que existe independentemente das horas da máquina."
                            value={data.handling_cost}
                            error={errors.handling_cost}
                            onChange={(value) => change('handling_cost', value)}
                        />
                        <Field
                            id="resale_multiplier"
                            label="Multiplicador de revenda (×)"
                            hint="Sobre o custo real. É a tua margem — 1,70× são 70% em cima do que a peça custou a fazer."
                            value={data.resale_multiplier}
                            error={errors.resale_multiplier}
                            onChange={(value) =>
                                change('resale_multiplier', value)
                            }
                        />
                        <Field
                            id="minimum_resale_price"
                            label="Preço mínimo de revenda (€)"
                            hint="O chão para peças muito pequenas — nem que seja pelo saco, a etiqueta e o tempo de atender."
                            value={data.minimum_resale_price}
                            error={errors.minimum_resale_price}
                            onChange={(value) =>
                                change('minimum_resale_price', value)
                            }
                        />
                        <Field
                            id="retail_multiplier"
                            label="Multiplicador do cliente final (×)"
                            hint="Sobre o preço de revenda. É a margem de quem revende."
                            value={data.retail_multiplier}
                            error={errors.retail_multiplier}
                            onChange={(value) =>
                                change('retail_multiplier', value)
                            }
                        />
                        <Field
                            id="minimum_retail_multiplier"
                            label="Multiplicador mínimo (×)"
                            hint="Rede de segurança: o arredondamento comercial também desce, e não pode deixar o revendedor abaixo disto."
                            value={data.minimum_retail_multiplier}
                            error={errors.minimum_retail_multiplier}
                            onChange={(value) =>
                                change('minimum_retail_multiplier', value)
                            }
                        />
                    </div>

                    <fieldset className="border-t border-border/60 pt-6">
                        <legend className="text-sm font-medium">
                            Manuseamento em lote
                        </legend>
                        <p className="mt-1 mb-3 text-sm text-muted-foreground">
                            Numa mesa com várias peças o custo fixo por peça não
                            se usa: o trabalho decompõe-se no que se faz uma vez
                            (montar, tirar a placa) e no que se faz a cada peça
                            (rebarbar, ensacar).
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id="batch_job_handling"
                                label="Por impressão (€)"
                                value={data.batch_job_handling}
                                error={errors.batch_job_handling}
                                onChange={(value) =>
                                    change('batch_job_handling', value)
                                }
                            />
                            <Field
                                id="batch_unit_handling"
                                label="Por unidade (€)"
                                value={data.batch_unit_handling}
                                error={errors.batch_unit_handling}
                                onChange={(value) =>
                                    change('batch_unit_handling', value)
                                }
                            />
                        </div>
                    </fieldset>
                </div>
            </Panel>

            <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" disabled={!dirty || processing}>
                    {processing && <Spinner />}
                    Guardar preços
                </Button>
                <p role="status" className={cn('text-sm', status.className)}>
                    {status.text}
                </p>
            </div>
        </form>
    );
}

function Field({
    id,
    label,
    hint,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type="number"
                step="0.01"
                min={0}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                required
                className="max-w-40"
            />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}
