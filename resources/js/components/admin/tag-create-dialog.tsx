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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { slugify } from '@/lib/slug';
import { store, update } from '@/routes/admin/etiquetas';
import type { TagFormData, TagRow } from '@/types/tag';
import { TAG_SCOPES } from '@/types/tag';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; uma etiqueta edita. */
    editing: TagRow | null;
    /** Todas as etiquetas, para detetar a colisão enquanto se escreve. */
    tags: TagRow[];
};

/**
 * Criar e editar uma etiqueta: um âmbito e um nome.
 *
 * O âmbito só se escolhe ao criar. Mudá-lo depois deixava os usos existentes a
 * apontar para o pivot errado — para mover, cria-se no âmbito certo.
 *
 * Não há efeito nenhum a sincronizar o formulário com o `editing`: quem monta
 * este componente dá-lhe uma `key` que muda com o alvo, e o remonte trata do
 * resto. Mesmo mecanismo dos modais das cores, dos materiais e das impressoras.
 */
export function TagCreateDialog({ open, onOpenChange, editing, tags }: Props) {
    const { data, setData, post, put, processing, errors, reset } =
        useForm<TagFormData>({
            scope: editing?.scope ?? 'product',
            name: editing?.name ?? '',
        });

    const slug = slugify(data.name);

    /*
     * A etiqueta que já ocupa este slug no mesmo âmbito, se houver outra. Guardar
     * por cima dela não é erro — funde as duas —, mas é uma operação que apaga
     * uma linha, por isso diz-se antes e não depois.
     */
    const collision = tags.find(
        (tag) =>
            tag.scope === data.scope &&
            tag.slug === slug &&
            tag.id !== editing?.id,
    );

    const canSave = slug !== '' && !processing;

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
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {editing ? 'Editar etiqueta' : 'Nova etiqueta'}
                    </DialogTitle>
                    <DialogDescription>
                        Uma etiqueta vive num âmbito. As de cliente nunca se
                        sugerem num produto, e vice-versa.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-5">
                    <div className="grid gap-2">
                        <Label className="text-xs tracking-wider text-muted-foreground uppercase">
                            Âmbito
                        </Label>
                        {editing ? (
                            <p className="text-sm text-muted-foreground">
                                {TAG_SCOPES.find(
                                    (scope) => scope.value === editing.scope,
                                )?.label ?? editing.scope}{' '}
                                — o âmbito não muda depois de criada.
                            </p>
                        ) : (
                            <ToggleGroup
                                type="single"
                                value={data.scope}
                                // O Radix devolve '' ao clicar no item já ativo.
                                onValueChange={(value) =>
                                    value && setData('scope', value)
                                }
                                variant="outline"
                                className="w-fit"
                            >
                                {TAG_SCOPES.map((scope) => (
                                    <ToggleGroupItem
                                        key={scope.value}
                                        value={scope.value}
                                    >
                                        {scope.chipLabel}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        )}
                        <InputError message={errors.scope} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="tag-name">Nome</Label>
                        <Input
                            id="tag-name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder="natal"
                            maxLength={60}
                            autoFocus
                        />
                        <InputError message={errors.name} />

                        {collision && (
                            <p className="text-sm text-warning">
                                Já existe «{collision.name}» neste âmbito.
                                Guardar funde as duas:{' '}
                                {collision.usageCount === 1
                                    ? '1 item fica'
                                    : `${collision.usageCount} itens ficam`}{' '}
                                com uma etiqueta só.
                            </p>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button type="button" onClick={submit} disabled={!canSave}>
                        {processing && <Spinner />}
                        {collision
                            ? 'Fundir etiquetas'
                            : editing
                              ? 'Guardar alterações'
                              : 'Criar etiqueta'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
