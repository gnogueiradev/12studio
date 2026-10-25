import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { BlockIcon } from '@/blocks/icons';
import { RichTextEditor } from '@/components/admin/rich-text-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type {
    FieldSchema,
    LinkValue,
    ProductOption,
    StoreLink,
} from '@/types/page-builder';
import { emptyValue } from './blocks';
import { ImageField } from './media-picker';
import { ProductPicker } from './product-picker';

export type FieldContext = {
    links: StoreLink[];
    categories: { value: number; label: string }[];
    tags: { value: number; label: string }[];
    products: Record<number, ProductOption>;
    onKnownProduct: (product: ProductOption) => void;
    mediaSrc: Record<number, string>;
    onMediaSrc: (id: number, src: string) => void;
    /** Erros do servidor, pelo caminho do campo ("blocks.2.content.pt.title"). */
    errors: Record<string, string>;
};

type Props = {
    field: FieldSchema;
    value: unknown;
    onChange: (value: unknown) => void;
    /** Prefixo único para os ids dos inputs (bloco + idioma). */
    idPrefix: string;
    /** O caminho do campo para encontrar o erro. */
    path: string;
    context: FieldContext;
};

/**
 * Um campo do inspetor, gerado a partir do esquema do bloco. Um tipo de
 * campo novo no servidor precisa de um caso aqui — o resto do editor não
 * muda.
 */
export function FieldInput({
    field,
    value,
    onChange,
    idPrefix,
    path,
    context,
}: Props) {
    const id = `${idPrefix}-${field.key}`;
    const error = context.errors[path];

    if (field.type === 'toggle') {
        return (
            <div className="grid gap-1">
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox
                        id={id}
                        checked={value === true}
                        onCheckedChange={(checked) =>
                            onChange(checked === true)
                        }
                    />
                    {field.label}
                </Label>
                {field.help && (
                    <p className="text-xs text-muted-foreground">
                        {field.help}
                    </p>
                )}
                <InputError message={error} />
            </div>
        );
    }

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>
                {field.label}
                {field.required && (
                    <span className="text-muted-foreground"> *</span>
                )}
            </Label>
            <Control
                field={field}
                value={value}
                onChange={onChange}
                id={id}
                path={path}
                context={context}
            />
            {field.help && (
                <p className="text-xs text-muted-foreground">{field.help}</p>
            )}
            <InputError message={error} />
            {field.type === 'link' && (
                <InputError message={context.errors[`${path}.href`]} />
            )}
            {field.type === 'link' && (
                <InputError message={context.errors[`${path}.label`]} />
            )}
        </div>
    );
}

function Control({
    field,
    value,
    onChange,
    id,
    path,
    context,
}: {
    field: FieldSchema;
    value: unknown;
    onChange: (value: unknown) => void;
    id: string;
    path: string;
    context: FieldContext;
}) {
    switch (field.type) {
        case 'text':
            return (
                <Input
                    id={id}
                    value={typeof value === 'string' ? value : ''}
                    maxLength={field.max}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
        case 'textarea':
            return (
                <Textarea
                    id={id}
                    value={typeof value === 'string' ? value : ''}
                    maxLength={field.max}
                    rows={3}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
        case 'richtext':
            return (
                <RichTextEditor
                    id={id}
                    value={typeof value === 'string' ? value : ''}
                    onChange={onChange}
                />
            );
        case 'number':
            return (
                <Input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    min={field.min}
                    max={field.max}
                    value={typeof value === 'number' ? value : ''}
                    onChange={(event) =>
                        onChange(
                            event.target.value === ''
                                ? null
                                : Number(event.target.value),
                        )
                    }
                />
            );
        case 'select':
            return (
                <Select
                    value={String(value ?? field.default ?? '')}
                    onValueChange={onChange}
                >
                    <SelectTrigger id={id}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {field.options?.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            );
        case 'image': {
            const imageId = typeof value === 'number' ? value : null;

            return (
                <ImageField
                    id={id}
                    value={imageId}
                    preview={
                        imageId !== null
                            ? (context.mediaSrc[imageId] ?? null)
                            : null
                    }
                    onChange={(next, src) => {
                        if (next !== null && src) {
                            context.onMediaSrc(next, src);
                        }

                        onChange(next);
                    }}
                />
            );
        }
        case 'link':
            return (
                <LinkInput
                    id={id}
                    value={(value as LinkValue) ?? null}
                    links={context.links}
                    onChange={onChange}
                />
            );
        case 'icon':
            return (
                <div
                    role="radiogroup"
                    aria-labelledby={id}
                    className="flex flex-wrap gap-1"
                >
                    {(field.icons ?? []).map((icon) => (
                        <button
                            key={icon}
                            type="button"
                            role="radio"
                            aria-checked={value === icon}
                            aria-label={icon}
                            onClick={() => onChange(icon)}
                            className={cn(
                                'flex size-8 items-center justify-center rounded-md border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                value === icon
                                    ? 'border-foreground bg-muted'
                                    : 'border-border/60 hover:bg-muted',
                            )}
                        >
                            <BlockIcon name={icon} className="size-4" />
                        </button>
                    ))}
                </div>
            );
        case 'products':
            return (
                <ProductPicker
                    id={id}
                    value={Array.isArray(value) ? (value as number[]) : []}
                    max={field.max}
                    known={context.products}
                    onKnown={context.onKnownProduct}
                    onChange={onChange}
                />
            );
        case 'categories':
        case 'tags':
            return (
                <ChoiceList
                    id={id}
                    options={
                        field.type === 'tags'
                            ? context.tags
                            : context.categories
                    }
                    value={Array.isArray(value) ? (value as number[]) : []}
                    max={field.max}
                    onChange={onChange}
                />
            );
        case 'repeater':
            return (
                <Repeater
                    field={field}
                    value={
                        Array.isArray(value)
                            ? (value as Record<string, unknown>[])
                            : []
                    }
                    onChange={onChange}
                    idPrefix={id}
                    path={path}
                    context={context}
                />
            );
        default:
            return null;
    }
}

/** Texto do botão e destino, com sugestões das páginas da loja. */
function LinkInput({
    id,
    value,
    links,
    onChange,
}: {
    id: string;
    value: LinkValue;
    links: StoreLink[];
    onChange: (value: LinkValue) => void;
}) {
    const label = value?.label ?? '';
    const href = value?.href ?? '';
    const update = (next: { label?: string; href?: string }) => {
        const merged = { label, href, ...next };
        onChange(merged.label === '' && merged.href === '' ? null : merged);
    };

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <Input
                id={id}
                value={label}
                maxLength={60}
                placeholder="Texto do botão"
                aria-label="Texto do botão"
                onChange={(event) => update({ label: event.target.value })}
            />
            <Input
                value={href}
                list={`${id}-links`}
                placeholder="/produtos ou https://…"
                aria-label="Destino do botão"
                onChange={(event) => update({ href: event.target.value })}
            />
            <datalist id={`${id}-links`}>
                {links.map((link) => (
                    <option key={link.path} value={link.path}>
                        {`${link.group}: ${link.label}`}
                    </option>
                ))}
            </datalist>
        </div>
    );
}

function ChoiceList({
    id,
    options,
    value,
    max,
    onChange,
}: {
    id: string;
    options: { value: number; label: string }[];
    value: number[];
    max?: number;
    onChange: (value: number[]) => void;
}) {
    if (options.length === 0) {
        return (
            <p id={id} className="text-sm text-muted-foreground">
                Ainda não há nenhuma para escolher.
            </p>
        );
    }

    return (
        <div
            id={id}
            className="grid max-h-48 gap-1.5 overflow-y-auto rounded-md border border-border/60 p-2"
        >
            {options.map((option) => {
                const checked = value.includes(option.value);

                return (
                    <Label
                        key={option.value}
                        className="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            checked={checked}
                            disabled={
                                !checked &&
                                max !== undefined &&
                                max > 1 &&
                                value.length >= max
                            }
                            onCheckedChange={(on) => {
                                if (max === 1) {
                                    onChange(on === true ? [option.value] : []);

                                    return;
                                }

                                onChange(
                                    on === true
                                        ? [...value, option.value]
                                        : value.filter(
                                              (item) => item !== option.value,
                                          ),
                                );
                            }}
                        />
                        {option.label}
                    </Label>
                );
            })}
        </div>
    );
}

/** Uma lista de itens com os mesmos campos (passos, perguntas, fotos). */
function Repeater({
    field,
    value,
    onChange,
    idPrefix,
    path,
    context,
}: {
    field: FieldSchema;
    value: Record<string, unknown>[];
    onChange: (value: Record<string, unknown>[]) => void;
    idPrefix: string;
    path: string;
    context: FieldContext;
}) {
    const fields = field.fields ?? [];
    const max = field.max ?? 12;

    const move = (from: number, to: number) => {
        const next = [...value];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        onChange(next);
    };

    return (
        <div className="grid gap-2">
            {value.map((item, index) => (
                <fieldset
                    key={index}
                    className="grid gap-3 rounded-md border border-border/60 p-3"
                >
                    <div className="flex items-center gap-1">
                        <legend className="flex-1 text-xs font-medium text-muted-foreground">
                            {index + 1}.
                        </legend>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label="Subir"
                            disabled={index === 0}
                            onClick={() => move(index, index - 1)}
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label="Descer"
                            disabled={index === value.length - 1}
                            onClick={() => move(index, index + 1)}
                        >
                            <ArrowDown />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label="Apagar item"
                            onClick={() =>
                                onChange(value.filter((_, i) => i !== index))
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                    {fields.map((sub) => (
                        <FieldInput
                            key={sub.key}
                            field={sub}
                            value={item[sub.key]}
                            idPrefix={`${idPrefix}-${index}`}
                            path={`${path}.${index}.${sub.key}`}
                            context={context}
                            onChange={(next) =>
                                onChange(
                                    value.map((current, i) =>
                                        i === index
                                            ? { ...current, [sub.key]: next }
                                            : current,
                                    ),
                                )
                            }
                        />
                    ))}
                </fieldset>
            ))}
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={value.length >= max}
                onClick={() =>
                    onChange([
                        ...value,
                        Object.fromEntries(
                            fields.map((sub) => [sub.key, emptyValue(sub)]),
                        ),
                    ])
                }
            >
                <Plus />
                Juntar
            </Button>
        </div>
    );
}
