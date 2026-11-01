import { X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { StylePropSchema } from '@/types/page-builder';
import type { StoreDeviceValues } from '@/types/store';
import type { FieldContext } from '../field-input';
import { ImageField } from '../media-picker';
import { DEVICES } from '../preview-frame';
import type { Device } from '../preview-frame';
import {
    clearDevice,
    clearHide,
    DEVICE_NAMES,
    readDevice,
    writeDevice,
    writeHide,
} from './device-values';

type Props = {
    prop: StylePropSchema;
    values: StoreDeviceValues | undefined;
    device: Device;
    /** Os valores de todos os dispositivos, já com este alterado. */
    onChange: (values: StoreDeviceValues) => void;
    idPrefix: string;
    /** O caminho do erro, sem o dispositivo (`node:<id>.style.gap`). */
    path: string;
    context: FieldContext;
    /** Só há um valor para todos os tamanhos: grava-se sempre no computador. */
    single?: boolean;
    /**
     * O que a montra faz neste dispositivo enquanto ele não tiver valor, quando
     * não é herdar do tamanho acima (uma linha empilha no telemóvel).
     */
    unset?: { value: unknown; hint: string };
};

/**
 * Um controlo de estilo. Escreve em `valores[dispositivo]`; sem valor próprio
 * mostra, esbatido, o que a página está de facto a usar.
 */
export function StyleControl({
    prop,
    values,
    device,
    onChange,
    idPrefix,
    path,
    context,
    single = false,
    unset,
}: Props) {
    const editing: Device = single ? 'desktop' : device;
    const { own, inherited, from } = readDevice(values, editing);
    const hasOwn = own !== undefined;
    const current = own ?? unset?.value ?? inherited ?? prop.default;
    const hint = hasOwn
        ? null
        : (unset?.hint ?? (from ? `herdado do ${DEVICE_NAMES[from]}` : null));
    const id = `${idPrefix}-${prop.key}`;
    const error =
        context.errors[`${path}.${editing}`] ??
        context.errors[`${path}.desktop`] ??
        context.errors[`${path}.tablet`] ??
        context.errors[`${path}.mobile`] ??
        context.errors[path];
    const Icon = DEVICES[editing].icon;
    const write = (value: unknown) =>
        onChange(writeDevice(values, editing, value));

    const heading = (
        <>
            <Label htmlFor={id} className="min-w-0 font-normal">
                {prop.label}
            </Label>
            {!single && (
                <Icon
                    aria-label={DEVICES[editing].label}
                    className="size-3.5 shrink-0 text-muted-foreground"
                />
            )}
            <span className="flex-1" />
            {hasOwn && (
                <button
                    type="button"
                    aria-label={
                        single
                            ? `Limpar ${prop.label}`
                            : `Repor ${prop.label} no ${DEVICE_NAMES[editing]}`
                    }
                    title="Repor"
                    className="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    onClick={() =>
                        onChange(
                            prop.key === 'hide'
                                ? clearHide(values, editing)
                                : clearDevice(values, editing),
                        )
                    }
                >
                    <X aria-hidden className="size-3.5" />
                </button>
            )}
        </>
    );

    const footer = (
        <>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </>
    );

    if (prop.type === 'toggle') {
        return (
            <div className="grid gap-1">
                <div className="flex items-center gap-1.5">
                    {heading}
                    <Switch
                        id={id}
                        checked={current === true}
                        muted={!hasOwn}
                        onChange={(checked) =>
                            prop.key === 'hide'
                                ? onChange(writeHide(values, editing, checked))
                                : write(checked)
                        }
                    />
                </div>
                {footer}
            </div>
        );
    }

    return (
        <div className="grid gap-1.5">
            <div className="flex min-h-5 items-center gap-1.5">{heading}</div>
            {(prop.type === 'enum' || prop.type === 'token') && (
                <Select
                    value={current === undefined ? '' : String(current)}
                    onValueChange={write}
                >
                    <SelectTrigger
                        id={id}
                        size="sm"
                        className={cn(
                            'w-full',
                            !hasOwn && 'text-muted-foreground',
                        )}
                    >
                        <SelectValue placeholder="Predefinido" />
                    </SelectTrigger>
                    <SelectContent>
                        {prop.options?.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
            {prop.type === 'int' && (
                <IntControl
                    id={id}
                    prop={prop}
                    value={typeof current === 'number' ? current : null}
                    muted={!hasOwn}
                    onChange={write}
                />
            )}
            {prop.type === 'media' && (
                <ImageField
                    id={id}
                    value={typeof current === 'number' ? current : null}
                    preview={
                        typeof current === 'number'
                            ? (context.mediaSrc[current] ?? null)
                            : null
                    }
                    onChange={(next, src) => {
                        if (next === null) {
                            onChange(clearDevice(values, editing));

                            return;
                        }

                        if (src) {
                            context.onMediaSrc(next, src);
                        }

                        write(next);
                    }}
                />
            )}
            {footer}
        </div>
    );
}

/** Slider e número: o número só grava o que o servidor aceita. */
function IntControl({
    id,
    prop,
    value,
    muted,
    onChange,
}: {
    id: string;
    prop: StylePropSchema;
    value: number | null;
    muted: boolean;
    onChange: (value: number) => void;
}) {
    const min = prop.min ?? 0;
    const max = prop.max ?? 100;
    const [draft, setDraft] = useState<string | null>(null);

    return (
        <div className="flex items-center gap-2">
            <input
                type="range"
                aria-label={prop.label}
                min={min}
                max={max}
                value={value ?? min}
                onChange={(event) => onChange(Number(event.target.value))}
                className={cn(
                    'h-1.5 min-w-0 flex-1 cursor-pointer accent-primary',
                    muted && 'opacity-50',
                )}
            />
            <Input
                id={id}
                type="number"
                inputMode="numeric"
                min={min}
                max={max}
                placeholder="—"
                value={draft ?? (value === null ? '' : String(value))}
                onChange={(event) => {
                    const text = event.target.value;
                    const number = Number(text);
                    setDraft(text);

                    if (
                        text !== '' &&
                        Number.isInteger(number) &&
                        number >= min &&
                        number <= max
                    ) {
                        onChange(number);
                    }
                }}
                onBlur={() => {
                    const number = Math.round(Number(draft));
                    const clamped = Math.min(max, Math.max(min, number));

                    if (draft && Number.isFinite(number) && clamped !== value) {
                        onChange(clamped);
                    }

                    setDraft(null);
                }}
                className={cn(
                    'h-8 w-16 shrink-0 px-2 text-sm tabular-nums',
                    muted && 'text-muted-foreground',
                )}
            />
            <span className="w-4 shrink-0 text-xs text-muted-foreground">
                {prop.unit}
            </span>
        </div>
    );
}

/** O interruptor: não há um nos componentes de base. */
export function Switch({
    id,
    checked,
    muted = false,
    onChange,
}: {
    id?: string;
    checked: boolean;
    muted?: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <button
            id={id}
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className={cn(
                'inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                checked ? 'bg-primary' : 'bg-input',
                muted && 'opacity-60',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'size-4 rounded-full bg-background shadow-sm transition-transform',
                    checked ? 'translate-x-[18px]' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}
