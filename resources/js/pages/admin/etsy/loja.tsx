import { Head, router, useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { EtsyNav } from '@/components/admin/etsy/etsy-nav';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Textarea } from '@/components/ui/textarea';
import { categorias, index, textos } from '@/routes/admin/etsy/loja';
import {
    apagar as deleteSection,
    guardar as saveSection,
} from '@/routes/admin/etsy/loja/seccoes';
import { etsyRefused } from '@/types/etsy';

type ShopTexts = {
    title: string;
    announcement: string;
    saleMessage: string;
    digitalSaleMessage: string;
    policyAdditional: string;
};

type Section = {
    id: number;
    title: string;
    activeListings: number | null;
};

type CategoryRow = {
    id: number;
    name: string;
    /** A secção da Etsy para onde vão os anúncios desta categoria. */
    sectionId: number | null;
};

type Props = {
    connected: boolean;
    /** O que a Etsy respondeu quando não deixou ler a loja. */
    error: string | null;
    shopName: string | null;
    shop: ShopTexts | null;
    sections: Section[];
    categories: CategoryRow[];
};

/** O valor que o Select usa para "sem secção" (o Radix não aceita ''). */
const NONE = 'none';

/**
 * Etsy → Loja: o que os compradores leem quando entram na loja, e como os
 * anúncios se arrumam lá dentro.
 */
export default function EtsyShop({
    connected,
    error,
    shopName,
    shop,
    sections,
    categories,
}: Props) {
    return (
        <>
            <Head title="Loja na Etsy" />
            <div className="flex h-full w-full max-w-[1100px] flex-1 flex-col p-6 pb-10">
                <PageHeader
                    title={
                        shopName ? `Loja na Etsy — ${shopName}` : 'Loja na Etsy'
                    }
                    description="Os textos que os compradores leem na loja, e as secções onde os anúncios se arrumam."
                />
                <EtsyNav current="loja" />

                {!connected && (
                    <Alert className="mt-5 max-w-3xl">
                        <ShieldAlert />
                        <AlertTitle>Ainda não há loja ligada</AlertTitle>
                        <AlertDescription>
                            Liga a loja em Definições → Etsy para a gerir daqui.
                        </AlertDescription>
                    </Alert>
                )}

                {error !== null && (
                    <Alert variant="destructive" className="mt-5 max-w-3xl">
                        <ShieldAlert />
                        <AlertTitle>Não foi possível ler a loja</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {shop !== null && (
                    <div className="mt-6 grid max-w-3xl gap-6">
                        <TextsForm
                            // Remonta com o que a Etsy devolveu depois de gravar.
                            key={JSON.stringify(shop)}
                            shop={shop}
                        />
                        <Sections sections={sections} />
                        <CategoryMap
                            key={JSON.stringify([
                                categories,
                                sections.map((section) => section.id),
                            ])}
                            categories={categories}
                            sections={sections}
                        />
                    </div>
                )}
            </div>
        </>
    );
}

/**
 * Os textos públicos. Antes de gravar pergunta-se: é a montra, e o que aqui
 * se escreve fica à vista de toda a gente no instante seguinte.
 */
function TextsForm({ shop }: { shop: ShopTexts }) {
    const [confirming, setConfirming] = useState(false);
    const { data, setData, patch, processing, errors, isDirty } = useForm({
        title: shop.title,
        announcement: shop.announcement,
        sale_message: shop.saleMessage,
        digital_sale_message: shop.digitalSaleMessage,
        policy_additional: shop.policyAdditional,
    });

    const save = () =>
        patch(textos().url, {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
        });

    return (
        <Panel
            title="Textos da loja"
            description="O que aparece na página da loja e nas mensagens que a Etsy manda aos compradores."
        >
            <form
                className="flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    setConfirming(true);
                }}
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="shop-title">Título</Label>
                    <Input
                        id="shop-title"
                        value={data.title}
                        maxLength={55}
                        onChange={(event) =>
                            setData('title', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        A linha por baixo do nome da loja. Até 55 caracteres.
                    </p>
                    <InputError message={errors.title} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="shop-announcement">Anúncio</Label>
                    <Textarea
                        id="shop-announcement"
                        rows={3}
                        value={data.announcement}
                        onChange={(event) =>
                            setData('announcement', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        O aviso no topo da loja: férias, prazos, novidades.
                    </p>
                    <InputError message={errors.announcement} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="shop-sale-message">
                        Mensagem depois da compra
                    </Label>
                    <Textarea
                        id="shop-sale-message"
                        rows={3}
                        value={data.sale_message}
                        onChange={(event) =>
                            setData('sale_message', event.target.value)
                        }
                    />
                    <InputError message={errors.sale_message} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="shop-policy">Política adicional</Label>
                    <Textarea
                        id="shop-policy"
                        rows={3}
                        value={data.policy_additional}
                        onChange={(event) =>
                            setData('policy_additional', event.target.value)
                        }
                    />
                    <InputError message={errors.policy_additional} />
                </div>

                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={processing || !isDirty}>
                        {processing && <Spinner />}
                        Guardar na Etsy
                    </Button>
                    {isDirty && (
                        <span className="text-sm text-warning">
                            Alterações por guardar.
                        </span>
                    )}
                </div>
            </form>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title="Mudar os textos da loja"
                description="É a montra: o que gravares fica à vista dos compradores na Etsy assim que carregares."
                confirmLabel="Guardar na Etsy"
                processing={processing}
                onConfirm={save}
            />
        </Panel>
    );
}

function Sections({ sections }: { sections: Section[] }) {
    /** `null` fechado; `'new'` a criar; uma secção a renomear. */
    const [editing, setEditing] = useState<Section | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Section | null>(null);
    const [busy, setBusy] = useState(false);

    return (
        <Panel
            title="Secções"
            description="As divisões da loja. Um anúncio está numa secção, ou em nenhuma."
            aside={
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing('new')}
                >
                    Nova secção
                </Button>
            }
        >
            {sections.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    A loja ainda não tem secções.
                </p>
            ) : (
                <ul className="rounded-lg border border-border/60">
                    {sections.map((section) => (
                        <li
                            key={section.id}
                            className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border/60 px-4 py-2.5 text-sm last:border-b-0"
                        >
                            <span className="min-w-0 flex-1 font-medium">
                                {section.title}
                            </span>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {section.activeListings === null
                                    ? ''
                                    : section.activeListings === 1
                                      ? '1 anúncio ativo'
                                      : `${section.activeListings} anúncios ativos`}
                            </span>
                            <span className="flex flex-none gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setEditing(section)}
                                >
                                    Renomear
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Apagar a secção ${section.title}`}
                                    onClick={() => setDeleting(section)}
                                >
                                    Apagar
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {editing !== null && (
                <SectionDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    editing={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Apagar a secção “${deleting?.title ?? ''}”`}
                description="Os anúncios que lá estão ficam sem secção — continuam à venda. Na Etsy a secção não se recupera: só voltando a criar."
                confirmLabel="Apagar da Etsy"
                destructive
                processing={busy}
                onConfirm={() => {
                    if (deleting === null) {
                        return;
                    }

                    setBusy(true);
                    router.delete(deleteSection(deleting.id).url, {
                        preserveScroll: true,
                        onFinish: () => {
                            setBusy(false);
                            setDeleting(null);
                        },
                    });
                }}
            />
        </Panel>
    );
}

function SectionDialog({
    editing,
    onClose,
}: {
    editing: Section | null;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        section_id: editing?.id ?? null,
        title: editing?.title ?? '',
    });

    const submit = () =>
        post(saveSection().url, {
            preserveScroll: true,
            onSuccess: (page) => !etsyRefused(page) && onClose(),
        });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {editing ? 'Renomear secção' : 'Nova secção'}
                    </DialogTitle>
                    <DialogDescription>
                        O nome que os compradores veem no menu da loja. Até 24
                        caracteres.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-1.5">
                    <Label htmlFor="section-title">Título</Label>
                    <Input
                        id="section-title"
                        value={data.title}
                        maxLength={24}
                        autoFocus
                        onChange={(event) =>
                            setData('title', event.target.value)
                        }
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' && data.title.trim()) {
                                event.preventDefault();
                                submit();
                            }
                        }}
                    />
                    <InputError message={errors.title} />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={processing || data.title.trim() === ''}
                    >
                        {processing && <Spinner />}
                        Guardar na Etsy
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A que secção vai cada categoria do 12studio. É uma escolha nossa: fica
 * guardada cá e só se nota quando um anúncio for enviado.
 */
function CategoryMap({
    categories,
    sections,
}: {
    categories: CategoryRow[];
    sections: Section[];
}) {
    const { data, setData, patch, processing, isDirty } = useForm<{
        sections: Record<number, number | null>;
    }>({
        sections: Object.fromEntries(
            categories.map((category) => [category.id, category.sectionId]),
        ),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(categorias().url, { preserveScroll: true });
    };

    return (
        <Panel
            title="Categorias → secções"
            description="Para onde vão os anúncios de cada categoria do 12studio quando são enviados. Cada produto pode escolher outra no seu painel da Etsy. Fica guardado aqui; não muda nada na Etsy."
        >
            {categories.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Ainda não há categorias no 12studio.
                </p>
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <ul className="grid gap-2">
                        {categories.map((category) => {
                            const chosen = data.sections[category.id] ?? null;
                            // Uma secção apagada na Etsy mostra-se como "sem secção".
                            const known =
                                chosen !== null &&
                                sections.some(
                                    (section) => section.id === chosen,
                                );

                            return (
                                <li
                                    key={category.id}
                                    className="grid items-center gap-2 sm:grid-cols-[1fr_16rem]"
                                >
                                    <label
                                        htmlFor={`category-${category.id}`}
                                        className="text-sm"
                                    >
                                        {category.name}
                                    </label>
                                    <Select
                                        value={known ? String(chosen) : NONE}
                                        onValueChange={(value) =>
                                            setData('sections', {
                                                ...data.sections,
                                                [category.id]:
                                                    value === NONE
                                                        ? null
                                                        : Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id={`category-${category.id}`}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                Sem secção
                                            </SelectItem>
                                            {sections.map((section) => (
                                                <SelectItem
                                                    key={section.id}
                                                    value={String(section.id)}
                                                >
                                                    {section.title}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </li>
                            );
                        })}
                    </ul>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing || !isDirty}>
                            {processing && <Spinner />}
                            Guardar
                        </Button>
                        {isDirty && (
                            <span className="text-sm text-warning">
                                Alterações por guardar.
                            </span>
                        )}
                    </div>
                </form>
            )}
        </Panel>
    );
}

EtsyShop.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Etsy', href: index() },
        { title: 'Loja', href: index() },
    ],
};
