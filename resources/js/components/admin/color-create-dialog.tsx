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
import { Spinner } from '@/components/ui/spinner';
import { isPlainHex } from '@/lib/color';
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/admin/cores';
import type { ColorFormData, ColorRow, PaletteColor } from '@/types/catalog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; uma cor edita. */
    editing: ColorRow | null;
    palette: PaletteColor[];
};

/**
 * Criar e editar uma cor: um nome, um tom e a ordem em que aparece.
 *
 * Não há materiais aqui, e é essa a questão — uma cor imprime-se em qualquer
 * bobine. O cruzamento acontece na variante, ao criar um produto.
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
    palette,
}: Props) {
    const { data, setData, post, put, processing, errors, reset } =
        useForm<ColorFormData>({
            name: editing?.name ?? '',
            hex_color: editing?.hex ?? '',
            sort_order: editing?.sortOrder ?? 0,
        });

    const canSave = data.name.trim() !== '' && isPlainHex(data.hex_color);

    const pickSwatch = (color: PaletteColor) => {
        setData((current) => ({
            ...current,
            hex_color: color.hex,
            // O nome só se preenche se ainda estiver vazio: quem escreveu
            // "Verde azeitona" e depois foi buscar o tom não quer perdê-lo.
            name: current.name.trim() === '' ? color.name : current.name,
        }));
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
                        Uma cor é um nome e um tom. Imprime-se em qualquer
                        material — o preço por quilo é da bobine.
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

                    <div className="grid w-32 gap-2 border-t border-border/60 pt-6">
                        <Label htmlFor="color-order">Ordem</Label>
                        <Input
                            id="color-order"
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
                        <InputError message={errors.sort_order} />
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
