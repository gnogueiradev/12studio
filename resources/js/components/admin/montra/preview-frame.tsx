import { Monitor, RotateCw, Smartphone, Tablet } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { requestJson } from '@/lib/http';
import type { EditorNode, PreviewMessage } from '@/types/page-builder';
import type { StoreNode } from '@/types/store';

/** A largura a sério de cada ecrã; a pré-visualização encolhe para caber. */
const DEVICES = {
    mobile: { width: 390, label: 'Telemóvel', icon: Smartphone },
    tablet: { width: 820, label: 'Tablet', icon: Tablet },
    desktop: { width: 1280, label: 'Computador', icon: Monitor },
} as const;

export type Device = keyof typeof DEVICES;

type Props = {
    url: string;
    renderUrl: string;
    blocks: EditorNode[];
    locale: string;
    device: Device;
    selected: string | null;
    onSelect: (id: string) => void;
};

/**
 * A pré-visualização ao vivo: a montra a sério num iframe (mesma origem).
 * Cada alteração vai ao servidor só para ser renderizada — nada se grava — e
 * os blocos prontos seguem para o iframe por postMessage, que os troca sem
 * recarregar. Clicar num bloco lá dentro seleciona-o aqui.
 *
 * O iframe tem a largura do ecrã escolhido e é reduzido (nunca ampliado) para
 * caber, para o "Computador" mostrar mesmo o layout de computador.
 */
export function PreviewFrame({
    url,
    renderUrl,
    blocks,
    locale,
    device,
    selected,
    onSelect,
}: Props) {
    const frame = useRef<HTMLIFrameElement>(null);
    const canvas = useRef<HTMLDivElement>(null);
    const rendered = useRef<StoreNode[] | null>(null);
    const [rendering, setRendering] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    const [size, setSize] = useState<{ width: number; height: number } | null>(
        null,
    );

    const post = useCallback((message: PreviewMessage) => {
        frame.current?.contentWindow?.postMessage(
            message,
            window.location.origin,
        );
    }, []);

    useEffect(() => {
        const node = canvas.current;

        if (!node) {
            return;
        }

        const observer = new ResizeObserver(([entry]) => {
            const { width, height } = entry.contentRect;
            setSize({ width, height });
        });
        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    // Renderiza os blocos do editor (com uma pausa, para não ir ao servidor
    // a cada letra) e manda-os ao iframe.
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setRendering(true);
            requestJson<{ blocks: StoreNode[] }>(
                renderUrl,
                'POST',
                { blocks, locale },
                controller.signal,
            )
                .then((response) => {
                    rendered.current = response.blocks;
                    post({
                        source: 'studio-editor',
                        kind: 'blocks',
                        blocks: response.blocks,
                    });
                })
                .catch(() => undefined)
                .finally(() => setRendering(false));
        }, 350);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [blocks, locale, renderUrl, post]);

    useEffect(() => {
        post({ source: 'studio-editor', kind: 'select', id: selected });
    }, [selected, post]);

    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (
                event.origin !== window.location.origin ||
                event.source !== frame.current?.contentWindow
            ) {
                return;
            }

            const message = event.data as PreviewMessage | undefined;

            if (message?.source !== 'studio-preview') {
                return;
            }

            if (message.kind === 'ready') {
                if (rendered.current) {
                    post({
                        source: 'studio-editor',
                        kind: 'blocks',
                        blocks: rendered.current,
                    });
                }

                post({ source: 'studio-editor', kind: 'select', id: selected });
            }

            if (message.kind === 'select') {
                onSelect(message.id);
            }
        };

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, [onSelect, post, selected]);

    const width = DEVICES[device].width;
    const scale = size && size.width > 0 ? Math.min(1, size.width / width) : 1;
    const src = `${url}?locale=${encodeURIComponent(locale)}`;

    return (
        <div className="flex h-full min-h-0 flex-col bg-muted">
            <div
                ref={canvas}
                className="flex min-h-0 flex-1 justify-center overflow-hidden p-3 sm:p-5"
            >
                <div
                    className="relative h-full shrink-0 overflow-hidden rounded-lg bg-background shadow-lg ring-1 ring-border"
                    style={{
                        width: width * scale,
                        height: size?.height || undefined,
                    }}
                >
                    <iframe
                        key={`${src}-${reloadKey}`}
                        ref={frame}
                        src={src}
                        title="Pré-visualização da página"
                        className="absolute top-0 left-0 block border-0 bg-background"
                        style={{
                            width,
                            height: size?.height ? size.height / scale : '100%',
                            transform: `scale(${scale})`,
                            transformOrigin: '0 0',
                        }}
                    />
                </div>
            </div>
            <div className="flex h-9 shrink-0 items-center gap-2 px-3 text-xs text-muted-foreground">
                <span>
                    {DEVICES[device].label} · {width}px
                </span>
                <span
                    className="rounded bg-background/60 px-1.5 py-0.5 tabular-nums"
                    title="Zoom da pré-visualização"
                >
                    {Math.round(scale * 100)}%
                </span>
                <span className="flex-1" />
                {rendering && <Spinner className="size-3.5" />}
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label="Recarregar a pré-visualização"
                            onClick={() => setReloadKey((key) => key + 1)}
                        >
                            <RotateCw />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Recarregar</TooltipContent>
                </Tooltip>
            </div>
        </div>
    );
}

/** O tamanho do ecrã e o idioma da pré-visualização (na barra de topo). */
export function PreviewControls({
    device,
    onDevice,
    locale,
    locales,
    onLocale,
}: {
    device: Device;
    onDevice: (device: Device) => void;
    locale: string;
    locales: string[];
    onLocale: (locale: string) => void;
}) {
    return (
        <div className="flex items-center gap-2">
            <ToggleGroup
                type="single"
                size="sm"
                variant="outline"
                value={device}
                onValueChange={(value) => value && onDevice(value as Device)}
                aria-label="Tamanho do ecrã"
            >
                {(Object.keys(DEVICES) as Device[]).map((key) => {
                    const Icon = DEVICES[key].icon;

                    return (
                        <Tooltip key={key}>
                            <TooltipTrigger asChild>
                                <ToggleGroupItem
                                    value={key}
                                    aria-label={DEVICES[key].label}
                                >
                                    <Icon />
                                </ToggleGroupItem>
                            </TooltipTrigger>
                            <TooltipContent>
                                {DEVICES[key].label}
                            </TooltipContent>
                        </Tooltip>
                    );
                })}
            </ToggleGroup>
            <Select value={locale} onValueChange={onLocale}>
                <SelectTrigger
                    size="sm"
                    className="w-20"
                    aria-label="Idioma da pré-visualização"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {locales.map((code) => (
                        <SelectItem key={code} value={code}>
                            {code.toUpperCase()}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
