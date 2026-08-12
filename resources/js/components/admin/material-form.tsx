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
import type { MaterialFormData } from '@/types/catalog';

type Props = {
    data: MaterialFormData;
    families: string[];
    setData: <K extends keyof MaterialFormData>(
        key: K,
        value: MaterialFormData[K],
    ) => void;
    errors: Partial<Record<keyof MaterialFormData, string>>;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
};

/** Radix não aceita value="" — o "sem família" precisa de um valor próprio. */
const NO_FAMILY = 'none';

export default function MaterialForm({
    data,
    families,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
}: Props) {
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
                    placeholder="PLA"
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label>Família</Label>
                <Select
                    value={data.family === '' ? NO_FAMILY : data.family}
                    onValueChange={(value) =>
                        setData('family', value === NO_FAMILY ? '' : value)
                    }
                >
                    <SelectTrigger className="w-56">
                        <SelectValue placeholder="Sem família" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NO_FAMILY}>Sem família</SelectItem>
                        {families.map((family) => (
                            <SelectItem key={family} value={family}>
                                {family}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                    O polímero base. O nome é comercial — "PLA Silk" e "PLA
                    Mate" são ambos da família PLA.
                </p>
                <InputError message={errors.family} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="supplier">Fornecedor</Label>
                <Input
                    id="supplier"
                    value={data.supplier}
                    onChange={(event) =>
                        setData('supplier', event.target.value)
                    }
                    maxLength={60}
                    placeholder="Prusament"
                    className="w-56"
                />
                <InputError message={errors.supplier} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="price_per_kg">Preço por kg (€)</Label>
                <Input
                    id="price_per_kg"
                    type="number"
                    step="0.01"
                    min={0}
                    value={data.price_per_kg}
                    onChange={(event) =>
                        setData('price_per_kg', event.target.value)
                    }
                    required
                    className="w-40"
                />
                <p className="text-xs text-muted-foreground">
                    O que pagas pelo rolo, por quilo. É a base do custo de cada
                    peça — cada cor pode ter o seu próprio preço se for mais
                    cara.
                </p>
                <InputError message={errors.price_per_kg} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="spools_in_stock">Bobines em stock</Label>
                    <Input
                        id="spools_in_stock"
                        type="number"
                        min={0}
                        value={data.spools_in_stock}
                        onChange={(event) =>
                            setData(
                                'spools_in_stock',
                                Number(event.target.value),
                            )
                        }
                        className="tabular-nums"
                    />
                    <InputError message={errors.spools_in_stock} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="min_spools">Stock mínimo</Label>
                    <Input
                        id="min_spools"
                        type="number"
                        min={0}
                        value={data.min_spools}
                        onChange={(event) =>
                            setData('min_spools', Number(event.target.value))
                        }
                        className="tabular-nums"
                    />
                    <p className="text-xs text-muted-foreground">
                        Abaixo disto o material aparece como "stock baixo". Zero
                        desliga o aviso.
                    </p>
                    <InputError message={errors.min_spools} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="sort_order">Ordem</Label>
                <Input
                    id="sort_order"
                    type="number"
                    min={0}
                    value={data.sort_order}
                    onChange={(event) =>
                        setData('sort_order', Number(event.target.value))
                    }
                    className="w-32"
                />
                <InputError message={errors.sort_order} />
            </div>

            <div className="flex items-center gap-3">
                <Checkbox
                    id="active"
                    checked={data.active}
                    onCheckedChange={(checked) =>
                        setData('active', checked === true)
                    }
                />
                <Label htmlFor="active">Disponível</Label>
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
