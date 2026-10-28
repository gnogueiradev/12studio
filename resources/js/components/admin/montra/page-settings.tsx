import { router, useForm } from '@inertiajs/react';
import { History, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
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
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { definicoes, repor } from '@/routes/admin/montra/paginas';
import type { EditablePage, Revision } from '@/types/page-builder';
import { formatDateTime } from './page-status';

type Seo = EditablePage['seo'];

/**
 * O título e a descrição para motores de busca, por idioma. Gravam-se com o
 * rascunho (autosave) e publicam-se com a página.
 */
export function SeoDialog({
    open,
    onOpenChange,
    seo,
    locales,
    onChange,
    errors,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    seo: Seo;
    locales: string[];
    onChange: (seo: Seo) => void;
    errors: Record<string, string>;
}) {
    const set = (locale: string, key: 'title' | 'description', value: string) =>
        onChange({
            ...seo,
            [locale]: { ...(seo[locale] ?? {}), [key]: value },
        });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Motores de busca</DialogTitle>
                    <DialogDescription>
                        O título do separador e o texto que o Google mostra.
                        Vazio num idioma, fica o português.
                    </DialogDescription>
                </DialogHeader>
                <Tabs defaultValue={locales[0]}>
                    <TabsList>
                        {locales.map((locale) => (
                            <TabsTrigger key={locale} value={locale}>
                                {locale.toUpperCase()}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    {locales.map((locale) => (
                        <TabsContent
                            key={locale}
                            value={locale}
                            className="grid gap-3 pt-2"
                        >
                            <div className="grid gap-1.5">
                                <Label htmlFor={`seo-${locale}-title`}>
                                    Título
                                </Label>
                                <Input
                                    id={`seo-${locale}-title`}
                                    value={seo[locale]?.title ?? ''}
                                    maxLength={70}
                                    onChange={(event) =>
                                        set(locale, 'title', event.target.value)
                                    }
                                />
                                <InputError
                                    message={errors[`seo.${locale}.title`]}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`seo-${locale}-description`}>
                                    Descrição
                                </Label>
                                <Textarea
                                    id={`seo-${locale}-description`}
                                    value={seo[locale]?.description ?? ''}
                                    maxLength={170}
                                    rows={3}
                                    onChange={(event) =>
                                        set(
                                            locale,
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {(seo[locale]?.description ?? '').length}
                                    /170
                                </p>
                                <InputError
                                    message={
                                        errors[`seo.${locale}.description`]
                                    }
                                />
                            </div>
                        </TabsContent>
                    ))}
                </Tabs>
                <DialogFooter>
                    <Button onClick={() => onOpenChange(false)}>Feito</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** O nome, o endereço e as datas de uma landing page. */
export function LandingSettingsDialog({
    open,
    onOpenChange,
    page,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    page: EditablePage;
}) {
    const { data, setData, patch, processing, errors } = useForm({
        name: page.name,
        slug: page.slug ?? '',
        starts_at: page.startsAtInput ?? '',
        ends_at: page.endsAtInput ?? '',
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        patch(definicoes(page.id).url, {
                            preserveScroll: true,
                            onSuccess: () => onOpenChange(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Endereço e datas</DialogTitle>
                        <DialogDescription>
                            Fora das datas a página dá “não encontrada” e sai do
                            sitemap. Sem datas, fica sempre no ar depois de
                            publicada.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor="landing-name">Nome</Label>
                        <Input
                            id="landing-name"
                            value={data.name}
                            maxLength={120}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="landing-slug">Endereço</Label>
                        <div className="flex items-center gap-1">
                            <span className="text-sm text-muted-foreground">
                                /
                            </span>
                            <Input
                                id="landing-slug"
                                value={data.slug}
                                maxLength={80}
                                onChange={(event) =>
                                    setData('slug', event.target.value)
                                }
                            />
                        </div>
                        <InputError message={errors.slug} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="landing-starts">Início</Label>
                            <Input
                                id="landing-starts"
                                type="datetime-local"
                                value={data.starts_at}
                                onChange={(event) =>
                                    setData('starts_at', event.target.value)
                                }
                            />
                            <InputError message={errors.starts_at} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="landing-ends">Fim</Label>
                            <Input
                                id="landing-ends"
                                type="datetime-local"
                                value={data.ends_at}
                                onChange={(event) =>
                                    setData('ends_at', event.target.value)
                                }
                            />
                            <InputError message={errors.ends_at} />
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
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** As últimas publicações, com "Repor esta versão". */
export function HistorySheet({
    open,
    onOpenChange,
    pageId,
    revisions,
    onRestored,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    pageId: number;
    revisions: Revision[];
    onRestored: () => void;
}) {
    const [confirm, setConfirm] = useState<Revision | null>(null);
    const [processing, setProcessing] = useState(false);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="w-full sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Histórico</SheetTitle>
                    <SheetDescription>
                        As últimas 20 publicações. Repor põe essa versão no
                        rascunho; o site só muda quando voltares a publicar.
                    </SheetDescription>
                </SheetHeader>
                <ul className="grid gap-2 overflow-y-auto px-4 pb-4">
                    {revisions.length === 0 && (
                        <li className="text-sm text-muted-foreground">
                            Ainda não foi publicada.
                        </li>
                    )}
                    {revisions.map((revision, index) => (
                        <li
                            key={revision.id}
                            className="flex items-center gap-3 rounded-md border border-border/60 p-3"
                        >
                            <History
                                aria-hidden
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                            <div className="min-w-0 flex-1 text-sm">
                                <p className="font-medium">
                                    {formatDateTime(revision.createdAt)}
                                    {index === 0 && (
                                        <span className="ml-2 text-xs font-normal text-success">
                                            no ar
                                        </span>
                                    )}
                                </p>
                                <p className="text-muted-foreground">
                                    {revision.user ?? '—'} · {revision.blocks}{' '}
                                    {revision.blocks === 1 ? 'bloco' : 'blocos'}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setConfirm(revision)}
                            >
                                <RotateCcw />
                                Repor
                            </Button>
                        </li>
                    ))}
                </ul>
            </SheetContent>
            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(value) => !value && setConfirm(null)}
                title="Repor esta versão?"
                description="O rascunho atual é substituído por esta versão. O que está publicado não muda até publicares."
                confirmLabel="Repor no rascunho"
                processing={processing}
                onConfirm={() => {
                    if (!confirm) {
                        return;
                    }

                    setProcessing(true);
                    router.post(
                        repor([pageId, confirm.id]).url,
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setConfirm(null);
                                onOpenChange(false);
                                onRestored();
                            },
                            onFinish: () => setProcessing(false),
                        },
                    );
                }}
            />
        </Sheet>
    );
}
