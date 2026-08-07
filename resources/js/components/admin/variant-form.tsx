import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { VariantFormData } from '@/types/catalog';

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
    /** Unidades presas a pagamentos pendentes — só existem a partir da Fase 3. */
    reservedStock?: number;
};

export default function VariantForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
    reservedStock = 0,
}: Props) {
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

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="price">Preço (€, IVA incluído)</Label>
                    <Input
                        id="price"
                        type="number"
                        step="0.01"
                        min={0}
                        value={data.price}
                        onChange={(event) =>
                            setData('price', event.target.value)
                        }
                        required
                    />
                    <InputError message={errors.price} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="compare_at_price">Preço anterior (€)</Label>
                    <Input
                        id="compare_at_price"
                        type="number"
                        step="0.01"
                        min={0}
                        value={data.compare_at_price}
                        onChange={(event) =>
                            setData('compare_at_price', event.target.value)
                        }
                        placeholder="Opcional"
                    />
                    <InputError message={errors.compare_at_price} />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
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
