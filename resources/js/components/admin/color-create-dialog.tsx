import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ToggleChip } from '@/components/admin/toggle-chip';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Spinner } from '@/components/ui/spinner';
import { isPlainHex } from '@/lib/color';
import { centsToInput, formatCents, inputToCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/admin/cores';
import type {
    ColorGroupFormData,
    ColorGroupRow,
    ColorMaterialCard,
    PaletteColor,
} from '@/types/catalog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; um grupo edita todas as linhas com aquele nome. */
    editing: ColorGroupRow | null;
    materials: ColorMaterialCard[];
    palette: PaletteColor[];
};

/**
 * Criar e editar uma cor — os dois no mesmo sítio, porque escolher em que
 * materiais ela existe faz parte de a definir.
 *
 * O que aqui se grava vai em leque: uma linha `colors` por material ligado, com
 * o mesmo nome e o mesmo hex. Tirar um material arquiva a linha respetiva em vez
 * de a apagar (as variantes que já a usam continuam a apontar para lá), e é o
 * ColorService que trata disso.
 *
 * Não há efeito nenhum a sincronizar o formulário com o `editing`: quem monta
 * este componente dá-lhe uma `key` que muda com o alvo, e o remonte trata do
 * resto. Um `useEffect` a chamar `setData` competia com o que o admin está a
 * escrever no momento em que a resposta do servidor chega.
 */
export function ColorCreateDialog({
    open,
    onOpenChange,
    editing,
    materials,
    palette,
}: Props) {
    const { data, setData, post, put, processing, errors, reset } =
        useForm<ColorGroupFormData>({
            name: editing?.name ?? '',
            hex_color: editing?.hex ?? '',
            material_ids: editing?.materialIds ?? [],
            price_per_kg: centsToInput(editing?.ownPricePerKgCents ?? null),
        });

    /*
     * O override não é um campo do payload: é a decisão de mandar ou não mandar
     * `price_per_kg`. Vazio significa "herda de cada material" — nunca zero.
     */
    const hasOwnPrice =
        editing !== null &&
        (editing.priceOrigin === 'own' || editing.priceOrigin === 'mixed');
    const [override, setOverride] = useState(hasOwnPrice);

    const chosen = materials.filter((material) =>
        data.material_ids.includes(material.id),
    );

    const priceCents = inputToCents(data.price_per_kg);
    const canSave =
        data.name.trim() !== '' &&
        isPlainHex(data.hex_color) &&
        data.material_ids.length > 0 &&
        (!override || priceCents > 0);

    const toggleMaterial = (id: number) =>
        setData(
            'material_ids',
            data.material_ids.includes(id)
                ? data.material_ids.filter((current) => current !== id)
                : [...data.material_ids, id],
        );

    const pickSwatch = (color: PaletteColor) => {
        setData((current) => ({
            ...current,
            hex_color: color.hex,
            // O nome só se preenche se ainda estiver vazio: quem escreveu
            // "Verde azeitona" e depois foi buscar o tom não quer perdê-lo.
            name: current.name.trim() === '' ? color.name : current.name,
        }));
    };

    const toggleOverride = (on: boolean) => {
        setOverride(on);

        if (!on) {
            setData('price_per_kg', '');
        }
    };

    const submit = () => {
        const options = {
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        };

        if (editing) {
            put(update(editing.id).url, options);

            return;
        }

        post(store().url, options);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-xl">
                <DialogHeader className="border-b border-border/60 p-6">
                    <DialogTitle>
                        {editing ? 'Editar cor' : 'Nova cor'}
                    </DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'Mudar os materiais onde existe muda as variantes disponíveis nos produtos.'
                            : 'Escolhe em que materiais existe. O preço vem de cada material, a não ser que definas um próprio.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-6 p-6">
                    <div className="flex items-end gap-4">
                        <span
                            aria-hidden
                            className={cn(
                                'size-16 shrink-0 rounded-xl border border-border',
                                !isPlainHex(data.hex_color) && 'bg-muted',
                            )}
                            style={
                                isPlainHex(data.hex_color)
                                    ? { backgroundColor: data.hex_color }
                                    : undefined
                            }
                        />
                        <div className="grid min-w-0 flex-1 gap-2">
                            <Label htmlFor="color-name">Nome</Label>
                            <Input
                                id="color-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                placeholder="Verde musgo"
                                maxLength={60}
                                autoFocus
                            />
                        </div>
                        <div className="grid w-32 shrink-0 gap-2">
                            <Label htmlFor="color-hex">Hex</Label>
                            <Input
                                id="color-hex"
                                value={data.hex_color}
                                onChange={(event) =>
                                    setData('hex_color', event.target.value)
                                }
                                placeholder="#8FAE7F"
                                maxLength={9}
                                className="font-mono uppercase"
                            />
                        </div>
                    </div>

                    <InputError message={errors.name} />
                    <InputError message={errors.hex_color} />

                    <div className="flex flex-wrap gap-2">
                        {palette.map((color) => (
                            <button
                                key={color.name}
                                type="button"
                                onClick={() => pickSwatch(color)}
                                title={color.name}
                                aria-label={color.name}
                                aria-pressed={data.hex_color === color.hex}
                                className={cn(
                                    'size-7 rounded-lg border transition-colors',
                                    data.hex_color === color.hex
                                        ? 'border-ring ring-2 ring-ring/40'
                                        : 'border-border hover:border-ring',
                                )}
                                style={{ backgroundColor: color.hex }}
                            />
                        ))}
                    </div>

                    <div className="grid gap-3 border-t border-border/60 pt-6">
                        <div className="flex items-baseline justify-between gap-3">
                            <Label>Materiais em que existe</Label>
                            <span
                                className={cn(
                                    'text-xs',
                                    data.material_ids.length === 0
                                        ? 'text-warning'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {data.material_ids.length === 0
                                    ? 'nenhum selecionado'
                                    : data.material_ids.length === 1
                                      ? '1 selecionado'
                                      : `${data.material_ids.length} selecionados`}
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {materials.map((material) => (
                                <ToggleChip
                                    key={material.id}
                                    active={data.material_ids.includes(
                                        material.id,
                                    )}
                                    onClick={() => toggleMaterial(material.id)}
                                >
                                    {material.name}
                                    <span className="text-muted-foreground tabular-nums">
                                        {formatCents(material.pricePerKgCents)}
                                    </span>
                                </ToggleChip>
                            ))}
                        </div>
                        <InputError message={errors.material_ids} />
                    </div>

                    <div className="flex flex-col gap-3 rounded-xl border border-border/60 bg-muted/40 p-4">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <Label
                                    htmlFor="color-override"
                                    className="text-sm"
                                >
                                    Preço próprio
                                </Label>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {override
                                        ? 'Ignora o preço dos materiais selecionados.'
                                        : chosen.length > 0
                                          ? `Herda de ${chosen.map((material) => material.name).join(', ')}.`
                                          : 'Sem preço próprio, herda de cada material.'}
                                </p>
                            </div>
                            <Checkbox
                                id="color-override"
                                checked={override}
                                onCheckedChange={(checked) =>
                                    toggleOverride(checked === true)
                                }
                            />
                        </div>

                        {override && (
                            <div className="grid gap-2">
                                <div className="relative">
                                    <Input
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
                                        placeholder="29,00"
                                        className="pr-16 tabular-nums"
                                        aria-label="Preço próprio por quilo"
                                    />
                                    <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                        € / kg
                                    </span>
                                </div>
                                {/*
                                 * O campo é um valor só. Se o grupo trazia
                                 * preços diferentes por material, o servidor
                                 * mandou-o vazio de propósito — gravar aqui
                                 * uniformiza-os, e vale a pena dizê-lo.
                                 */}
                                {editing?.priceOrigin === 'mixed' && (
                                    <p className="text-xs text-warning">
                                        Esta cor tem preços diferentes conforme
                                        o material. O valor que escreveres passa
                                        a valer em todos.
                                    </p>
                                )}
                                <InputError message={errors.price_per_kg} />
                            </div>
                        )}
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
                        {editing ? 'Guardar alterações' : 'Criar cor'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
