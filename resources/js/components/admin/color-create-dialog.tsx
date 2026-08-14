import { useForm } from '@inertiajs/react';
import { ColorPicker } from '@/components/admin/color-picker';
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

    /**
     * Escolher o tom pode também dar o nome, mas só quando o tom sai exatamente
     * de um atalho e o nome ainda está vazio — quem escreveu "Verde azeitona" e
     * depois foi buscar o tom não o quer perder, e um tom apanhado no espectro
     * não tem nome nenhum para dar.
     */
    const pickHex = (hex: string) => {
        const preset = palette.find(
            (color) => color.hex.toUpperCase() === hex.toUpperCase(),
        );

        setData((current) => ({
            ...current,
            hex_color: hex,
            name:
                preset !== undefined && current.name.trim() === ''
                    ? preset.name
                    : current.name,
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
                    <div className="grid gap-2">
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
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Tom</Label>
                        <ColorPicker
                            value={data.hex_color}
                            onChange={pickHex}
                            presets={palette}
                            idPrefix="color"
                        />
                        <InputError message={errors.hex_color} />
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
