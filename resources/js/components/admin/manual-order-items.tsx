import { Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { centsToInput, formatCents, inputToCents } from '@/lib/money';
import type { VariantOption } from '@/types/catalog';
import type { ManualOrderItem } from '@/types/order';

// O Radix Select não aceita value="" — sentinela para a linha livre.
const FREE_LINE = 'free';

type Props = {
    items: ManualOrderItem[];
    variants: VariantOption[];
    defaultVatRate: number;
    errors: Record<string, string>;
    onChange: (items: ManualOrderItem[]) => void;
};

export function emptyItem(defaultVatRate: number): ManualOrderItem {
    return {
        variant_id: null,
        product_name: '',
        variant_label: '',
        sku: '',
        unit_price: '',
        qty: 1,
        price_override_reason: '',
        fulfillment_mode: 'in_stock',
        vat_rate: defaultVatRate,
    };
}

export function ManualOrderItems({
    items,
    variants,
    defaultVatRate,
    errors,
    onChange,
}: Props) {
    const patch = (index: number, changes: Partial<ManualOrderItem>) => {
        onChange(
            items.map((item, current) =>
                current === index ? { ...item, ...changes } : item,
            ),
        );
    };

    const pickVariant = (index: number, value: string) => {
        if (value === FREE_LINE) {
            patch(index, {
                variant_id: null,
                sku: '',
                product_name: '',
                variant_label: '',
                fulfillment_mode: 'in_stock',
                vat_rate: defaultVatRate,
            });

            return;
        }

        const variant = variants.find((option) => option.id === Number(value));

        if (variant) {
            // O preço do catálogo entra pré-preenchido mas continua editável;
            // se o admin o mudar, o motivo passa a ser obrigatório.
            patch(index, {
                variant_id: variant.id,
                sku: variant.sku,
                product_name: variant.productName,
                unit_price: centsToInput(variant.priceCents),
                fulfillment_mode: variant.fulfillmentMode,
                vat_rate: variant.vatRate,
                price_override_reason: '',
            });
        }
    };

    return (
        <div className="flex flex-col gap-4">
            {items.map((item, index) => {
                const variant = variants.find(
                    (option) => option.id === item.variant_id,
                );
                const priceChanged =
                    variant !== undefined &&
                    inputToCents(item.unit_price) !== variant.priceCents;

                return (
                    <div
                        key={index}
                        className="flex flex-col gap-4 rounded-xl border border-border/60 p-4"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="grid flex-1 gap-2">
                                <Label>Artigo</Label>
                                <Select
                                    value={
                                        item.variant_id === null
                                            ? FREE_LINE
                                            : String(item.variant_id)
                                    }
                                    onValueChange={(value) =>
                                        pickVariant(index, value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={FREE_LINE}>
                                            Linha livre (fora do catálogo)
                                        </SelectItem>
                                        {variants.map((option) => (
                                            <SelectItem
                                                key={option.id}
                                                value={String(option.id)}
                                            >
                                                {option.label} · {option.sku} ·{' '}
                                                {formatCents(option.priceCents)}{' '}
                                                · {option.availableStock} un.
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {items.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="mt-7"
                                    onClick={() =>
                                        onChange(
                                            items.filter(
                                                (_, current) =>
                                                    current !== index,
                                            ),
                                        )
                                    }
                                    aria-label="Remover artigo"
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </div>

                        {item.variant_id === null && (
                            <div className="grid grid-cols-3 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor={`product_name_${index}`}>
                                        Descrição
                                    </Label>
                                    <Input
                                        id={`product_name_${index}`}
                                        value={item.product_name}
                                        onChange={(event) =>
                                            patch(index, {
                                                product_name:
                                                    event.target.value,
                                            })
                                        }
                                        maxLength={120}
                                    />
                                    <InputError
                                        message={
                                            errors[
                                                `items.${index}.product_name`
                                            ]
                                        }
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor={`variant_label_${index}`}>
                                        Variante
                                    </Label>
                                    <Input
                                        id={`variant_label_${index}`}
                                        value={item.variant_label}
                                        onChange={(event) =>
                                            patch(index, {
                                                variant_label:
                                                    event.target.value,
                                            })
                                        }
                                        maxLength={120}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor={`sku_${index}`}>SKU</Label>
                                    <Input
                                        id={`sku_${index}`}
                                        value={item.sku}
                                        onChange={(event) =>
                                            patch(index, {
                                                sku: event.target.value,
                                            })
                                        }
                                        maxLength={60}
                                    />
                                </div>
                            </div>
                        )}

                        <div className="grid grid-cols-3 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor={`unit_price_${index}`}>
                                    Preço unitário (€)
                                </Label>
                                <Input
                                    id={`unit_price_${index}`}
                                    type="number"
                                    step="0.01"
                                    min={0}
                                    value={item.unit_price}
                                    onChange={(event) =>
                                        patch(index, {
                                            unit_price: event.target.value,
                                        })
                                    }
                                    required
                                />
                                {variant?.wholesalePriceCents != null && (
                                    <button
                                        type="button"
                                        className="w-fit text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                        onClick={() =>
                                            patch(index, {
                                                unit_price: centsToInput(
                                                    variant.wholesalePriceCents,
                                                ),
                                                // O preço fica diferente do
                                                // catálogo, e o motivo é
                                                // obrigatório nesse caso —
                                                // preenchê-lo aqui poupa
                                                // escrever sempre o mesmo.
                                                price_override_reason:
                                                    'Preço de revenda',
                                            })
                                        }
                                    >
                                        Usar preço de revenda (
                                        {formatCents(
                                            variant.wholesalePriceCents,
                                        )}
                                        )
                                    </button>
                                )}
                                <InputError
                                    message={
                                        errors[`items.${index}.unit_price`]
                                    }
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor={`qty_${index}`}>
                                    Quantidade
                                </Label>
                                <Input
                                    id={`qty_${index}`}
                                    type="number"
                                    min={1}
                                    value={item.qty}
                                    onChange={(event) =>
                                        patch(index, {
                                            qty: Number(event.target.value),
                                        })
                                    }
                                    required
                                />
                                <InputError
                                    message={errors[`items.${index}.qty`]}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label>Total da linha</Label>
                                <div className="flex h-9 items-center text-sm">
                                    {formatCents(
                                        inputToCents(item.unit_price) *
                                            item.qty,
                                    )}
                                </div>
                            </div>
                        </div>

                        {priceChanged && (
                            <div className="grid gap-2">
                                <Label
                                    htmlFor={`price_override_reason_${index}`}
                                >
                                    Motivo do preço diferente do catálogo
                                </Label>
                                <Input
                                    id={`price_override_reason_${index}`}
                                    value={item.price_override_reason}
                                    onChange={(event) =>
                                        patch(index, {
                                            price_override_reason:
                                                event.target.value,
                                        })
                                    }
                                    placeholder="Desconto acordado, peça com defeito…"
                                    maxLength={200}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Catálogo:{' '}
                                    {formatCents(variant?.priceCents ?? 0)}
                                </p>
                                <InputError
                                    message={
                                        errors[
                                            `items.${index}.price_override_reason`
                                        ]
                                    }
                                />
                            </div>
                        )}
                    </div>
                );
            })}

            <div>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        onChange([...items, emptyItem(defaultVatRate)])
                    }
                >
                    Adicionar artigo
                </Button>
            </div>
        </div>
    );
}
