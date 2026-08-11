import { ColorSwatch } from '@/components/admin/color-swatch';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatCents, inputToCents } from '@/lib/money';
import type { ColorGroup, VariantFormData } from '@/types/catalog';

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
    colorGroups: ColorGroup[];
    /** Unidades presas a pagamentos pendentes — só existem a partir da Fase 3. */
    reservedStock?: number;
};

const NO_COLOR = 'none';

export default function VariantForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
    colorGroups,
    reservedStock = 0,
}: Props) {
    const normalCents = inputToCents(data.normal_price);
    const saleCents =
        data.sale_price === '' ? null : inputToCents(data.sale_price);
    const discount =
        saleCents !== null && normalCents > 0 && saleCents < normalCents
            ? Math.round(((normalCents - saleCents) / normalCents) * 100)
            : null;

    const hasColors = colorGroups.length > 0;

    return (
        <form onSubmit={onSubmit} className="flex max-w-xl flex-col gap-6">
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

            <div className="grid gap-2">
                <Label>Cor</Label>
                <Select
                    value={
                        data.color_id === null
                            ? NO_COLOR
                            : String(data.color_id)
                    }
                    onValueChange={(value) =>
                        setData(
                            'color_id',
                            value === NO_COLOR ? null : Number(value),
                        )
                    }
                    disabled={!hasColors}
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Sem cor" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NO_COLOR}>Sem cor</SelectItem>
                        {colorGroups.map((group) => (
                            <SelectGroup key={group.material}>
                                <SelectLabel>{group.material}</SelectLabel>
                                {group.colors.map((color) => (
                                    <SelectItem
                                        key={color.id}
                                        value={String(color.id)}
                                    >
                                        <span className="flex items-center gap-2">
                                            <ColorSwatch hex={color.hex} />
                                            {color.name}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        ))}
                    </SelectContent>
                </Select>
                {!hasColors && (
                    <p className="text-xs text-muted-foreground">
                        Ainda não há cores. Cria-as em Materiais e cores para as
                        poderes escolher aqui.
                    </p>
                )}
                <InputError message={errors.color_id} />
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
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
