import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
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
import { Spinner } from '@/components/ui/spinner';
import {
    centsToInput,
    formatCents,
    formatCostPerGram,
    inputToCents,
} from '@/lib/money';
import { store, update } from '@/routes/admin/materiais';
import type { MaterialFormData, MaterialRow } from '@/types/catalog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; uma linha da listagem edita esse material. */
    editing: MaterialRow | null;
    families: string[];
};

const NO_FAMILY = 'none';

/** Peça de referência para traduzir o custo/g em dinheiro que se reconhece. */
const SAMPLE_PIECE_GRAMS = 100;

/**
 * Criar e editar um material — os dois no mesmo sítio, para não haver dois
 * formulários da mesma coisa a divergir com o tempo.
 *
 * Um material é a bobine: nome, família, fornecedor, preço por quilo e stock.
 * Não tem cores — o mesmo "Preto" imprime-se em qualquer material, e é a
 * variante do produto que cruza os dois eixos. Este formulário já ofereceu
 * chips de cor e um mini-formulário para inventar uma; sairam com a ligação.
 *
 * Não há efeito nenhum a sincronizar o formulário com o `editing`: quem monta
 * este componente dá-lhe uma `key` que muda com o alvo, e o remonte trata do
 * resto. Um `useEffect` a chamar `setData` competia com o que o admin está a
 * escrever no momento em que a resposta do servidor chega.
 */
export function MaterialCreateDialog({
    open,
    onOpenChange,
    editing,
    families,
}: Props) {
    const { data, setData, post, patch, processing, errors, reset } =
        useForm<MaterialFormData>({
            name: editing?.name ?? '',
            family: editing?.family ?? '',
            supplier: editing?.supplier ?? '',
            price_per_kg: editing ? centsToInput(editing.pricePerKgCents) : '',
            spools_in_stock: editing?.spoolsInStock ?? 0,
            min_spools: editing?.minSpools ?? 0,
            sort_order: editing?.sortOrder ?? 0,
        });

    const pricePerKgCents = inputToCents(data.price_per_kg);
    const canSave = data.name.trim() !== '' && pricePerKgCents > 0;

    const submit = () => {
        const options = {
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        };

        if (editing) {
            // A rota aceita PUT e PATCH; PATCH é o que diz a verdade sobre o
            // payload, que não traz o `active`.
            patch(update(editing.id).url, options);

            return;
        }

        post(store().url, options);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-border/60 p-6">
                    <DialogTitle>
                        {editing ? 'Editar material' : 'Novo material'}
                    </DialogTitle>
                    <DialogDescription>
                        O preço por quilo entra no cálculo do custo de cada
                        produto.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-6 p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="material-name">Nome</Label>
                            <Input
                                id="material-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                placeholder="PLA Silk"
                                maxLength={60}
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Família</Label>
                            <Select
                                value={
                                    data.family === '' ? NO_FAMILY : data.family
                                }
                                onValueChange={(value) =>
                                    setData(
                                        'family',
                                        value === NO_FAMILY ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Sem família" />
                                </SelectTrigger>
                                <SelectContent>
                                    {/* Radix não aceita value="" — daí o NO_FAMILY. */}
                                    <SelectItem value={NO_FAMILY}>
                                        Sem família
                                    </SelectItem>
                                    {families.map((family) => (
                                        <SelectItem key={family} value={family}>
                                            {family}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.family} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="material-price">
                                Preço por quilo
                            </Label>
                            <div className="relative">
                                <Input
                                    id="material-price"
                                    type="number"
                                    step="0.5"
                                    min={0}
                                    value={data.price_per_kg}
                                    onChange={(event) =>
                                        setData(
                                            'price_per_kg',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="21,90"
                                    className="pr-8 tabular-nums"
                                />
                                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                    €
                                </span>
                            </div>
                            <InputError message={errors.price_per_kg} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="material-supplier">
                                Fornecedor
                            </Label>
                            <Input
                                id="material-supplier"
                                value={data.supplier}
                                onChange={(event) =>
                                    setData('supplier', event.target.value)
                                }
                                placeholder="Prusament"
                                maxLength={60}
                            />
                            <InputError message={errors.supplier} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="material-min-spools">
                                Stock mínimo
                            </Label>
                            <div className="relative">
                                <Input
                                    id="material-min-spools"
                                    type="number"
                                    min={0}
                                    value={data.min_spools}
                                    onChange={(event) =>
                                        setData(
                                            'min_spools',
                                            Number(event.target.value),
                                        )
                                    }
                                    placeholder="3"
                                    className="pr-20 tabular-nums"
                                />
                                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                    bobines
                                </span>
                            </div>
                            <InputError message={errors.min_spools} />
                        </div>
                    </div>

                    {/*
                     * Contas ao vivo, só para dar noção — o servidor é que
                     * converte e grava. Zero significa "ainda não escreveu um
                     * preço", e mostrar "0,000 €" era fingir uma resposta.
                     */}
                    <div className="flex items-center justify-between gap-4 rounded-xl border border-border/60 bg-muted/40 p-4">
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Custo por grama
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Uma peça de {SAMPLE_PIECE_GRAMS} g fica em{' '}
                                <span className="tabular-nums">
                                    {pricePerKgCents > 0
                                        ? formatCents(
                                              Math.round(
                                                  (pricePerKgCents *
                                                      SAMPLE_PIECE_GRAMS) /
                                                      1000,
                                              ),
                                          )
                                        : '—'}
                                </span>
                                .
                            </p>
                        </div>
                        <p className="text-lg font-semibold tabular-nums">
                            {pricePerKgCents > 0
                                ? formatCostPerGram(pricePerKgCents)
                                : '—'}
                        </p>
                    </div>

                    {/*
                     * Stock e ordem aparecem sempre. Ficaram escondidos no criar
                     * enquanto este modal tinha um bloco de cores a competir
                     * pelo espaço; sem ele, esconder duas colunas que a base de
                     * dados aceita na criação era ceremonia sem ganho.
                     */}
                    <div className="grid gap-4 border-t border-border/60 pt-6 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="material-spools">
                                Bobines em stock
                            </Label>
                            <Input
                                id="material-spools"
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
                            <p className="text-xs text-muted-foreground">
                                Abaixo do stock mínimo o material aparece como
                                "stock baixo".
                            </p>
                            <InputError message={errors.spools_in_stock} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="material-sort-order">Ordem</Label>
                            <Input
                                id="material-sort-order"
                                type="number"
                                min={0}
                                value={data.sort_order}
                                onChange={(event) =>
                                    setData(
                                        'sort_order',
                                        Number(event.target.value),
                                    )
                                }
                                className="tabular-nums"
                            />
                            <p className="text-xs text-muted-foreground">
                                Menor primeiro. Em caso de empate manda o nome.
                            </p>
                            <InputError message={errors.sort_order} />
                        </div>
                    </div>
                </div>

                <DialogFooter className="border-t border-border/60 p-6">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={!canSave || processing}
                    >
                        {processing && <Spinner />}
                        {editing ? 'Guardar alterações' : 'Criar material'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
