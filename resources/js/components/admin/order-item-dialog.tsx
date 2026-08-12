import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { cn } from '@/lib/utils';
import type { VariantOption } from '@/types/catalog';
import type { ManualOrderItem } from '@/types/order';

/** Entrada fixa no fim da lista: artigo que não existe no catálogo. */
const FREE_LINE = 'free';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    variants: VariantOption[];
    defaultVatRate: number;
    /** null = adicionar; caso contrário o modal abre com a linha carregada. */
    item: ManualOrderItem | null;
    onSubmit: (item: ManualOrderItem) => void;
};

/**
 * Adicionar ou corrigir uma linha da encomenda.
 *
 * O servidor manda uma lista plana de variantes; o admin pensa em produtos.
 * Por isso o modal agrupa por `productName` e só mostra o seletor de variante
 * quando o produto tem mais do que uma — escolher "PETG Natural" num produto
 * que só existe nessa cor é uma pergunta sem resposta alternativa.
 */
export function OrderItemDialog({
    open,
    onOpenChange,
    variants,
    defaultVatRate,
    item,
    onSubmit,
}: Props) {
    const products = useMemo(() => {
        const grouped = new Map<string, VariantOption[]>();

        variants.forEach((variant) => {
            const list = grouped.get(variant.productName) ?? [];

            list.push(variant);
            grouped.set(variant.productName, list);
        });

        return [...grouped.entries()].map(([name, options]) => {
            const prices = options.map((option) => option.priceCents);

            return {
                name,
                variants: options,
                fromCents: Math.min(...prices),
                // Variantes do mesmo produto podem custar valores diferentes;
                // sem o "desde", a linha da lista dizia um preço e a variante
                // escolhida a seguir mostrava outro.
                ranged: Math.min(...prices) !== Math.max(...prices),
            };
        });
    }, [variants]);

    /*
     * O pai remonta este componente a cada abertura (ver o `key` na
     * ManualOrderItems), por isso o estado inicial sai daqui — dos props — e
     * não de um efeito a repor tudo depois de o modal abrir.
     *
     * `initial` é a variante que estava na linha em edição; `fallback` é o
     * primeiro produto do catálogo, para um modal novo não abrir sem nada
     * escolhido e com metade do conteúdo escondido.
     */
    const initial =
        item === null
            ? undefined
            : variants.find((option) => option.id === item.variant_id);
    const fallback = item === null ? products[0] : undefined;

    const [search, setSearch] = useState('');
    const [product, setProduct] = useState(
        initial?.productName ?? fallback?.name ?? FREE_LINE,
    );
    const [variantId, setVariantId] = useState<number | null>(
        initial?.id ?? fallback?.variants[0]?.id ?? null,
    );
    // Só uma linha livre em edição traz descrição e preço escritos à mão.
    const [description, setDescription] = useState(
        initial === undefined ? (item?.product_name ?? '') : '',
    );
    const [price, setPrice] = useState(
        initial === undefined ? (item?.unit_price ?? '') : '',
    );
    const [qty, setQty] = useState(item?.qty ?? 1);

    const query = search.trim().toLowerCase();
    const matches = products.filter(
        (option) =>
            query === '' ||
            option.name.toLowerCase().includes(query) ||
            option.variants.some((variant) =>
                variant.sku.toLowerCase().includes(query),
            ),
    );

    const freeLine = product === FREE_LINE;
    const chosen = products.find((option) => option.name === product);
    const variant =
        chosen?.variants.find((option) => option.id === variantId) ??
        chosen?.variants[0];

    const unitCents = freeLine
        ? inputToCents(price)
        : (variant?.priceCents ?? 0);
    const ready = freeLine ? description.trim() !== '' : variant !== undefined;

    const pickProduct = (name: string) => {
        setProduct(name);
        setVariantId(
            products.find((option) => option.name === name)?.variants[0]?.id ??
                null,
        );
    };

    const confirm = () => {
        if (freeLine) {
            if (description.trim() === '') {
                return;
            }

            onSubmit({
                variant_id: null,
                product_name: description.trim(),
                variant_label: '',
                sku: '',
                unit_price: price === '' ? '0' : price,
                qty,
                price_override_reason: '',
                fulfillment_mode: 'in_stock',
                vat_rate: defaultVatRate,
            });
            onOpenChange(false);

            return;
        }

        if (variant === undefined) {
            return;
        }

        // O preço do catálogo entra tal e qual, sem motivo de override: quando
        // é preciso um preço acordado, o caminho é a linha livre.
        onSubmit({
            variant_id: variant.id,
            product_name: variant.productName,
            variant_label: variant.variantLabel,
            sku: variant.sku,
            unit_price: centsToInput(variant.priceCents),
            qty,
            price_override_reason: '',
            fulfillment_mode: variant.fulfillmentMode,
            vat_rate: variant.vatRate,
        });
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {item === null ? 'Adicionar artigo' : 'Editar artigo'}
                    </DialogTitle>
                    <DialogDescription>
                        Escolhe o produto, a variante e a quantidade.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    <Label htmlFor="item_search">Produto</Label>
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            id="item_search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Procurar produto…"
                            className="pl-9"
                        />
                    </div>

                    <div className="flex max-h-48 flex-col overflow-y-auto rounded-md border border-border/60">
                        {matches.map((option) => (
                            <button
                                key={option.name}
                                type="button"
                                onClick={() => pickProduct(option.name)}
                                className={cn(
                                    'flex items-center justify-between gap-3 border-b border-border/60 px-3 py-2 text-left text-sm hover:bg-accent',
                                    option.name === product &&
                                        'bg-accent font-medium',
                                )}
                            >
                                {option.name}
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {option.ranged && 'desde '}
                                    {formatCents(option.fromCents)}
                                </span>
                            </button>
                        ))}

                        {matches.length === 0 && (
                            <p className="px-3 py-4 text-center text-sm text-muted-foreground">
                                Nenhum produto encontrado.
                            </p>
                        )}

                        <button
                            type="button"
                            onClick={() => pickProduct(FREE_LINE)}
                            className={cn(
                                'flex items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-accent',
                                freeLine && 'bg-accent font-medium',
                            )}
                        >
                            Linha livre (fora do catálogo)
                            <span className="text-xs text-muted-foreground">
                                preço livre
                            </span>
                        </button>
                    </div>
                </div>

                {freeLine ? (
                    <div className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="item_description">Descrição</Label>
                            <Input
                                id="item_description"
                                value={description}
                                onChange={(event) =>
                                    setDescription(event.target.value)
                                }
                                placeholder="Peça à medida, reparação…"
                                maxLength={120}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="item_price">
                                Preço unitário (€)
                            </Label>
                            <Input
                                id="item_price"
                                type="number"
                                min={0}
                                step="0.01"
                                value={price}
                                onChange={(event) =>
                                    setPrice(event.target.value)
                                }
                                className="tabular-nums"
                            />
                        </div>
                    </div>
                ) : (
                    chosen !== undefined &&
                    chosen.variants.length > 1 && (
                        <div className="grid gap-2">
                            <Label>Variante</Label>
                            <Select
                                value={
                                    variant === undefined
                                        ? undefined
                                        : String(variant.id)
                                }
                                onValueChange={(value) =>
                                    setVariantId(Number(value))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {chosen.variants.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {/*
                                             * O SKU entra aqui porque a cor e
                                             * o tamanho podem faltar: sem ele
                                             * um produto com várias variantes
                                             * sem etiqueta dava uma lista de
                                             * "Única" indistinguíveis.
                                             */}
                                            {option.variantLabel} · {option.sku}{' '}
                                            · {formatCents(option.priceCents)} ·{' '}
                                            {option.availableStock} un.
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )
                )}

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border/60 bg-muted px-4 py-3">
                    <div>
                        <p className="text-sm font-medium">Quantidade</p>
                        <p className="text-xs text-muted-foreground tabular-nums">
                            {unitCents === 0
                                ? 'preço por definir'
                                : `${formatCents(unitCents)} cada`}
                        </p>
                    </div>

                    <div className="flex items-center gap-1 rounded-full border border-border/60 bg-background p-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 rounded-full"
                            onClick={() =>
                                setQty((current) => Math.max(1, current - 1))
                            }
                            aria-label="Menos um"
                        >
                            −
                        </Button>
                        <Input
                            type="number"
                            min={1}
                            value={qty}
                            onChange={(event) =>
                                setQty(Math.max(1, Number(event.target.value)))
                            }
                            aria-label="Quantidade"
                            className="h-8 w-14 border-0 bg-transparent text-center font-medium tabular-nums shadow-none focus-visible:ring-0"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 rounded-full"
                            onClick={() => setQty((current) => current + 1)}
                            aria-label="Mais um"
                        >
                            +
                        </Button>
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3">
                    <span className="text-sm text-muted-foreground tabular-nums">
                        Total: {formatCents(unitCents * qty)}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            onClick={confirm}
                            disabled={!ready}
                        >
                            {item === null ? 'Adicionar' : 'Guardar'}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
