import { useForm } from '@inertiajs/react';
import {
    CategoryColorPicker,
    CategoryStatusPicker,
} from '@/components/admin/category-fields';
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
import { Textarea } from '@/components/ui/textarea';
import { slugify } from '@/lib/slug';
import { store } from '@/routes/admin/categorias';
import type { CategoryFormData } from '@/types/catalog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function CategoryCreateDialog({ open, onOpenChange }: Props) {
    const { data, setData, post, processing, errors, reset } =
        useForm<CategoryFormData>({
            name: '',
            description: '',
            status: 'visible',
            color: null,
            sort_order: 0,
        });

    /*
     * O slug não é um campo: é derivado do nome no CategoryService e o Form
     * Request nem sequer o aceita. Aqui só se pré-visualiza, para quem escreve
     * o nome ver logo que endereço é que a categoria vai ter.
     */
    const slug = slugify(data.name);

    /*
     * Um nome só de símbolos ("***") passa o `trim()` mas dá slug vazio, e uma
     * categoria sem slug não tem endereço nenhum. Por isso a condição olha para
     * os dois.
     */
    const canCreate = data.name.trim() !== '' && slug !== '';

    const submit = () =>
        post(store().url, {
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-lg">
                <DialogHeader className="border-b border-border/60 p-6">
                    <DialogTitle>Nova categoria</DialogTitle>
                    <DialogDescription>
                        O slug é gerado a partir do nome e usado no endereço da
                        loja.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-6 p-6">
                    <div className="grid gap-2">
                        <Label htmlFor="category-name">Nome</Label>
                        <Input
                            id="category-name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder="Decoração"
                            maxLength={120}
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-baseline justify-between gap-3">
                            <Label htmlFor="category-slug">Slug</Label>
                            <span className="text-xs text-muted-foreground">
                                /loja/{slug || '…'}
                            </span>
                        </div>
                        <Input
                            id="category-slug"
                            value={slug}
                            readOnly
                            tabIndex={-1}
                            placeholder="decoracao"
                            className="font-mono text-muted-foreground"
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="category-description">
                            Descrição{' '}
                            <span className="font-normal text-muted-foreground">
                                (opcional)
                            </span>
                        </Label>
                        <Textarea
                            id="category-description"
                            rows={2}
                            value={data.description}
                            onChange={(event) =>
                                setData('description', event.target.value)
                            }
                            placeholder="Vasos, castiçais e peças de parede."
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-3">
                            <Label>Cor da categoria</Label>
                            <span className="text-xs text-muted-foreground">
                                Usada na etiqueta e no menu da loja.
                            </span>
                        </div>
                        <CategoryColorPicker
                            value={data.color}
                            onChange={(hex) => setData('color', hex)}
                        />
                        <InputError message={errors.color} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Visibilidade</Label>
                        <CategoryStatusPicker
                            value={data.status}
                            onChange={(status) => setData('status', status)}
                        />
                        <InputError message={errors.status} />
                    </div>
                </div>

                <DialogFooter className="items-center gap-3 border-t border-border/60 bg-secondary/30 p-6">
                    <Button
                        type="button"
                        variant="outline"
                        className="rounded-full"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        className="rounded-full"
                        disabled={processing || !canCreate}
                        onClick={submit}
                    >
                        Criar categoria
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
