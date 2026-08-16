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
 * O que NÃO está aqui, de propósito:
 *
 *   1. os números da MÁQUINA — potência, preço de compra, vida útil,
 *      manutenção. São propriedades de cada impressora e vivem em Impressoras;
 *      duas máquinas não custam o mesmo por hora. O que é global é a tarifa da
 *      luz, porque o contrato é da casa;
 *   2. o degrau de 0,50 € do preço de revenda e as faixas de arredondamento do
 *      preço ao cliente. São regras comerciais fixas — ninguém anuncia uma peça
 *      a 63,40 € — e mexer-lhes partia a lista de preços em vez de a afinar.
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
                description="O que custa operar, quanto vale o teu tempo e que margens queres. Os números de cada máquina — potência, preço, vida útil, manutenção — vivem em Impressoras."
            >
                <div className="flex flex-col gap-6">
                    <fieldset>
                        <legend className="text-sm font-medium">
                            Custos de operação
                        </legend>
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <Field
                                id="electricity_price"
                                label="Preço da eletricidade (€/kWh)"
                                step="0.0001"
                                hint="Quatro casas: 0,1420 e 0,1440 €/kWh não são o mesmo tarifário, e a diferença multiplica-se por todas as horas do ano."
                                value={data.electricity_price}
                                error={errors.electricity_price}
                                onChange={(value) =>
                                    change('electricity_price', value)
                                }
                            />
                            <Field
                                id="labor_rate"
                                label="Valor do meu trabalho (€/h)"
                                hint="Só conta o tempo em que estás mesmo a mexer na peça. As horas em que a máquina trabalha sozinha pagam-se noutras parcelas."
                                value={data.labor_rate}
                                error={errors.labor_rate}
                                onChange={(value) =>
                                    change('labor_rate', value)
                                }
                            />
                            <Field
                                id="active_labor_minutes"
                                label="Trabalho ativo por peça (min)"
                                step="1"
                                hint="Preparar, trocar filamento, tirar da mesa, rebarbar, limpar, montar, embalar. Cada variante pode dizer outro número."
                                value={String(data.active_labor_minutes)}
                                error={errors.active_labor_minutes}
                                onChange={(value) =>
                                    change(
                                        'active_labor_minutes',
                                        Number(value || 0),
                                    )
                                }
                            />
                            <Field
                                id="setup_labor_minutes"
                                label="Preparação por impressão (min)"
                                step="1"
                                hint="Só em lote, e só uma vez por mesa: montar, lançar, tirar a placa. É isto que dilui quando se imprimem doze de uma vez."
                                value={String(data.setup_labor_minutes)}
                                error={errors.setup_labor_minutes}
                                onChange={(value) =>
                                    change(
                                        'setup_labor_minutes',
                                        Number(value || 0),
                                    )
                                }
                            />
                        </div>
                    </fieldset>

                    <fieldset className="border-t border-border/60 pt-6">
                        <legend className="text-sm font-medium">Risco</legend>
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <Field
                                id="failure_rate_percent"
                                label="Taxa de falhas (%)"
                                hint="Aplica-se ao custo TODO, e divide em vez de somar: o custo passa a custo ÷ (1 − taxa). Somar 5% recuperava menos do que se perdeu, porque a peça falhada também gastou filamento, luz e horas de máquina."
                                value={data.failure_rate_percent}
                                error={errors.failure_rate_percent}
                                onChange={(value) =>
                                    change('failure_rate_percent', value)
                                }
                            />
                        </div>
                    </fieldset>

                    <fieldset className="border-t border-border/60 pt-6">
                        <legend className="text-sm font-medium">Margens</legend>
                        <p className="mt-1 mb-3 text-sm text-muted-foreground">
                            Margens sobre a VENDA, não markup sobre o custo: 40%
                            quer dizer que 40 cêntimos de cada euro faturado
                            sobram. Como os dois arredondamentos são sempre para
                            cima, o que sai é um chão — a margem real fica igual
                            ou acima do que pedires aqui.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id="wholesale_margin_percent"
                                label="Margem de revenda (%)"
                                hint="A tua, quando vendes a quem revende."
                                value={data.wholesale_margin_percent}
                                error={errors.wholesale_margin_percent}
                                onChange={(value) =>
                                    change('wholesale_margin_percent', value)
                                }
                            />
                            <Field
                                id="reseller_margin_percent"
                                label="Margem do revendedor (%)"
                                hint="A que queres DEIXAR a quem te compra. O preço ao cliente sai do preço de revenda, e não do teu custo — é o que garante que uma loja consegue viver do que compra aqui."
                                value={data.reseller_margin_percent}
                                error={errors.reseller_margin_percent}
                                onChange={(value) =>
                                    change('reseller_margin_percent', value)
                                }
                            />
                            <Field
                                id="minimum_wholesale_price"
                                label="Preço mínimo de revenda (€)"
                                hint="O chão para peças muito pequenas — nem que seja pelo saco, a etiqueta e o tempo de atender."
                                value={data.minimum_wholesale_price}
                                error={errors.minimum_wholesale_price}
                                onChange={(value) =>
                                    change('minimum_wholesale_price', value)
                                }
                            />
                        </div>
                    </fieldset>

                    <fieldset className="border-t border-border/60 pt-6">
                        <legend className="text-sm font-medium">
                            Custos de venda
                        </legend>
                        <p className="mt-1 mb-3 text-sm text-muted-foreground">
                            Comissões de marketplace, terminal ou plataforma.
                            Não entram no custo de produção — não custam nada
                            produzir — e por isso não mexem no preço, só no que
                            sobra dele. A zero, o bloco do lucro líquido nem
                            aparece na calculadora.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id="channel_fixed_fee"
                                label="Taxa fixa por venda (€)"
                                value={data.channel_fixed_fee}
                                error={errors.channel_fixed_fee}
                                onChange={(value) =>
                                    change('channel_fixed_fee', value)
                                }
                            />
                            <Field
                                id="channel_percentage_fee"
                                label="Comissão (%)"
                                value={data.channel_percentage_fee}
                                error={errors.channel_percentage_fee}
                                onChange={(value) =>
                                    change('channel_percentage_fee', value)
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
    step = '0.01',
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    value: string;
    error?: string;
    /** "1" para os campos de minutos, "0.0001" para a tarifa da luz. */
    step?: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type="number"
                step={step}
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
