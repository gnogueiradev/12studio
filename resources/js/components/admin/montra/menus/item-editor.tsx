import {
    ChevronRight,
    Globe,
    GripVertical,
    Lock,
    Mail,
    MousePointerClick,
    Plus,
    Trash2,
} from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { StoreLink } from '@/types/page-builder';
import {
    filledLocales,
    isComplaintsBook,
    labelOf,
    LOCALE_NAMES,
} from './menu-model';
import type { MenuItem, MenuLimits, MenuLocation } from './menu-model';

/** O que mudou e os erros (do último "Guardar") que isso resolve. */
export type ItemChange = (patch: Partial<MenuItem>, clears: string[]) => void;

type Props = {
    location: MenuLocation;
    item: MenuItem;
    parent: MenuItem | null;
    locales: string[];
    links: StoreLink[];
    limits: MenuLimits;
    errors: Record<string, string>;
    autoFocus: boolean;
    onChange: ItemChange;
    onRemove: () => void;
    onAddChild: () => void;
};

/**
 * O painel do item escolhido: o texto, as traduções e para onde leva.
 * Monta-se de novo a cada item (key = uid), por isso o estado local — as
 * traduções abertas, o "título é um link" — é sempre do item à vista.
 */
export function ItemEditor({
    location,
    item,
    parent,
    locales,
    links,
    limits,
    errors,
    autoFocus,
    onChange,
    onRemove,
    onAddChild,
}: Props) {
    const id = useId();
    const [main, ...others] = locales;
    const isFooter = location === 'footer';
    const isColumn = isFooter && parent === null;
    const [titleIsLink, setTitleIsLink] = useState(item.target !== '');
    const [translationsOpen, setTranslationsOpen] = useState(false);
    const translationError = others.some((locale) => errors[`label.${locale}`]);
    const parentName = parent
        ? labelOf(parent, main) || 'Sem texto'
        : undefined;

    const heading = isColumn
        ? 'Coluna do rodapé'
        : isFooter
          ? `Link da coluna “${parentName}”`
          : parent
            ? `Sub-link de “${parentName}”`
            : 'Item do menu';

    const setLabel = (locale: string, value: string) =>
        onChange({ label: { ...item.label, [locale]: value } }, [
            `label.${locale}`,
        ]);

    return (
        <div className="grid gap-5">
            <div className="flex items-start justify-between gap-3">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {heading}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="-mt-1.5 text-muted-foreground hover:text-destructive"
                    onClick={onRemove}
                >
                    <Trash2 />
                    Apagar
                </Button>
            </div>

            {isComplaintsBook(item) && (
                <p className="flex items-start gap-2 rounded-md bg-info-soft px-3 py-2 text-sm text-info-soft-foreground">
                    <Lock aria-hidden className="mt-0.5 size-4 shrink-0" />
                    Obrigatório para quem vende em Portugal: podes mudar o
                    texto, mas não o tires.
                </p>
            )}

            <div className="grid gap-2">
                <Label htmlFor={`${id}-text`}>
                    {isColumn ? 'Título da coluna' : 'Texto'}
                </Label>
                <Input
                    id={`${id}-text`}
                    value={item.label[main] ?? ''}
                    maxLength={40}
                    autoFocus={autoFocus}
                    placeholder={isColumn ? 'Ex.: Ajuda' : 'Ex.: Catálogo'}
                    aria-invalid={Boolean(errors[`label.${main}`])}
                    className="h-11 text-base"
                    onChange={(event) => setLabel(main, event.target.value)}
                />
                <InputError message={errors[`label.${main}`]} />

                {others.length > 0 && (
                    <Collapsible
                        open={translationsOpen || translationError}
                        onOpenChange={setTranslationsOpen}
                    >
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="-ml-2.5 w-fit text-muted-foreground [&[data-state=open]>svg]:rotate-90"
                            >
                                <ChevronRight className="transition-transform" />
                                Traduções
                                <span className="font-normal">
                                    · {filledLocales(item, others)} de{' '}
                                    {others.length}
                                </span>
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="grid gap-3 pt-1">
                            <p className="text-xs text-muted-foreground">
                                Vazio = mostra o texto em português.
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {others.map((locale) => (
                                    <div key={locale} className="grid gap-1.5">
                                        <Label
                                            htmlFor={`${id}-${locale}`}
                                            className="text-xs"
                                        >
                                            {LOCALE_NAMES[locale] ??
                                                locale.toUpperCase()}
                                        </Label>
                                        <Input
                                            id={`${id}-${locale}`}
                                            value={item.label[locale] ?? ''}
                                            maxLength={40}
                                            placeholder={labelOf(item, main)}
                                            aria-invalid={Boolean(
                                                errors[`label.${locale}`],
                                            )}
                                            onChange={(event) =>
                                                setLabel(
                                                    locale,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={errors[`label.${locale}`]}
                                        />
                                    </div>
                                ))}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                )}
            </div>

            <div className="grid gap-2">
                {isColumn ? (
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={`${id}-is-link`}
                            checked={titleIsLink}
                            onCheckedChange={(checked) => {
                                setTitleIsLink(checked === true);

                                if (checked !== true) {
                                    onChange({ type: 'path', target: '' }, [
                                        'target',
                                    ]);
                                }
                            }}
                        />
                        <Label htmlFor={`${id}-is-link`}>
                            O título é um link
                        </Label>
                    </div>
                ) : (
                    <Label htmlFor={`${id}-target`}>Leva a</Label>
                )}
                {isColumn && !titleIsLink && (
                    <p className="text-xs text-muted-foreground">
                        Se não for, o título aparece só como texto.
                    </p>
                )}
                {(!isColumn || titleIsLink) && (
                    <LinkPicker
                        id={`${id}-target`}
                        item={item}
                        links={links}
                        invalid={Boolean(errors.target)}
                        onChange={(patch) => onChange(patch, ['target'])}
                    />
                )}
                <InputError message={errors.target} />
            </div>

            {parent === null && (
                <div className="grid gap-2 border-t border-border/60 pt-4">
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-sm font-medium">
                            {isFooter ? 'Links da coluna' : 'Submenu'}
                        </p>
                        <span className="text-xs text-muted-foreground">
                            {item.children.length} de {limits.children}
                        </span>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {isFooter
                            ? 'Os links aparecem por baixo do título. Arrasta-os na estrutura para mudar a ordem ou de coluna.'
                            : item.children.length > 0
                              ? 'Este item abre um submenu com os sub-links. Arrasta-os na estrutura para mudar a ordem.'
                              : 'Com sub-links, este item passa a abrir um submenu.'}
                    </p>
                    <InputError message={errors.children} />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-fit"
                        disabled={item.children.length >= limits.children}
                        onClick={onAddChild}
                    >
                        <Plus />
                        {isFooter ? 'Link' : 'Sub-link'}
                    </Button>
                </div>
            )}
        </div>
    );
}

const URL_CHOICE = '__url';
const EMAIL_CHOICE = '__email';
const PATH_PREFIX = 'path:';

function LinkPicker({
    id,
    item,
    links,
    invalid,
    onChange,
}: {
    id: string;
    item: MenuItem;
    links: StoreLink[];
    invalid: boolean;
    onChange: (patch: Partial<MenuItem>) => void;
}) {
    const groups = new Map<string, StoreLink[]>();

    for (const link of links) {
        groups.set(link.group, [...(groups.get(link.group) ?? []), link]);
    }

    const value =
        item.type === 'url'
            ? URL_CHOICE
            : item.type === 'email'
              ? EMAIL_CHOICE
              : item.target
                ? PATH_PREFIX + item.target
                : '';
    // Um caminho que já não está na lista (página apagada, escrito à mão)
    // continua a aparecer — escolher outro é decisão tua, não do editor.
    const unknownPath =
        item.type === 'path' &&
        item.target !== '' &&
        !links.some((link) => link.path === item.target);

    const choose = (choice: string) => {
        if (choice === URL_CHOICE) {
            onChange({
                type: 'url',
                target: item.type === 'url' ? item.target : '',
            });
        } else if (choice === EMAIL_CHOICE) {
            onChange({
                type: 'email',
                target: item.type === 'email' ? item.target : '',
            });
        } else {
            onChange({
                type: 'path',
                target: choice.slice(PATH_PREFIX.length),
            });
        }
    };

    return (
        <div className="grid gap-2">
            <Select value={value} onValueChange={choose}>
                <SelectTrigger
                    id={id}
                    className="w-full"
                    aria-invalid={invalid && item.type === 'path'}
                >
                    <SelectValue placeholder="Escolhe uma página…" />
                </SelectTrigger>
                <SelectContent>
                    {unknownPath && (
                        <SelectGroup>
                            <SelectLabel>Endereço atual</SelectLabel>
                            <SelectItem value={PATH_PREFIX + item.target}>
                                {item.target}
                            </SelectItem>
                        </SelectGroup>
                    )}
                    {[...groups].map(([group, list]) => (
                        <SelectGroup key={group}>
                            <SelectLabel>{group}</SelectLabel>
                            {list.map((link) => (
                                <SelectItem
                                    key={`${group}:${link.path}`}
                                    value={PATH_PREFIX + link.path}
                                >
                                    {link.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    ))}
                    <SelectSeparator />
                    <SelectGroup>
                        <SelectLabel>Outros</SelectLabel>
                        <SelectItem value={URL_CHOICE}>
                            <Globe />
                            Endereço externo…
                        </SelectItem>
                        <SelectItem value={EMAIL_CHOICE}>
                            <Mail />
                            Email…
                        </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>
            {item.type === 'url' && (
                <Input
                    type="url"
                    inputMode="url"
                    value={item.target}
                    placeholder="https://…"
                    aria-label="Endereço externo"
                    aria-invalid={invalid}
                    onChange={(event) =>
                        onChange({ type: 'url', target: event.target.value })
                    }
                />
            )}
            {item.type === 'email' && (
                <Input
                    type="email"
                    value={item.target}
                    placeholder="ola@aminhaloja.pt"
                    aria-label="Endereço de email"
                    aria-invalid={invalid}
                    onChange={(event) =>
                        onChange({ type: 'email', target: event.target.value })
                    }
                />
            )}
        </div>
    );
}

/** O painel quando ainda não escolheste nada. */
export function NoItemSelected({ location }: { location: MenuLocation }) {
    const isFooter = location === 'footer';

    return (
        <div className="grid justify-items-center gap-4 px-4 py-10 text-center">
            <span className="grid size-11 place-items-center rounded-full bg-muted text-muted-foreground">
                <MousePointerClick className="size-5" />
            </span>
            <div className="grid gap-1">
                <p className="font-medium">Escolhe o que queres mudar</p>
                <p className="text-sm text-muted-foreground">
                    Clica num {isFooter ? 'título ou link' : 'item'} na
                    pré-visualização ou na estrutura.
                </p>
            </div>
            <ul className="grid gap-2 text-left text-sm text-muted-foreground">
                <li className="flex items-center gap-2">
                    <GripVertical className="size-4 shrink-0" />
                    Arrasta pela pega para mudar a ordem
                    {isFooter ? ' ou passar um link para outra coluna' : ''}.
                </li>
                <li className="flex items-center gap-2">
                    <Plus className="size-4 shrink-0" />
                    {isFooter
                        ? 'Usa “+ Coluna” ou “+ Link” para acrescentar.'
                        : 'Usa “+ Item” ou “+ Sub-link” para acrescentar.'}
                </li>
            </ul>
        </div>
    );
}
