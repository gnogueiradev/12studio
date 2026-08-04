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
import type { CategoryOption, ProductFormData } from '@/types/catalog';
import { FULFILLMENT_MODES, PRODUCT_STATUSES } from '@/types/catalog';

type Props = {
    data: ProductFormData;
    setData: <K extends keyof ProductFormData>(
        key: K,
        value: ProductFormData[K],
    ) => void;
    errors: Partial<Record<keyof ProductFormData, string>>;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
    categories: CategoryOption[];
};

const NO_CATEGORY = 'none';

export default function ProductForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
    categories,
}: Props) {
    const showProductionFields = data.fulfillment_mode !== 'in_stock';

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
                <Label>Categoria</Label>
                <Select
                    value={
                        data.category_id === null
                            ? NO_CATEGORY
                            : String(data.category_id)
                    }
                    onValueChange={(value) =>
                        setData(
                            'category_id',
                            value === NO_CATEGORY ? null : Number(value),
                        )
                    }
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Sem categoria" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NO_CATEGORY}>
                            Sem categoria
                        </SelectItem>
                        {categories.map((category) => (
                            <SelectItem
                                key={category.id}
                                value={String(category.id)}
                            >
                                {category.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.category_id} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Descrição</Label>
                <textarea
                    id="description"
                    value={data.description}
                    onChange={(event) =>
                        setData('description', event.target.value)
                    }
                    rows={6}
                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <InputError message={errors.description} />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label>Estado</Label>
                    <Select
                        value={data.status}
                        onValueChange={(value) => setData('status', value)}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PRODUCT_STATUSES.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="vat_rate">IVA (%)</Label>
                    <Input
                        id="vat_rate"
                        type="number"
                        min={0}
                        max={100}
                        value={data.vat_rate}
                        onChange={(event) =>
                            setData('vat_rate', Number(event.target.value))
                        }
                    />
                    <InputError message={errors.vat_rate} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label>Modo de produção</Label>
                <Select
                    value={data.fulfillment_mode}
                    onValueChange={(value) =>
                        setData('fulfillment_mode', value)
                    }
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {FULFILLMENT_MODES.map((mode) => (
                            <SelectItem key={mode.value} value={mode.value}>
                                {mode.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.fulfillment_mode} />
            </div>

            {showProductionFields && (
                <div className="grid grid-cols-2 gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="production_time_days">
                            Produção (dias)
                        </Label>
                        <Input
                            id="production_time_days"
                            type="number"
                            min={0}
                            max={60}
                            value={data.production_time_days ?? ''}
                            onChange={(event) =>
                                setData(
                                    'production_time_days',
                                    event.target.value === ''
                                        ? null
                                        : Number(event.target.value),
                                )
                            }
                        />
                        <InputError message={errors.production_time_days} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="max_open_production_qty">
                            Capacidade máx. em produção
                        </Label>
                        <Input
                            id="max_open_production_qty"
                            type="number"
                            min={1}
                            value={data.max_open_production_qty ?? ''}
                            onChange={(event) =>
                                setData(
                                    'max_open_production_qty',
                                    event.target.value === ''
                                        ? null
                                        : Number(event.target.value),
                                )
                            }
                        />
                        <InputError message={errors.max_open_production_qty} />
                    </div>
                </div>
            )}

            <div className="flex items-center gap-6">
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="featured"
                        checked={data.featured}
                        onCheckedChange={(checked) =>
                            setData('featured', checked === true)
                        }
                    />
                    <Label htmlFor="featured">Destacado</Label>
                </div>

                {showProductionFields && (
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id="allow_backorder"
                            checked={data.allow_backorder}
                            onCheckedChange={(checked) =>
                                setData('allow_backorder', checked === true)
                            }
                        />
                        <Label htmlFor="allow_backorder">
                            Aceitar além da capacidade
                        </Label>
                    </div>
                )}
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
