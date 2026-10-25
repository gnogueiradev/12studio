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
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { EditorBlock, PreviewMessage } from '@/types/page-builder';

const DEVICES = {
    mobile: { width: 390, label: 'Telemóvel', icon: Smartphone },
    tablet: { width: 820, label: 'Tablet', icon: Tablet },
    desktop: { width: null, label: 'Computador', icon: Monitor },
} as const;

type Device = keyof typeof DEVICES;

type Props = {
    url: string;
    renderUrl: string;
    blocks: EditorBlock[];
    locale: string;
    locales: string[];
    onLocale: (locale: string) => void;
    selected: string | null;
    onSelect: (id: string) => void;
};

/**
 * A pré-visualização ao vivo: a montra a sério num iframe (mesma origem).
 * Cada alteração vai ao servidor só para ser renderizada — nada se grava — e
 * os blocos prontos seguem para o iframe por postMessage, que os troca sem
 * recarregar. Clicar num bloco lá dentro seleciona-o aqui.
 */
export function PreviewFrame({
    url,
    renderUrl,
    blocks,
    locale,
    locales,
    onLocale,
    selected,
    onSelect,
}: Props) {
    const frame = useRef<HTMLIFrameElement>(null);
    const rendered = useRef<unknown[] | null>(null);
    const [device, setDevice] = useState<Device>('desktop');
    const [rendering, setRendering] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);

    const post = useCallback((message: PreviewMessage) => {
        frame.current?.contentWindow?.postMessage(
            message,
            window.location.origin,
        );
    }, []);

    // Renderiza os blocos do editor (com uma pausa, para não ir ao servidor
    // a cada letra) e manda-os ao iframe.
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setRendering(true);
            requestJson<{ blocks: unknown[] }>(
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
    const src = `${url}?locale=${encodeURIComponent(locale)}`;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex flex-wrap items-center gap-2 border-b border-border/60 px-3 py-2">
                <ToggleGroup
                    type="single"
                    size="sm"
                    value={device}
                    onValueChange={(value) =>
                        value && setDevice(value as Device)
                    }
                    aria-label="Tamanho do ecrã"
                >
                    {(Object.keys(DEVICES) as Device[]).map((key) => {
                        const Icon = DEVICES[key].icon;

                        return (
                            <ToggleGroupItem
                                key={key}
                                value={key}
                                aria-label={DEVICES[key].label}
                            >
                                <Icon />
                            </ToggleGroupItem>
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
                <span className="flex-1" />
                {rendering && <Spinner className="size-4" />}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label="Recarregar a pré-visualização"
                    onClick={() => setReloadKey((key) => key + 1)}
                >
                    <RotateCw />
                </Button>
            </div>
            <div className="flex min-h-0 flex-1 justify-center overflow-auto bg-muted/50 p-3">
                <iframe
                    key={`${src}-${reloadKey}`}
                    ref={frame}
                    src={src}
                    title="Pré-visualização da página"
                    className={cn(
                        'h-full rounded-md border border-border/60 bg-background shadow-sm transition-[width]',
                        width === null && 'w-full',
                    )}
                    style={width === null ? undefined : { width }}
                />
            </div>
        </div>
    );
}
