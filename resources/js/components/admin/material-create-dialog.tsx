import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ToggleChip } from '@/components/admin/toggle-chip';
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
import { isPlainHex, sameColorName } from '@/lib/color';
import { formatCents, formatCostPerGram, inputToCents } from '@/lib/money';
import { store } from '@/routes/admin/materiais';
import type { MaterialQuickFormData, PaletteColor } from '@/types/catalog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** As cores que já existem (App\Support\ColorPalette). */
    colorOptions: PaletteColor[];
    families: string[];
};

const NO_FAMILY = 'none';

/** Peça de referência para traduzir o custo/g em dinheiro que se reconhece. */
const SAMPLE_PIECE_GRAMS = 100;

/** Tom de partida do formulário de cor nova, só para o seletor abrir com algo. */
const DRAFT_HEX = '#8FAE7F';

const EMPTY_DRAFT: PaletteColor = { name: '', hex: DRAFT_HEX };

/**
 * Criar um material e as suas primeiras cores de uma vez.
 *
 * As chips são as cores que já existem em /admin/cores. Na base de dados uma
 * Color pertence a UM material — ligar "Terracota" aqui cria a linha
 * Terracota×este material, com o hex da cor que já lá está e o preço a herdar.
 *
 * Quem precisa de uma cor que ainda não existe inventa-a no mini-formulário, e
 * ela nasce com o material na mesma transação: sem cores o material não gera
 * variante nenhuma, e mandar o admin a /admin/cores a meio do formulário era
 * perder o que ele já tinha escrito.
 *
 * Sem uma única cor na base de dados o servidor manda os presets do
 * App\Support\FilamentPalette — senão o primeiro material da instalação abria
 * sem uma chip para ligar.
 */
export function MaterialCreateDialog({
    open,
    onOpenChange,
    colorOptions,
    families,
}: Props) {
    const { data, setData, post, processing, errors, reset } =
        useForm<MaterialQuickFormData>({
            name: '',
            family: '',
            supplier: '',
            price_per_kg: '',
            min_spools: 0,
            colors: [],
        });

    /*
     * Estado só do mini-formulário. As cores inventadas não vivem aqui: entram
     * logo em `data.colors`, que é a única lista. Uma segunda lista a espelhar
     * a primeira era a forma clássica de tirar uma chip e o payload continuar a
     * mandá-la.
     */
    const [adding, setAdding] = useState(false);
    const [draft, setDraft] = useState<PaletteColor>(EMPTY_DRAFT);

    const pricePerKgCents = inputToCents(data.price_per_kg);
    const canCreate =
        data.name.trim() !== '' &&
        pricePerKgCents > 0 &&
        data.colors.length > 0;

    const isChosen = (name: string) =>
        data.colors.some((color) => sameColorName(color.name, name));

    /** A cor com este nome, venha das chips ou de uma já inventada acima. */
    const existing = (name: string) =>
        colorOptions.find((color) => sameColorName(color.name, name)) ??
        data.colors.find((color) => sameColorName(color.name, name));

    /*
     * As inventadas mostram-se a seguir às chips do catálogo, e são exatamente
     * as escolhidas que não estão lá — derivadas, nunca guardadas à parte.
     */
    const invented = data.colors.filter(
        (color) =>
            !colorOptions.some((option) =>
                sameColorName(option.name, color.name),
            ),
    );

    const toggleColor = (color: PaletteColor) =>
        setData(
            'colors',
            isChosen(color.name)
                ? data.colors.filter(
                      (current) => !sameColorName(current.name, color.name),
                  )
                : [...data.colors, color],
        );

    const draftName = draft.name.trim();
    const draftMatch = existing(draftName);
    const canAdd = draftName !== '' && isPlainHex(draft.hex);

    /*
     * Nada vai ao servidor: a cor só nasce quando o material nascer. Se o nome
     * já existir, liga-se a cor que já lá está em vez de criar uma quase-igual
     * — é o servidor que manda no hex desses nomes, e ficar com "preto" ao lado
     * de "Preto" na listagem de cores não é o que ninguém queria.
     */
    const addDraft = () => {
        if (!isChosen(draftName)) {
            setData('colors', [
                ...data.colors,
                draftMatch ?? { name: draftName, hex: draft.hex },
            ]);
        }

        setDraft(EMPTY_DRAFT);
        setAdding(false);
    };

    /*
     * O primeiro erro de cor, venha ele em `colors`, `colors.0.name` ou
     * `colors.0.hex` — o InputError mostra um, e é o que a chave exata não dá
     * de bandeja.
     */
    const colorError = Object.entries(errors).find(([key]) =>
        key.startsWith('colors'),
    )?.[1];

    const submit = () =>
        post(store().url, {
            onSuccess: () => {
                reset();
                setDraft(EMPTY_DRAFT);
                setAdding(false);
                onOpenChange(false);
            },
        });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-border/60 p-6">
                    <DialogTitle>Novo material</DialogTitle>
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

                    <div className="grid gap-3 border-t border-border/60 pt-6">
                        <div className="flex items-baseline justify-between gap-3">
                            <Label>Cores disponíveis</Label>
                            <span className="text-xs text-muted-foreground">
                                {data.colors.length === 0
                                    ? 'nenhuma selecionada'
                                    : data.colors.length === 1
                                      ? '1 selecionada'
                                      : `${data.colors.length} selecionadas`}
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {colorOptions.map((color) => (
                                <ToggleChip
                                    key={color.name}
                                    active={isChosen(color.name)}
                                    onClick={() => toggleColor(color)}
                                >
                                    <ColorSwatch
                                        hex={color.hex}
                                        className="size-3.5"
                                    />
                                    {color.name}
                                </ToggleChip>
                            ))}

                            {/*
                             * Ligadas por definição — existem porque o admin as
                             * escreveu. Clicar tira-as, que é a única coisa que
                             * "desligar" pode querer dizer numa cor que ainda
                             * não existe em lado nenhum.
                             */}
                            {invented.map((color) => (
                                <ToggleChip
                                    key={color.name}
                                    active
                                    onClick={() => toggleColor(color)}
                                >
                                    <ColorSwatch
                                        hex={color.hex}
                                        className="size-3.5"
                                    />
                                    {color.name}
                                    <span className="font-normal text-muted-foreground">
                                        nova
                                    </span>
                                </ToggleChip>
                            ))}

                            <button
                                type="button"
                                onClick={() => setAdding((open) => !open)}
                                aria-expanded={adding}
                                className="flex items-center gap-1.5 rounded-full border border-dashed border-border px-3.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:border-ring hover:text-foreground"
                            >
                                <Plus className="size-3.5" />
                                Nova cor
                            </button>
                        </div>

                        {adding && (
                            <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border/60 bg-muted/40 p-4">
                                {/*
                                 * O seletor nativo é o próprio quadrado de
                                 * pré-visualização: dá o picker do sistema sem
                                 * uma linha de JavaScript, e o campo ao lado
                                 * continua a aceitar um hex colado.
                                 */}
                                <input
                                    type="color"
                                    value={
                                        isPlainHex(draft.hex)
                                            ? draft.hex
                                            : DRAFT_HEX
                                    }
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            hex: event.target.value.toUpperCase(),
                                        }))
                                    }
                                    aria-label="Tom da cor nova"
                                    className="size-9 shrink-0 cursor-pointer rounded-lg border border-border bg-transparent"
                                />
                                <div className="grid min-w-40 flex-1 gap-2">
                                    <Label htmlFor="material-color-name">
                                        Nome da cor
                                    </Label>
                                    <Input
                                        id="material-color-name"
                                        value={draft.name}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                name: event.target.value,
                                            }))
                                        }
                                        placeholder="Verde azeitona"
                                        maxLength={60}
                                    />
                                </div>
                                <div className="grid w-32 shrink-0 gap-2">
                                    <Label htmlFor="material-color-hex">
                                        Hex
                                    </Label>
                                    <Input
                                        id="material-color-hex"
                                        value={draft.hex}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                hex: event.target.value,
                                            }))
                                        }
                                        placeholder="#8FAE7F"
                                        maxLength={9}
                                        className="font-mono uppercase"
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addDraft}
                                    disabled={!canAdd}
                                >
                                    Adicionar
                                </Button>

                                {draftMatch && (
                                    <p className="w-full text-xs text-muted-foreground">
                                        «{draftMatch.name}» já existe —
                                        Adicionar liga a chip que já lá está.
                                    </p>
                                )}
                            </div>
                        )}

                        <p className="text-xs text-muted-foreground">
                            Só estas cores ficam disponíveis nas variantes dos
                            produtos com este material. Todas herdam este preço
                            por quilo — o preço próprio e a imagem afinam-se
                            depois em Cores.
                        </p>
                        <InputError message={colorError} />
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
                        disabled={!canCreate || processing}
                    >
                        {processing && <Spinner />}
                        Criar material
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
