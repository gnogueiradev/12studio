import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { MaterialFormData } from '@/types/catalog';

type Props = {
    data: MaterialFormData;
    setData: <K extends keyof MaterialFormData>(
        key: K,
        value: MaterialFormData[K],
    ) => void;
    errors: Partial<Record<keyof MaterialFormData, string>>;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
};

export default function MaterialForm({
    data,
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
