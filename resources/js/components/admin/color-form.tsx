import { ColorSwatch } from '@/components/admin/color-swatch';
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
import { formatCents } from '@/lib/money';
import type { ColorFormData, MaterialOption } from '@/types/catalog';

type Props = {
    data: ColorFormData;
    setData: <K extends keyof ColorFormData>(
        key: K,
        value: ColorFormData[K],
    ) => void;
    errors: Partial<Record<keyof ColorFormData, string>>;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
    materials: MaterialOption[];
};

/** O <input type="color"> só aceita #rrggbb — recusa as 8 casas com alfa. */
const isPlainHex = (value: string) => /^#[0-9a-fA-F]{6}$/.test(value);

export default function ColorForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
    materials,
}: Props) {
    const material = materials.find((option) => option.id === data.material_id);

    return (
        <form onSubmit={onSubmit} className="flex max-w-xl flex-col gap-6">
            <div className="grid gap-2">
                <Label>Material</Label>
                <Select
                    value={
                        data.material_id === null
                            ? undefined
                            : String(data.material_id)
                    }
                    onValueChange={(value) =>
                        setData('material_id', Number(value))
                    }
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Escolhe o material" />
                    </SelectTrigger>
                    <SelectContent>
                        {materials.map((option) => (
                            <SelectItem
                                key={option.id}
                                value={String(option.id)}
                            >
                                {option.name} ·{' '}
                                {formatCents(option.pricePerKgCents)}/kg
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                    A mesma cor em materiais diferentes são registos distintos —
                    PLA Preto não é PETG Preto.
                </p>
                <InputError message={errors.material_id} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="name">Nome</Label>
                <Input
                    id="name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    required
                    maxLength={60}
                    placeholder="Preto mate"
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="hex_color">Cor</Label>
                <div className="flex items-center gap-3">
                    <input
                        id="hex_color"
                        type="color"
                        value={
                            isPlainHex(data.hex_color)
                                ? data.hex_color
                                : '#000000'
                        }
                        onChange={(event) =>
                            setData('hex_color', event.target.value)
                        }
                        className="size-9 cursor-pointer rounded-md border border-input bg-transparent"
                    />
                    <Input
                        value={data.hex_color}
                        onChange={(event) =>
                            setData('hex_color', event.target.value)
                        }
                        required
                        maxLength={9}
                        className="w-32 font-mono"
                        aria-label="Código hexadecimal"
                    />
                    <ColorSwatch hex={data.hex_color} className="size-6" />
                </div>
                <p className="text-xs text-muted-foreground">
                    O swatch que o cliente vê na página do produto.
                </p>
                <InputError message={errors.hex_color} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="price_per_kg">Preço por kg próprio (€)</Label>
                <Input
                    id="price_per_kg"
                    type="number"
                    step="0.01"
                    min={0}
                    value={data.price_per_kg}
                    onChange={(event) =>
                        setData('price_per_kg', event.target.value)
                    }
                    placeholder="Opcional"
                    className="w-40"
                />
                <p className="text-xs text-muted-foreground">
                    Deixa vazio para herdar o preço do material
                    {material &&
                        ` (${formatCents(material.pricePerKgCents)}/kg)`}
                    . Preenche só se esta cor te custar mais.
                </p>
                <InputError message={errors.price_per_kg} />
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
                    id="is_active"
                    checked={data.is_active}
                    onCheckedChange={(checked) =>
                        setData('is_active', checked === true)
                    }
                />
                <Label htmlFor="is_active">Disponível</Label>
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
