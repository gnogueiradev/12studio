import { useForm } from '@inertiajs/react';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ToggleChip } from '@/components/admin/toggle-chip';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatCents, formatCostPerGram, inputToCents } from '@/lib/money';
import { store } from '@/routes/admin/materiais';
import type { MaterialQuickFormData, PaletteColor } from '@/types/catalog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    palette: PaletteColor[];
    families: string[];
};

const NO_FAMILY = 'none';

/** Peça de referência para traduzir o custo/g em dinheiro que se reconhece. */
const SAMPLE_PIECE_GRAMS = 100;

/**
 * Criar um material e as suas primeiras cores de uma vez.
 *
 * As chips não são cores existentes: uma Color pertence a um Material, por isso
 * um material acabado de nascer não tem nenhuma. São presets da
 * App\Support\FilamentPalette, e o que ficar ligado é criado com o material na
 * mesma transação — sem cores o material não gera variante nenhuma, e obrigar a
 * uma segunda visita a /admin/cores era publicar um material inútil.
 */
export function MaterialCreateDialog({
    open,
    onOpenChange,
    palette,
    families,
}: Props) {
    const { data, setData, post, processing, errors, reset } =
        useForm<MaterialQuickFormData>({
            name: '',
            family: '',
            supplier: '',
            price_per_kg: '',
            min_spools: 0,
            colors: [],
        });

    const pricePerKgCents = inputToCents(data.price_per_kg);
    const canCreate =
        data.name.trim() !== '' &&
        pricePerKgCents > 0 &&
        data.colors.length > 0;

    const toggleColor = (name: string) =>
        setData(
            'colors',
            data.colors.includes(name)
                ? data.colors.filter((current) => current !== name)
                : [...data.colors, name],
        );

    const submit = () =>
        post(store().url, {
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-border/60 p-6">
                    <DialogTitle>Novo material</DialogTitle>
                    <DialogDescription>
                        O preço por quilo entra no cálculo do custo de cada
                        produto.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-6 p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="material-name">Nome</Label>
                            <Input
                                id="material-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                placeholder="PLA Silk"
                                maxLength={60}
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Família</Label>
                            <Select
                                value={
                                    data.family === '' ? NO_FAMILY : data.family
                                }
                                onValueChange={(value) =>
                                    setData(
                                        'family',
                                        value === NO_FAMILY ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Sem família" />
                                </SelectTrigger>
                                <SelectContent>
                                    {/* Radix não aceita value="" — daí o NO_FAMILY. */}
                                    <SelectItem value={NO_FAMILY}>
                                        Sem família
                                    </SelectItem>
                                    {families.map((family) => (
                                        <SelectItem key={family} value={family}>
                                            {family}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.family} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="material-price">
                                Preço por quilo
                            </Label>
                            <div className="relative">
                                <Input
                                    id="material-price"
                                    type="number"
                                    step="0.5"
                                    min={0}
                                    value={data.price_per_kg}
                                    onChange={(event) =>
                                        setData(
                                            'price_per_kg',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="21,90"
                                    className="pr-8 tabular-nums"
                                />
                                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                    €
                                </span>
                            </div>
                            <InputError message={errors.price_per_kg} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="material-supplier">
                                Fornecedor
                            </Label>
                            <Input
                                id="material-supplier"
                                value={data.supplier}
                                onChange={(event) =>
                                    setData('supplier', event.target.value)
                                }
                                placeholder="Prusament"
                                maxLength={60}
                            />
                            <InputError message={errors.supplier} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="material-min-spools">
                                Stock mínimo
                            </Label>
                            <div className="relative">
                                <Input
                                    id="material-min-spools"
                                    type="number"
                                    min={0}
                                    value={data.min_spools}
                                    onChange={(event) =>
                                        setData(
                                            'min_spools',
                                            Number(event.target.value),
                                        )
                                    }
                                    placeholder="3"
                                    className="pr-20 tabular-nums"
                                />
                                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                    bobines
                                </span>
                            </div>
                            <InputError message={errors.min_spools} />
                        </div>
                    </div>

                    {/*
                     * Contas ao vivo, só para dar noção — o servidor é que
                     * converte e grava. Zero significa "ainda não escreveu um
                     * preço", e mostrar "0,000 €" era fingir uma resposta.
                     */}
                    <div className="flex items-center justify-between gap-4 rounded-xl border border-border/60 bg-muted/40 p-4">
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Custo por grama
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Uma peça de {SAMPLE_PIECE_GRAMS} g fica em{' '}
                                <span className="tabular-nums">
                                    {pricePerKgCents > 0
                                        ? formatCents(
                                              Math.round(
                                                  (pricePerKgCents *
                                                      SAMPLE_PIECE_GRAMS) /
                                                      1000,
                                              ),
                                          )
                                        : '—'}
                                </span>
                                .
                            </p>
                        </div>
                        <p className="text-lg font-semibold tabular-nums">
                            {pricePerKgCents > 0
                                ? formatCostPerGram(pricePerKgCents)
                                : '—'}
                        </p>
                    </div>

                    <div className="grid gap-3 border-t border-border/60 pt-6">
                        <div className="flex items-baseline justify-between gap-3">
                            <Label>Cores disponíveis</Label>
                            <span className="text-xs text-muted-foreground">
                                {data.colors.length === 0
                                    ? 'nenhuma selecionada'
                                    : data.colors.length === 1
                                      ? '1 selecionada'
                                      : `${data.colors.length} selecionadas`}
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {palette.map((color) => (
                                <ToggleChip
                                    key={color.name}
                                    active={data.colors.includes(color.name)}
                                    onClick={() => toggleColor(color.name)}
                                >
                                    <ColorSwatch
                                        hex={color.hex}
                                        className="size-3.5"
                                    />
                                    {color.name}
                                </ToggleChip>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Só estas cores ficam disponíveis nas variantes dos
                            produtos com este material. Depois podes acrescentar
                            ou afinar preços em Cores.
                        </p>
                        <InputError message={errors.colors} />
                    </div>
                </div>

                <DialogFooter className="border-t border-border/60 p-6">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={!canCreate || processing}
                    >
                        {processing && <Spinner />}
                        Criar material
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
