import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { CategoryFormData } from '@/types/catalog';

type Props = {
    data: CategoryFormData;
    setData: <K extends keyof CategoryFormData>(
        key: K,
        value: CategoryFormData[K],
    ) => void;
    errors: Partial<Record<keyof CategoryFormData, string>>;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
};

export default function CategoryForm({
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
                    maxLength={120}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Descrição</Label>
                <Textarea
                    id="description"
                    value={data.description}
                    onChange={(event) =>
                        setData('description', event.target.value)
                    }
                    rows={4}
                />
                <InputError message={errors.description} />
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
                <Label htmlFor="active">Visível na loja</Label>
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
