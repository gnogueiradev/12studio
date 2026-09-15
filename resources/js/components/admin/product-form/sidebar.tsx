import { TagInput } from '@/components/admin/tag-input';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCents } from '@/lib/money';
import type { ProductEditing, ProductFormData } from '@/types/catalog';
import { PRODUCT_STATUSES } from '@/types/catalog';

type Props = {
    data: ProductFormData;
    setData: <K extends keyof ProductFormData>(
        key: K,
        value: ProductFormData[K],
    ) => void;
    errors: Partial<Record<keyof ProductFormData, string>>;
    /** Null a criar: ainda não há variantes nem fotografias que resumir. */
    editing: ProductEditing | null;
    tagSuggestions: string[];
};

const STATUS_NOTES: Record<string, string> = {
    draft: 'Rascunhos não aparecem na montra nem podem ser encomendados.',
    active: 'À venda na montra, com o preço da variante principal.',
    archived: 'Fora do catálogo. O stock e o histórico mantêm-se intactos.',
};

/**
 * A coluna da direita: o que se decide sobre o produto, não sobre a peça.
 *
 * Fica colada ao ecrã enquanto a coluna principal corre — o estado e as
 * etiquetas são as duas coisas que se querem mexer a qualquer altura do
 * formulário, e ir buscá-las ao fundo de seis secções era perder o sítio.
 */
export function ProductSidebar({
    data,
    setData,
    errors,
    editing,
    tagSuggestions,
}: Props) {
    const prices = (editing?.variants ?? []).map(
        (variant) => variant.priceCents,
    );
    const stock = (editing?.variants ?? []).reduce(
        (total, variant) => total + variant.availableStock,
        0,
    );

    return (
        <aside className="flex min-w-0 flex-[1_1_260px] flex-col gap-3.5 lg:sticky lg:top-5 lg:max-w-75">
            <section className="flex flex-col gap-3 rounded-xl border border-border/60 bg-card px-4 py-4">
                <h2 className="text-[13.5px] font-semibold">Publicação</h2>

                <div className="grid gap-1.5">
                    <Label
                        htmlFor="product-status"
                        className="text-xs text-muted-foreground"
                    >
                        Estado
                    </Label>
                    <Select
                        value={data.status}
                        onValueChange={(value) => setData('status', value)}
                    >
                        <SelectTrigger id="product-status" className="w-full">
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

                <label className="flex items-center gap-2.5 text-[13px]">
                    <Checkbox
                        checked={data.featured}
                        onCheckedChange={(checked) =>
                            setData('featured', checked === true)
                        }
                    />
                    Destacado na montra
                </label>

                <p className="text-xs text-pretty text-muted-foreground">
                    {STATUS_NOTES[data.status]}
                </p>
            </section>

            {editing !== null && (
                <section className="flex flex-col gap-2.5 rounded-xl border border-border/60 bg-card px-4 py-4 text-[13px]">
                    <h2 className="text-[13.5px] font-semibold">Resumo</h2>

                    <div className="flex justify-between gap-2.5">
                        <span className="text-muted-foreground">
                            Preço desde
                        </span>
                        <span className="font-semibold tabular-nums">
                            {prices.length === 0
                                ? '—'
                                : formatCents(Math.min(...prices))}
                        </span>
                    </div>
                    <div className="flex justify-between gap-2.5">
                        <span className="text-muted-foreground">
                            Pronto a sair
                        </span>
                        <span className="tabular-nums">
                            {stock} {stock === 1 ? 'peça' : 'peças'}
                        </span>
                    </div>
                    <div className="flex justify-between gap-2.5">
                        <span className="text-muted-foreground">Variantes</span>
                        <span className="tabular-nums">
                            {editing.variants.length}
                        </span>
                    </div>
                    <div className="flex justify-between gap-2.5">
                        <span className="text-muted-foreground">
                            Fotografias
                        </span>
                        <span className="tabular-nums">
                            {editing.images.length}
                        </span>
                    </div>
                </section>
            )}

            <section className="flex flex-col gap-2.5 rounded-xl border border-border/60 bg-card px-4 py-4">
                <h2 className="text-[13.5px] font-semibold">Etiquetas</h2>
                <TagInput
                    id="product-tags"
                    value={data.tags}
                    onChange={(tags) => setData('tags', tags)}
                    suggestions={tagSuggestions}
                />
                <p className="text-xs text-pretty text-muted-foreground">
                    Segundo eixo de organização, ao lado da categoria: "natal",
                    "presente", "minimalista".
                </p>
                <InputError message={errors.tags} />
            </section>
        </aside>
    );
}
