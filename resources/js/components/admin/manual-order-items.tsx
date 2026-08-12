import { X } from 'lucide-react';
import { useState } from 'react';
import { OrderItemDialog } from '@/components/admin/order-item-dialog';
import { Button } from '@/components/ui/button';
import { formatCents, inputToCents } from '@/lib/money';
import type { VariantOption } from '@/types/catalog';
import type { ManualOrderItem } from '@/types/order';

type Props = {
    items: ManualOrderItem[];
    variants: VariantOption[];
    defaultVatRate: number;
    onChange: (items: ManualOrderItem[]) => void;
};

/**
 * As linhas da encomenda, em lista compacta. Escolher o artigo é trabalho do
 * OrderItemDialog — aqui só se vê o que já entrou, e clica-se para corrigir.
 */
export function ManualOrderItems({
    items,
    variants,
    defaultVatRate,
    onChange,
}: Props) {
    const [open, setOpen] = useState(false);
    /** null = a adicionar; um índice = a editar essa linha. */
    const [editing, setEditing] = useState<number | null>(null);
    /**
     * Muda a cada abertura para o modal remontar e nascer com o estado da
     * linha certa — a alternativa era um efeito a repor os campos depois de
     * abrir, e um modal que pisca com o conteúdo anterior.
     */
    const [session, setSession] = useState(0);

    const openWith = (index: number | null) => {
        setEditing(index);
        setSession((current) => current + 1);
        setOpen(true);
    };

    const submit = (item: ManualOrderItem) => {
        onChange(
            editing === null
                ? [...items, item]
                : items.map((current, index) =>
                      index === editing ? item : current,
                  ),
        );
    };

    const remove = (index: number) =>
        onChange(items.filter((_, current) => current !== index));

    return (
        <div className="flex flex-col gap-2">
            {items.map((item, index) => {
                const unitCents = inputToCents(item.unit_price);

                return (
                    <div
                        key={index}
                        className="flex flex-wrap items-center gap-3 rounded-lg border border-border/60 bg-muted px-3 py-2.5"
                    >
                        {/*
                         * A linha inteira abre o modal em edição. É um botão e
                         * não uma div com onClick para continuar a chegar-se lá
                         * pelo teclado.
                         */}
                        <button
                            type="button"
                            onClick={() => openWith(index)}
                            className="min-w-36 flex-1 text-left"
                        >
                            <span className="block text-sm font-medium">
                                {item.product_name}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {[
                                    item.variant_id === null
                                        ? 'Fora do catálogo'
                                        : item.variant_label,
                                    item.sku,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </button>

                        <span className="text-sm text-muted-foreground tabular-nums">
                            {item.qty} × {formatCents(unitCents)}
                        </span>
                        <span className="min-w-20 text-right text-sm font-medium tabular-nums">
                            {formatCents(unitCents * item.qty)}
                        </span>

                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-8 hover:border-destructive hover:text-destructive"
                            onClick={() => remove(index)}
                            aria-label={`Remover ${item.product_name}`}
                        >
                            <X />
                        </Button>
                    </div>
                );
            })}

            {items.length === 0 && (
                <p className="rounded-lg border border-dashed border-border/60 px-3 py-6 text-center text-sm text-muted-foreground">
                    Ainda sem artigos nesta encomenda.
                </p>
            )}

            <div>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => openWith(null)}
                >
                    Adicionar artigo
                </Button>
            </div>

            <OrderItemDialog
                key={session}
                open={open}
                onOpenChange={setOpen}
                variants={variants}
                defaultVatRate={defaultVatRate}
                item={editing === null ? null : (items[editing] ?? null)}
                onSubmit={submit}
            />
        </div>
    );
}
