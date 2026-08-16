import { useForm } from '@inertiajs/react';
import { ColorPicker } from '@/components/admin/color-picker';
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
import { Spinner } from '@/components/ui/spinner';
import { isPlainHex } from '@/lib/color';
import { store, update } from '@/routes/admin/cores';
import type {
    ColorFormData,
    ColorRow,
    MaterialOption,
    PaletteColor,
} from '@/types/catalog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; uma cor edita. */
    editing: ColorRow | null;
    palette: PaletteColor[];
    materials: MaterialOption[];
};

/**
 * Criar e editar uma cor: um nome, um tom, os filamentos em que existe e a
 * ordem em que aparece.
 *
 * Os filamentos estão aqui e não no produto porque a limitação é do stock de
 * bobines, não da peça: se não há rosa silk, não há para peça nenhuma, e
 * repetir isso em cada produto era esperar que os cem se lembrassem do mesmo.
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
    materials,
}: Props) {
    const { data, setData, post, put, processing, errors, reset } =
        useForm<ColorFormData>({
            name: editing?.name ?? '',
            hex_color: editing?.hex ?? '',
            sort_order: editing?.sortOrder ?? 0,
            material_ids:
                editing?.materials.map((material) => material.id) ?? [],
        });

    // Os filamentos não entram aqui: uma cor por declarar é um estado legítimo,
    // e obrigar a escolher um agora era impedir de apontar a cor antes de saber
    // em que bobines ela vai existir. O aviso da listagem é que faz a cobrança.
    const canSave = data.name.trim() !== '' && isPlainHex(data.hex_color);

    const toggleMaterial = (id: number) => {
        setData(
            'material_ids',
            data.material_ids.includes(id)
                ? data.material_ids.filter((current) => current !== id)
                : [...data.material_ids, id],
        );
    };

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
                        Uma cor é um nome, um tom e os filamentos em que a tens.
                        O preço por quilo é da bobine.
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

                    <div className="grid gap-2">
                        <Label>Tenho esta cor em</Label>
                        {materials.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Ainda não há filamentos no catálogo. Cria-os em
                                Materiais.
                            </p>
                        ) : (
                            <>
                                <div className="flex flex-wrap gap-2">
                                    {materials.map((material) => (
                                        <ToggleChip
                                            key={material.id}
                                            active={data.material_ids.includes(
                                                material.id,
                                            )}
                                            onClick={() =>
                                                toggleMaterial(material.id)
                                            }
                                        >
                                            {material.name}
                                        </ToggleChip>
                                    ))}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {data.material_ids.length === 0
                                        ? 'Enquanto não escolheres nenhum, esta cor não gera variantes de produto.'
                                        : 'Desmarcar um filamento esconde da loja as variantes que o usavam — nunca as apaga.'}
                                </p>
                            </>
                        )}
                        <InputError message={errors.material_ids} />
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
