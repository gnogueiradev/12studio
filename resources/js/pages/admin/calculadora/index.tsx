import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import { PricingBreakdown } from '@/components/admin/pricing-breakdown';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCents } from '@/lib/money';
import type { Option } from '@/lib/options';
import { cn } from '@/lib/utils';
import { calculadora } from '@/routes/admin';
import { index as impressorasIndex } from '@/routes/admin/impressoras';
import { index as materiaisIndex } from '@/routes/admin/materiais';
import type { MaterialOption } from '@/types/catalog';
import type {
    PricingBreakdown as Breakdown,
    PricingFormFields,
    PricingMode,
    PrinterProfileOption,
} from '@/types/pricing';
import { PRICING_MODES } from '@/types/pricing';

type Props = {
    inputs: PricingFormFields;
    /** Null enquanto faltar peso ou tempo. */
    result: Breakdown | null;
    hourlyRateCents: number;
    usingFallbackRate: boolean;
    printers: PrinterProfileOption[];
    materials: MaterialOption[];
    modes: Option[];
};

/**
 * Espera antes de pedir um novo cálculo. Curto o suficiente para parecer ao
 * vivo, longo o suficiente para não disparar um pedido por tecla.
 */
const DEBOUNCE_MS = 300;

/**
 * Calculadora de preços.
 *
 * O cálculo é do SERVIDOR, sempre — a página não espelha a fórmula. Duas razões
 * concretas: o projeto não tem runner de testes JavaScript, e os números caem em
 * cima das fronteiras de arredondamento (6,125 € é exatamente meio degrau de
 * 0,50 €), portanto um espelho em vírgula flutuante discordaria do servidor num
 * cêntimo de vez em quando — sem nunca falhar teste nenhum.
 *
 * O estado vive no URL. Um preço fica partilhável, e o botão "recuar" do browser
 * faz o que se espera.
 */
export default function CalculadoraIndex({
    inputs,
    result,
    hourlyRateCents,
    usingFallbackRate,
    printers,
    materials,
    modes,
}: Props) {
    const [fields, setFields] = useState<PricingFormFields>(inputs);

    /*
     * O primeiro render não pode pedir nada: as props que acabaram de chegar já
     * correspondem a estes campos, e um pedido aqui era um round-trip para
     * confirmar o que a página já sabe.
     */
    const mounted = useRef(false);

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;

            return;
        }

        const timer = setTimeout(() => {
            router.get(
                calculadora().url,
                { ...fields },
                {
                    only: [
                        'inputs',
                        'result',
                        'hourlyRateCents',
                        'usingFallbackRate',
                    ],
                    preserveState: true,
                    preserveScroll: true,
                    // `replace` para vinte teclas não deixarem vinte entradas no
                    // histórico — recuar tem de sair da calculadora, não desfazer
                    // um dígito de cada vez.
                    replace: true,
                },
            );
        }, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [fields]);

    const patch = (changes: Partial<PricingFormFields>) =>
        setFields((current) => ({ ...current, ...changes }));

    const printer = printers.find((p) => p.id === fields.printer_profile_id);
    const isBatch = fields.mode === PRICING_MODES.batch;
    const hasMaterials = materials.length > 0;

    return (
        <>
            <Head title="Calculadora de preços" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Calculadora de preços"
                    description="Quanto custa imprimir uma peça, e por quanto vendê-la. O tempo de impressão conta tanto como o plástico."
                />

                <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                    <Panel
                        title="O trabalho"
                        description="Os números que o slicer te dá."
                    >
                        <div className="flex flex-col gap-4">
                            <div className="grid gap-2">
                                <Label>Como é impresso</Label>
                                {/*
                                 * Botões e não um select: são duas opções, e a
                                 * escolha muda o significado dos campos que
                                 * estão logo por baixo — vale a pena ver as
                                 * duas ao mesmo tempo.
                                 */}
                                <div className="flex gap-2">
                                    {modes.map((mode) => (
                                        <button
                                            key={mode.value}
                                            type="button"
                                            aria-pressed={
                                                fields.mode === mode.value
                                            }
                                            onClick={() =>
                                                patch({
                                                    mode: mode.value as PricingMode,
                                                })
                                            }
                                            className={cn(
                                                'flex-1 rounded-lg border px-3 py-2 text-xs transition-colors',
                                                fields.mode === mode.value
                                                    ? 'border-primary bg-primary/10 font-medium text-foreground'
                                                    : 'border-border/60 text-muted-foreground hover:border-border',
                                            )}
                                        >
                                            {mode.label}
                                        </button>
                                    ))}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {isBatch
                                        ? 'Dá o peso e o tempo da mesa inteira — é o que o slicer mostra, e é o tempo que a impressora fica ocupada.'
                                        : 'Dá o peso e o tempo de UMA peça. A quantidade só multiplica no fim.'}
                                </p>
                            </div>

                            {/*
                             * Material e não cor: é a bobine que tem preço/kg.
                             * O €/kg aparece dentro da própria opção, tal como o
                             * custo/hora da impressora — é precisamente aquilo
                             * sobre que se está a decidir.
                             *
                             * Não há campo de preço separado, de propósito: era
                             * o mesmo facto escrito em dois sítios, e o segundo
                             * ficava a mostrar o valor antigo depois de se
                             * trocar de filamento. Um valor que não existe em
                             * estado não pode ficar desatualizado.
                             */}
                            <div className="grid gap-2">
                                <Label>Material / filamento</Label>
                                <Select
                                    value={
                                        fields.material_id === null
                                            ? ''
                                            : String(fields.material_id)
                                    }
                                    onValueChange={(value) =>
                                        patch({ material_id: Number(value) })
                                    }
                                    disabled={!hasMaterials}
                                >
                                    <SelectTrigger aria-label="Material">
                                        <SelectValue
                                            placeholder={
                                                hasMaterials
                                                    ? 'Escolhe um filamento'
                                                    : 'Nenhum criado'
                                            }
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {materials.map((material) => (
                                            <SelectItem
                                                key={material.id}
                                                value={String(material.id)}
                                            >
                                                {material.name} —{' '}
                                                {formatCents(
                                                    material.pricePerKgCents,
                                                )}
                                                /kg
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    {hasMaterials
                                        ? 'É a bobine que tem preço: o €/kg vem do filamento escolhido.'
                                        : 'Sem filamentos criados não há preço nenhum para calcular.'}{' '}
                                    <Link
                                        href={materiaisIndex()}
                                        className="underline underline-offset-2"
                                    >
                                        Gerir materiais
                                    </Link>
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="weight_grams">
                                    {isBatch
                                        ? 'Peso da mesa (g)'
                                        : 'Peso da peça (g)'}
                                </Label>
                                <Input
                                    id="weight_grams"
                                    type="number"
                                    min={0}
                                    value={fields.weight_grams || ''}
                                    onChange={(event) =>
                                        patch({
                                            weight_grams: Number(
                                                event.target.value || 0,
                                            ),
                                        })
                                    }
                                />
                            </div>

                            <fieldset className="grid gap-2">
                                {/*
                                 * Horas e minutos separados de propósito:
                                 * "1,30" tanto se lê como uma hora e trinta
                                 * como 1,3 horas, e a diferença são 12 minutos
                                 * de máquina em cada peça.
                                 */}
                                <legend className="mb-2 text-sm font-medium">
                                    {isBatch
                                        ? 'Tempo da mesa'
                                        : 'Tempo de impressão'}
                                </legend>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="hours"
                                            className="text-xs font-normal text-muted-foreground"
                                        >
                                            Horas
                                        </Label>
                                        <Input
                                            id="hours"
                                            type="number"
                                            min={0}
                                            max={999}
                                            value={fields.hours || ''}
                                            onChange={(event) =>
                                                patch({
                                                    hours: Number(
                                                        event.target.value || 0,
                                                    ),
                                                })
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="minutes"
                                            className="text-xs font-normal text-muted-foreground"
                                        >
                                            Minutos
                                        </Label>
                                        <Input
                                            id="minutes"
                                            type="number"
                                            min={0}
                                            max={59}
                                            value={fields.minutes || ''}
                                            onChange={(event) =>
                                                patch({
                                                    minutes: Number(
                                                        event.target.value || 0,
                                                    ),
                                                })
                                            }
                                        />
                                    </div>
                                </div>
                            </fieldset>

                            <div className="grid gap-2">
                                <Label>Impressora</Label>
                                <Select
                                    value={
                                        fields.printer_profile_id === null
                                            ? ''
                                            : String(fields.printer_profile_id)
                                    }
                                    onValueChange={(value) =>
                                        patch({
                                            printer_profile_id: Number(value),
                                        })
                                    }
                                    disabled={printers.length === 0}
                                >
                                    <SelectTrigger aria-label="Impressora">
                                        <SelectValue placeholder="Nenhuma configurada" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {printers.map((option) => (
                                            <SelectItem
                                                key={option.id}
                                                value={String(option.id)}
                                            >
                                                {option.name} —{' '}
                                                {formatCents(
                                                    option.hourlyRateCents,
                                                )}
                                                /h
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    O custo/hora cobre energia, desgaste e
                                    manutenção.{' '}
                                    <Link
                                        href={impressorasIndex()}
                                        className="underline underline-offset-2"
                                    >
                                        Gerir impressoras
                                    </Link>
                                </p>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="quantity">Quantidade</Label>
                                    <Input
                                        id="quantity"
                                        type="number"
                                        min={1}
                                        value={fields.quantity}
                                        onChange={(event) =>
                                            patch({
                                                quantity: Math.max(
                                                    1,
                                                    Number(
                                                        event.target.value || 1,
                                                    ),
                                                ),
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="extra_cost">
                                        Custos extra (€)
                                    </Label>
                                    <Input
                                        id="extra_cost"
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        value={fields.extra_cost}
                                        onChange={(event) =>
                                            patch({
                                                extra_cost: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                            <p className="-mt-2 text-xs text-muted-foreground">
                                Ímanes, feltro, argolas, caixa. Entram a cru:
                                não pagam reserva de falha, porque só se juntam
                                depois de a peça estar feita.
                            </p>
                        </div>
                    </Panel>

                    <div className="flex flex-col gap-4">
                        <PricingBreakdown
                            result={result}
                            printTimeMinutes={
                                fields.hours * 60 + fields.minutes
                            }
                            hourlyRateCents={hourlyRateCents}
                            printerName={printer?.name ?? null}
                            usingFallbackRate={usingFallbackRate}
                            emptyHint="Escolhe o filamento e preenche o peso e o tempo de impressão para ver o preço."
                        />

                        {/*
                         * `role="status"` porque os números mudam sozinhos
                         * depois de se parar de escrever: sem isto, quem não vê
                         * o ecrã não fica a saber que houve resultado novo.
                         */}
                        <p role="status" className="sr-only">
                            {result === null
                                ? 'Sem cálculo: falta o filamento, o peso ou o tempo.'
                                : `Custo ${formatCents(result.productionCostCents)}, revenda ${formatCents(result.resalePriceCents)}, cliente ${formatCents(result.retailPriceCents)}.`}
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}

CalculadoraIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Calculadora', href: calculadora() },
    ],
};
