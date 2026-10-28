import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { ImageField } from '@/components/admin/montra/media-picker';
import { MontraNav } from '@/components/admin/montra/montra-nav';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { AA_LARGE, AA_TEXT, contrastRatio } from '@/lib/contrast';
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { preview, reset, update } from '@/routes/admin/montra/tema';
import type { PreviewMessage } from '@/types/page-builder';

type Mode = 'light' | 'dark';
type ColorKey = 'background' | 'foreground' | 'primary' | 'accent' | 'gold';
type Colors = Record<ColorKey, string>;

type ThemeForm = {
    light: Colors;
    dark: Colors;
    radius: string;
    body_font: string;
    heading_font: string;
    logo: number | null;
    favicon: number | null;
};

type Props = {
    theme: ThemeForm & { logo_url: string | null; favicon_url: string | null };
    defaults: Omit<ThemeForm, 'logo' | 'favicon'>;
    fonts: { value: string; label: string; headingOnly: boolean }[];
    radii: string[];
    previewUrl: string;
};

const COLOR_LABELS: Record<ColorKey, { label: string; help: string }> = {
    background: { label: 'Fundo', help: 'O fundo das páginas.' },
    foreground: { label: 'Texto', help: 'Títulos e texto corrido.' },
    primary: { label: 'Principal', help: 'Botões principais e links.' },
    accent: { label: 'Acento', help: 'Faixas e fundos suaves.' },
    gold: { label: 'Detalhe', help: 'Promoções, selos e foco.' },
};

const RADIUS_LABELS: Record<string, string> = {
    sharp: 'Retos',
    soft: 'Suaves',
    round: 'Redondos',
};

/**
 * Montra → Tema (só o dono). As cores, as letras, os cantos, o logotipo e o
 * favicon, com a montra a mudar ao lado antes de gravar. Uma combinação com
 * contraste abaixo de 3:1 não se grava.
 */
export default function ThemePage({
    theme,
    defaults,
    fonts,
    radii,
    previewUrl,
}: Props) {
    const { logo_url: logoUrl, favicon_url: faviconUrl, ...initial } = theme;
    const { data, setData, put, processing, errors } =
        useForm<ThemeForm>(initial);
    const [mode, setMode] = useState<Mode>('light');
    const [previewMode, setPreviewMode] = useState<Mode>('light');
    const [logoSrc, setLogoSrc] = useState(logoUrl);
    const [faviconSrc, setFaviconSrc] = useState(faviconUrl);
    const [confirmReset, setConfirmReset] = useState(false);
    const frame = useRef<HTMLIFrameElement>(null);
    const fieldErrors = errors as Record<string, string | undefined>;

    // A montra ao lado com o tema por gravar.
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            requestJson<{ css: string; fonts: string }>(
                preview().url,
                'POST',
                data,
                controller.signal,
            )
                .then((response) =>
                    frame.current?.contentWindow?.postMessage(
                        {
                            source: 'studio-editor',
                            kind: 'theme',
                            css: response.css,
                            fonts: response.fonts,
                            mode: previewMode,
                        } satisfies PreviewMessage,
                        window.location.origin,
                    ),
                )
                .catch(() => undefined);
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [data, previewMode]);

    const setColor = (target: Mode, key: ColorKey, value: string) =>
        setData((current) => ({
            ...current,
            [target]: { ...current[target], [key]: value },
        }));

    const checks = (target: Mode) => {
        const colors = data[target];
        const valid = (hex: string) => /^#[0-9a-f]{6}$/i.test(hex);

        if (
            !valid(colors.foreground) ||
            !valid(colors.background) ||
            !valid(colors.primary)
        ) {
            return [];
        }

        return [
            {
                label: 'Texto sobre o fundo',
                ratio: contrastRatio(colors.foreground, colors.background),
                need: AA_TEXT,
            },
            {
                label: 'Botão principal sobre o fundo',
                ratio: contrastRatio(colors.primary, colors.background),
                need: AA_LARGE,
            },
        ];
    };

    return (
        <>
            <Head title="Tema da montra" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Montra"
                    description="O aspeto do site público. O backoffice não muda."
                >
                    <Button
                        variant="outline"
                        onClick={() => setConfirmReset(true)}
                    >
                        <RotateCcw />
                        Repor tema original
                    </Button>
                </PageHeader>
                <MontraNav current="tema" />

                <div className="grid gap-4 xl:grid-cols-[minmax(0,460px)_1fr]">
                    <form
                        className="flex flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            put(update().url, { preserveScroll: true });
                        }}
                    >
                        <Panel
                            title="Cores"
                            description="Cinco cores por modo; os outros tons (cartões, bordas, texto secundário) saem destas."
                        >
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                size="sm"
                                value={mode}
                                onValueChange={(value) =>
                                    value && setMode(value as Mode)
                                }
                                className="mb-4"
                            >
                                <ToggleGroupItem value="light">
                                    Modo claro
                                </ToggleGroupItem>
                                <ToggleGroupItem value="dark">
                                    Modo escuro
                                </ToggleGroupItem>
                            </ToggleGroup>
                            <div className="grid gap-3">
                                {(Object.keys(COLOR_LABELS) as ColorKey[]).map(
                                    (key) => (
                                        <div
                                            key={key}
                                            className="grid grid-cols-[auto_1fr_7rem] items-center gap-3"
                                        >
                                            <input
                                                type="color"
                                                value={data[mode][key]}
                                                onChange={(event) =>
                                                    setColor(
                                                        mode,
                                                        key,
                                                        event.target.value,
                                                    )
                                                }
                                                aria-label={`${COLOR_LABELS[key].label} (${mode === 'light' ? 'claro' : 'escuro'})`}
                                                className="size-9 cursor-pointer rounded-md border border-border bg-transparent p-0.5"
                                            />
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {COLOR_LABELS[key].label}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {COLOR_LABELS[key].help}
                                                </p>
                                                <InputError
                                                    message={
                                                        fieldErrors[
                                                            `${mode}.${key}`
                                                        ]
                                                    }
                                                />
                                            </div>
                                            <Input
                                                value={data[mode][key]}
                                                maxLength={7}
                                                aria-label={`Código da cor ${COLOR_LABELS[key].label}`}
                                                className="font-mono text-sm"
                                                onChange={(event) =>
                                                    setColor(
                                                        mode,
                                                        key,
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    ),
                                )}
                            </div>
                            <ul className="mt-4 grid gap-1.5">
                                {checks(mode).map((check) => {
                                    const blocked = check.ratio < AA_LARGE;
                                    const ok = check.ratio >= check.need;

                                    return (
                                        <li
                                            key={check.label}
                                            className={cn(
                                                'flex items-center gap-2 text-sm',
                                                ok
                                                    ? 'text-success'
                                                    : blocked
                                                      ? 'text-destructive'
                                                      : 'text-warning',
                                            )}
                                        >
                                            {ok ? (
                                                <CheckCircle2
                                                    aria-hidden
                                                    className="size-4"
                                                />
                                            ) : (
                                                <AlertTriangle
                                                    aria-hidden
                                                    className="size-4"
                                                />
                                            )}
                                            {check.label}:{' '}
                                            {check.ratio.toFixed(1)}:1
                                            {!ok &&
                                                (blocked
                                                    ? ' — ilegível, não se pode gravar'
                                                    : ' — fraco para texto pequeno')}
                                        </li>
                                    );
                                })}
                            </ul>
                        </Panel>

                        <Panel title="Letra e cantos">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FontSelect
                                    id="heading-font"
                                    label="Títulos"
                                    value={data.heading_font}
                                    options={fonts}
                                    onChange={(value) =>
                                        setData('heading_font', value)
                                    }
                                    error={errors.heading_font}
                                />
                                <FontSelect
                                    id="body-font"
                                    label="Texto"
                                    value={data.body_font}
                                    options={fonts.filter(
                                        (font) => !font.headingOnly,
                                    )}
                                    onChange={(value) =>
                                        setData('body_font', value)
                                    }
                                    error={errors.body_font}
                                />
                            </div>
                            <div className="mt-4 grid gap-1.5">
                                <Label>Cantos</Label>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    value={data.radius}
                                    onValueChange={(value) =>
                                        value && setData('radius', value)
                                    }
                                    className="justify-start"
                                >
                                    {radii.map((radius) => (
                                        <ToggleGroupItem
                                            key={radius}
                                            value={radius}
                                        >
                                            {RADIUS_LABELS[radius] ?? radius}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            </div>
                        </Panel>

                        <Panel
                            title="Logotipo e favicon"
                            description="Sem logotipo, o topo mostra o nome da loja. O favicon é o ícone do separador do browser (quadrado, de preferência 512×512)."
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="theme-logo">Logotipo</Label>
                                    <ImageField
                                        id="theme-logo"
                                        value={data.logo}
                                        preview={logoSrc}
                                        onChange={(id, src) => {
                                            setData('logo', id);
                                            setLogoSrc(src);
                                        }}
                                    />
                                    <InputError message={errors.logo} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="theme-favicon">
                                        Favicon
                                    </Label>
                                    <ImageField
                                        id="theme-favicon"
                                        value={data.favicon}
                                        preview={faviconSrc}
                                        onChange={(id, src) => {
                                            setData('favicon', id);
                                            setFaviconSrc(src);
                                        }}
                                    />
                                    <InputError message={errors.favicon} />
                                </div>
                            </div>
                        </Panel>

                        <div className="flex items-center gap-3">
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Guardar tema
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    setData((current) => ({
                                        ...current,
                                        ...defaults,
                                    }))
                                }
                            >
                                Usar as cores de origem
                            </Button>
                        </div>
                    </form>

                    <section
                        aria-label="Pré-visualização do tema"
                        className="flex min-h-[70vh] flex-col overflow-hidden rounded-xl border border-border/60"
                    >
                        <div className="flex items-center gap-2 border-b border-border/60 px-3 py-2 text-sm">
                            <span className="flex-1 text-muted-foreground">
                                Início, com o tema por gravar
                            </span>
                            <ToggleGroup
                                type="single"
                                size="sm"
                                value={previewMode}
                                onValueChange={(value) =>
                                    value && setPreviewMode(value as Mode)
                                }
                            >
                                <ToggleGroupItem value="light">
                                    Claro
                                </ToggleGroupItem>
                                <ToggleGroupItem value="dark">
                                    Escuro
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                        <iframe
                            ref={frame}
                            src={previewUrl}
                            title="Pré-visualização do tema"
                            className="w-full flex-1 bg-background"
                            onLoad={() =>
                                setData((current) => ({ ...current }))
                            }
                        />
                    </section>
                </div>
            </div>
            <ConfirmDialog
                open={confirmReset}
                onOpenChange={setConfirmReset}
                title="Repor o tema original?"
                description="As cores, as letras, os cantos, o logotipo e o favicon voltam aos de origem."
                confirmLabel="Repor"
                onConfirm={() =>
                    router.delete(reset().url, {
                        onSuccess: () => setConfirmReset(false),
                    })
                }
            />
        </>
    );
}

function FontSelect({
    id,
    label,
    value,
    options,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}

ThemePage.layout = {
    breadcrumbs: [{ title: 'Montra', href: '/admin/montra/paginas' }],
};
