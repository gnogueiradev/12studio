import { router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { centsToInput, inputToCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { variantFormFromRow } from '@/lib/variant-form-data';
import { destroy, update } from '@/routes/admin/variantes';
import type { VariantRow } from '@/types/catalog';

type Props = {
    variants: VariantRow[];
    /** Null abre a ficha em branco; uma linha abre a ficha dessa variante. */
    onOpenVariant: (variant: VariantRow | null) => void;
    /** A variante que a gaveta tem aberta, para a linha dela se marcar. */
    openId: number | 'new' | null;
};

/**
 * As variantes do produto: uma linha cada, com o preço e o stock a poderem
 * mudar ali mesmo.
 *
 * Os dois campos da linha são o atalho para o que se mexe todos os dias —
 * baixar um preço, contar o que saiu da impressora. Tudo o resto (cor,
 * material, gramagem, custos, impressora) vive na ficha, que abre em gaveta por
 * baixo da lista.
 *
 * Gravam ao sair do campo e só quando o valor mudou mesmo: sem essa guarda,
 * passar pelos campos com o Tab gravava trinta variantes e deixava trinta
 * movimentos de stock com o nome de quem só estava a olhar.
 */
export function VariantRows({ variants, onOpenVariant, openId }: Props) {
    const [archiving, setArchiving] = useState<VariantRow | null>(null);
    const [saving, setSaving] = useState<number | null>(null);

    /*
     * O endpoint da variante herda as regras do `store` e exige o payload
     * inteiro — por isso vai a linha toda, com o campo mexido por cima. É o
     * mesmo pedido que a ficha faz; o que muda é quantos campos o admin viu.
     */
    const save = (variant: VariantRow, changes: Record<string, unknown>) => {
        setSaving(variant.id);
        router.patch(
            update(variant.id).url,
            { ...variantFormFromRow(variant), ...changes },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(null),
            },
        );
    };

    if (variants.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-border px-6 py-8 text-center text-sm text-muted-foreground">
                Este produto ainda não tem variantes — cria a primeira para lhe
                dar preço e stock.
            </p>
        );
    }

    return (
        <>
            <div className="flex items-center gap-2.5 px-0.5 pb-2 text-[10.5px] font-medium tracking-[0.1em] text-muted-foreground uppercase">
                <span className="min-w-0 flex-[1_1_120px]">Variante</span>
                <span className="w-22 flex-none text-right">Preço</span>
                <span className="w-16 flex-none text-right">Stock</span>
                <span className="w-16 flex-none" />
            </div>

            <ul>
                {variants.map((variant) => (
                    <li
                        key={variant.id}
                        className={cn(
                            'flex flex-wrap items-center gap-x-2.5 gap-y-2 border-t border-border/60 px-0.5 py-2.5',
                            openId === variant.id && 'bg-secondary/40',
                            !variant.active && 'opacity-60',
                        )}
                    >
                        <span className="flex min-w-0 flex-[1_1_120px] flex-wrap items-center gap-2">
                            {variant.color !== null && (
                                <ColorSwatch hex={variant.color.hex} />
                            )}
                            <span className="text-[13.5px]">
                                {[
                                    variant.color?.name,
                                    variant.sizeLabel,
                                    variant.material?.name,
                                ]
                                    .filter((part) => part)
                                    .join(' · ') || 'Sem cor nem material'}
                            </span>
                            {variant.isDefault && (
                                <span className="rounded-full border border-border px-2 py-0.5 text-[10.5px] text-muted-foreground">
                                    Principal
                                </span>
                            )}
                            {!variant.active && (
                                <span className="rounded-full border border-border px-2 py-0.5 text-[10.5px] text-muted-foreground">
                                    Arquivada
                                </span>
                            )}
                            <span className="text-[11px] text-muted-foreground tabular-nums">
                                {variant.sku}
                            </span>
                        </span>

                        <span className="relative flex w-22 flex-none items-center">
                            <Input
                                defaultValue={centsToInput(variant.priceCents)}
                                onBlur={(event) => {
                                    const cents = inputToCents(
                                        event.target.value,
                                    );

                                    if (
                                        cents === variant.priceCents ||
                                        event.target.value.trim() === ''
                                    ) {
                                        return;
                                    }

                                    /*
                                     * O campo da linha mostra o preço EFETIVO —
                                     * o que o cliente paga. Numa variante em
                                     * promoção, é o promocional que ali está, e
                                     * é esse que tem de ser reescrito; mexer no
                                     * normal subia o preço riscado sem ninguém
                                     * pedir.
                                     */
                                    save(
                                        variant,
                                        variant.salePrice === null
                                            ? {
                                                  normal_price:
                                                      event.target.value,
                                              }
                                            : {
                                                  sale_price:
                                                      event.target.value,
                                              },
                                    );
                                }}
                                aria-label={`Preço de ${variant.sku}`}
                                className="h-8 rounded-lg pr-6 text-right text-[13px] tabular-nums md:text-[13px]"
                            />
                            <span className="pointer-events-none absolute right-2 text-xs text-muted-foreground">
                                €
                            </span>
                        </span>

                        <Input
                            defaultValue={variant.stock}
                            inputMode="numeric"
                            onBlur={(event) => {
                                const stock = Number(event.target.value);

                                if (
                                    !Number.isInteger(stock) ||
                                    stock < 0 ||
                                    stock === variant.stock
                                ) {
                                    return;
                                }

                                save(variant, { stock });
                            }}
                            aria-label={`Stock de ${variant.sku}`}
                            className="h-8 w-16 flex-none rounded-lg text-right text-[13px] tabular-nums md:text-[13px]"
                        />

                        <span className="flex w-16 flex-none items-center justify-end gap-0.5">
                            {saving === variant.id ? (
                                <Spinner className="mr-1.5 size-3.5" />
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => onOpenVariant(variant)}
                                        aria-label={`Editar ${variant.sku}`}
                                        className="grid size-7 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                    >
                                        <Pencil className="size-3.5" />
                                    </button>
                                    {variant.active && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setArchiving(variant)
                                            }
                                            aria-label={`Arquivar ${variant.sku}`}
                                            className="grid size-7 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-secondary hover:text-destructive"
                                        >
                                            <Trash2 className="size-3.5" />
                                        </button>
                                    )}
                                </>
                            )}
                        </span>
                    </li>
                ))}
            </ul>

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar variante"
                description={
                    <>
                        A variante <strong>{archiving?.sku}</strong> deixa de
                        poder ser vendida. Os movimentos de stock e as
                        encomendas onde aparece mantêm-se.
                    </>
                }
                confirmLabel="Arquivar"
                destructive
                onConfirm={() => {
                    if (archiving) {
                        router.delete(destroy(archiving.id).url, {
                            preserveScroll: true,
                            preserveState: true,
                            onFinish: () => setArchiving(null),
                        });
                    }
                }}
            />
        </>
    );
}
