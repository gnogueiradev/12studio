import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { ColorSwatchGrid } from '@/components/admin/color-swatch-grid';
import { PricingBreakdown } from '@/components/admin/pricing-breakdown';
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
import { centsToInput, formatCents, inputToCents } from '@/lib/money';
import type {
    ColorOption,
    MaterialOption,
    VariantFormData,
} from '@/types/catalog';
import type {
    PricingBreakdown as Breakdown,
    PrinterProfileOption,
} from '@/types/pricing';

/** O que o PricingPreview do servidor devolve para esta variante. */
export type VariantPricingPreview = {
    result: Breakdown | null;
    hourlyRateCents: number;
    usingFallbackRate: boolean;
    printerProfileId: number | null;
};

type Props = {
    data: VariantFormData;
    setData: <K extends keyof VariantFormData>(
        key: K,
        value: VariantFormData[K],
    ) => void;
    errors: Partial<Record<keyof VariantFormData, string>>;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
    colors: ColorOption[];
    materials: MaterialOption[];
    printers: PrinterProfileOption[];
    /** Calculada no servidor e recarregada em `only: ['pricing']`. */
    pricing: VariantPricingPreview;
    /** Unidades presas a pagamentos pendentes — só existem a partir da Fase 3. */
    reservedStock?: number;
};

const NO_MATERIAL = 'none';

/** O valor do seletor que quer dizer "usa a que estiver predefinida". */
const DEFAULT_PRINTER = 'default';

/** Espera antes de pedir um preço novo ao servidor. */
const DEBOUNCE_MS = 300;

export default function VariantForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
    colors,
    materials,
    printers,
    pricing,
    reservedStock = 0,
}: Props) {
    const printMinutes = data.printing_time_minutes ?? 0;

    const setPrintTime = (hours: number, minutes: number) =>
        setData('printing_time_minutes', hours * 60 + minutes);

    const defaultPrinter = printers.find((printer) => printer.isDefault);
    const chosenPrinter =
        printers.find((printer) => printer.id === data.printer_profile_id) ??
        defaultPrinter;

    /*
     * O preço vem do SERVIDOR — o formulário não espelha a fórmula. É o mesmo
     * motor que calcula esta pré-visualização e o preço que fica gravado, por
     * isso não há duas versões a divergir.
     *
     * `only: ['pricing']` recarrega só esta prop: o resto da página (a listagem
     * de produtos por trás, o produto que o modal está a editar, e o que o
     * admin já escreveu nos outros campos) fica intacto.
     */
    const debounced = useRef(false);

    useEffect(() => {
        const request = () =>
            router.reload({
                only: ['pricing'],
                data: {
                    weight_grams: data.filament_weight_grams ?? 0,
                    hours: Math.floor(printMinutes / 60),
                    minutes: printMinutes % 60,
                    // O MATERIAL e não a cor: é a bobine que tem preço/kg.
                    // Deixar a cor aqui era disparar um recálculo idêntico a
                    // cada troca de tom.
                    material_id: data.material_id,
                    printer_profile_id: data.printer_profile_id,
                    extra_cost: data.extra_cost,
                },
            });

        /*
         * A PRIMEIRA vez não espera. A ficha vive dentro do modal do produto, e
         * a página que a serve não sabe qual das variantes vai ser aberta — o
         * `pricing` que veio com ela está vazio. Esperar aqui era abrir uma
         * variante já preenchida com o painel de custo em branco.
         *
         * Numa variante nova não há sequer pedido: sem peso e sem tempo o
         * servidor devolveria o mesmo "sem resultado" que já está na página.
         */
        if (!debounced.current) {
            debounced.current = true;

            if (printMinutes > 0 && (data.filament_weight_grams ?? 0) > 0) {
                request();
            }

            return;
        }

        const timer = setTimeout(request, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [
        data.filament_weight_grams,
        data.material_id,
        data.printer_profile_id,
        data.extra_cost,
        printMinutes,
    ]);

    /**
     * Escreve os preços sugeridos nos campos, sem gravar nada. O admin continua
     * a rever antes de submeter — e a regra cruzada "revenda <= venda" fica
     * satisfeita por construção, porque o preço ao cliente é sempre maior.
     */
    const applySuggestedPrices = () => {
        if (!pricing.result) {
            return;
        }

        setData('normal_price', centsToInput(pricing.result.retailPriceCents));
        setData(
            'wholesale_price',
            centsToInput(pricing.result.resalePriceCents),
        );
    };

    const normalCents = inputToCents(data.normal_price);
    const saleCents =
        data.sale_price === '' ? null : inputToCents(data.sale_price);
    const discount =
        saleCents !== null && normalCents > 0 && saleCents < normalCents
            ? Math.round(((normalCents - saleCents) / normalCents) * 100)
            : null;

    const hasColors = colors.length > 0;
    const hasMaterials = materials.length > 0;

    // Largura cheia: o único sítio onde este formulário aparece é o modal do
    // produto, e é ele que já dá a medida.
    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-6">
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="sku">SKU</Label>
                    <Input
                        id="sku"
                        value={data.sku}
                        onChange={(event) => setData('sku', event.target.value)}
                        required
                        autoFocus
                        maxLength={60}
                    />
                    <InputError message={errors.sku} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="size_label">Tamanho / designação</Label>
                    <Input
                        id="size_label"
                        value={data.size_label}
                        onChange={(event) =>
                            setData('size_label', event.target.value)
                        }
                        maxLength={60}
                        placeholder="20 cm"
                    />
                    <InputError message={errors.size_label} />
                </div>
            </div>

            {/*
             * Cor e material lado a lado, duas listas planas: são dois eixos
             * independentes. Só o material influencia o preço — a cor é o tom.
             */}
            <div className="grid gap-4">
                <div className="grid gap-2">
                    <Label>Cor</Label>
                    {/*
                     * Amostras e não um seletor fechado: uma cor reconhece-se
                     * pelo tom antes de se ler o nome, e escolher entre dois
                     * verdes obrigava a abrir a lista e comparar duas bolinhas.
                     */}
                    <ColorSwatchGrid
                        colors={colors}
                        value={data.color_id}
                        onChange={(id) => setData('color_id', id)}
                        emptyLabel="Sem cor"
                    />
                    {!hasColors && (
                        <p className="text-xs text-muted-foreground">
                            Ainda não há cores. Cria-as em Cores para as poderes
                            escolher aqui.
                        </p>
                    )}
                    <InputError message={errors.color_id} />
                </div>

                <div className="grid gap-2 sm:max-w-sm">
                    <Label>Material</Label>
                    <Select
                        value={
                            data.material_id === null
                                ? NO_MATERIAL
                                : String(data.material_id)
                        }
                        onValueChange={(value) =>
                            setData(
                                'material_id',
                                value === NO_MATERIAL ? null : Number(value),
                            )
                        }
                        disabled={!hasMaterials}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Sem material" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NO_MATERIAL}>
                                Sem material
                            </SelectItem>
                            {materials.map((material) => (
                                <SelectItem
                                    key={material.id}
                                    value={String(material.id)}
                                >
                                    <span className="flex items-center gap-2">
                                        {material.name}
                                        <span className="text-muted-foreground tabular-nums">
                                            {formatCents(
                                                material.pricePerKgCents,
                                            )}
                                            /kg
                                        </span>
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {!hasMaterials && (
                        <p className="text-xs text-muted-foreground">
                            Ainda não há materiais. Cria-os em Materiais.
                        </p>
                    )}
                    <InputError message={errors.material_id} />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="normal_price">
                        Preço normal (€, IVA incluído)
                    </Label>
                    <Input
                        id="normal_price"
                        type="number"
                        step="0.01"
                        min={0}
                        value={data.normal_price}
                        onChange={(event) =>
                            setData('normal_price', event.target.value)
                        }
                        required
                    />
                    <InputError message={errors.normal_price} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="sale_price">Preço promocional (€)</Label>
                    <Input
                        id="sale_price"
                        type="number"
                        step="0.01"
                        min={0}
                        value={data.sale_price}
                        onChange={(event) =>
                            setData('sale_price', event.target.value)
                        }
                        placeholder="Opcional"
                    />
                    <InputError message={errors.sale_price} />
                </div>
            </div>

            {discount !== null && (
                <p className="-mt-2 text-sm text-muted-foreground">
                    A montra mostra{' '}
                    <strong className="text-foreground">
                        {formatCents(saleCents ?? 0)}
                    </strong>{' '}
                    com <s>{formatCents(normalCents)}</s> riscado — menos{' '}
                    {discount}%.
                </p>
            )}

            <div className="grid grid-cols-2 gap-4 border-t border-border/60 pt-6">
                <div className="grid gap-2">
                    <Label htmlFor="wholesale_price">
                        Preço de revenda (€)
                    </Label>
                    <Input
                        id="wholesale_price"
                        type="number"
                        step="0.01"
                        min={0}
                        value={data.wholesale_price}
                        onChange={(event) =>
                            setData('wholesale_price', event.target.value)
                        }
                        placeholder="Opcional"
                    />
                    <p className="text-xs text-muted-foreground">
                        Só no backoffice. Aplicas com um clique numa encomenda
                        manual; a montra nunca o mostra.
                    </p>
                    <InputError message={errors.wholesale_price} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="filament_weight_grams">Gramagem (g)</Label>
                    <Input
                        id="filament_weight_grams"
                        type="number"
                        min={0}
                        value={data.filament_weight_grams ?? ''}
                        onChange={(event) =>
                            setData(
                                'filament_weight_grams',
                                event.target.value === ''
                                    ? null
                                    : Number(event.target.value),
                            )
                        }
                        placeholder="Opcional"
                    />
                    <p className="text-xs text-muted-foreground">
                        Filamento gasto nesta variante. Alimenta o cálculo de
                        custo.
                    </p>
                    <InputError message={errors.filament_weight_grams} />
                </div>
            </div>

            <div className="flex flex-col gap-4 border-t border-border/60 pt-6">
                <div>
                    <h2 className="text-sm font-semibold">Custo de produção</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Duas peças com o mesmo plástico podem demorar 30 minutos
                        ou 4 horas — e não custam o mesmo. É o tempo que decide.
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <fieldset className="grid gap-2">
                        {/*
                         * Horas e minutos separados: "1,30" tanto se lê como uma
                         * hora e trinta como 1,3 horas, e a diferença são 12
                         * minutos de máquina em cada peça. A BD guarda o total
                         * em minutos.
                         */}
                        <legend className="mb-2 text-sm font-medium">
                            Tempo de impressão
                        </legend>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label
                                    htmlFor="print_hours"
                                    className="text-xs font-normal text-muted-foreground"
                                >
                                    Horas
                                </Label>
                                <Input
                                    id="print_hours"
                                    type="number"
                                    min={0}
                                    max={999}
                                    value={Math.floor(printMinutes / 60) || ''}
                                    onChange={(event) =>
                                        setPrintTime(
                                            Number(event.target.value || 0),
                                            printMinutes % 60,
                                        )
                                    }
                                    placeholder="0"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label
                                    htmlFor="print_minutes"
                                    className="text-xs font-normal text-muted-foreground"
                                >
                                    Minutos
                                </Label>
                                <Input
                                    id="print_minutes"
                                    type="number"
                                    min={0}
                                    max={59}
                                    value={printMinutes % 60 || ''}
                                    onChange={(event) =>
                                        setPrintTime(
                                            Math.floor(printMinutes / 60),
                                            Number(event.target.value || 0),
                                        )
                                    }
                                    placeholder="0"
                                />
                            </div>
                        </div>
                        <InputError message={errors.printing_time_minutes} />
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor="extra_cost">
                            Custos adicionais (€)
                        </Label>
                        <Input
                            id="extra_cost"
                            type="number"
                            step="0.01"
                            min={0}
                            value={data.extra_cost}
                            onChange={(event) =>
                                setData('extra_cost', event.target.value)
                            }
                            placeholder="0.00"
                        />
                        <p className="text-xs text-muted-foreground">
                            Ímanes, feltro, argolas, caixa. Entram a cru: não
                            pagam reserva de falha.
                        </p>
                        <InputError message={errors.extra_cost} />
                    </div>
                </div>

                <div className="grid gap-2">
                    <Label>Impressora</Label>
                    <Select
                        value={
                            data.printer_profile_id === null
                                ? DEFAULT_PRINTER
                                : String(data.printer_profile_id)
                        }
                        onValueChange={(value) =>
                            setData(
                                'printer_profile_id',
                                value === DEFAULT_PRINTER
                                    ? null
                                    : Number(value),
                            )
                        }
                        disabled={printers.length === 0}
                    >
                        <SelectTrigger aria-label="Impressora">
                            <SelectValue placeholder="Predefinida" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={DEFAULT_PRINTER}>
                                {defaultPrinter
                                    ? `Predefinida (${defaultPrinter.name})`
                                    : 'Predefinida'}
                            </SelectItem>
                            {printers.map((printer) => (
                                <SelectItem
                                    key={printer.id}
                                    value={String(printer.id)}
                                >
                                    {printer.name} —{' '}
                                    {formatCents(printer.hourlyRateCents)}/h
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.printer_profile_id} />
                </div>

                <PricingBreakdown
                    result={pricing.result}
                    printTimeMinutes={printMinutes}
                    hourlyRateCents={pricing.hourlyRateCents}
                    printerName={chosenPrinter?.name ?? null}
                    usingFallbackRate={pricing.usingFallbackRate}
                    emptyHint="Preenche a gramagem e o tempo de impressão para veres o custo e o preço sugerido."
                    action={
                        pricing.result && (
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={applySuggestedPrices}
                                >
                                    Aplicar preços
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    Preenche revenda e preço normal com os
                                    valores sugeridos. Ainda podes mexer antes
                                    de gravar.
                                </p>
                            </div>
                        )
                    }
                />
            </div>

            <div className="grid grid-cols-2 gap-4 border-t border-border/60 pt-6">
                <div className="grid gap-2">
                    <Label htmlFor="stock">Stock</Label>
                    <Input
                        id="stock"
                        type="number"
                        min={0}
                        value={data.stock}
                        onChange={(event) =>
                            setData('stock', Number(event.target.value))
                        }
                        required
                    />
                    <p className="text-xs text-muted-foreground">
                        Alterar aqui grava um movimento de stock com o teu nome.
                        {reservedStock > 0 &&
                            ` ${reservedStock} unidade(s) reservadas por pagamentos pendentes.`}
                    </p>
                    <InputError message={errors.stock} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="low_stock_threshold">
                        Alerta de stock baixo
                    </Label>
                    <Input
                        id="low_stock_threshold"
                        type="number"
                        min={0}
                        value={data.low_stock_threshold}
                        onChange={(event) =>
                            setData(
                                'low_stock_threshold',
                                Number(event.target.value),
                            )
                        }
                        required
                    />
                    <InputError message={errors.low_stock_threshold} />
                </div>
            </div>

            <div className="flex flex-col gap-3">
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox
                        checked={data.is_default}
                        onCheckedChange={(checked) =>
                            setData('is_default', checked === true)
                        }
                    />
                    Variante principal (preço mostrado na montra)
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
            </div>

            <div>
                <Button
                    type="submit"
                    className="rounded-full"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
