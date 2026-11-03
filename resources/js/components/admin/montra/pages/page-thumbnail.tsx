import { useEffect, useRef, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { preview } from '@/routes/admin/montra';

// A montra desenha-se num ecrã de portátil e encolhe-se até caber no cartão.
const FRAME_WIDTH = 1280;
const FRAME_HEIGHT = 800;

// No máximo três a carregar ao mesmo tempo: cada miniatura é uma montra
// inteira (pedido, scripts, imagens), e dez de uma vez entupiam o servidor.
const MAX_LOADING = 3;
let loading = 0;
const waiting: (() => void)[] = [];

function acquire(start: () => void): () => void {
    let state: 'waiting' | 'running' | 'done' = 'waiting';
    const run = () => {
        state = 'running';
        loading += 1;
        start();
    };

    if (loading < MAX_LOADING) {
        run();
    } else {
        waiting.push(run);
    }

    return () => {
        if (state === 'waiting') {
            waiting.splice(waiting.indexOf(run), 1);
        } else if (state === 'running') {
            loading -= 1;
            waiting.shift()?.();
        }

        state = 'done';
    };
}

/**
 * A miniatura de uma página: a pré-visualização do editor (o rascunho, tal
 * como o servidor o desenha) num iframe encolhido, que não se clica nem se
 * foca. Só se monta quando o cartão está perto do ecrã e há vez na fila
 * (três de cada vez), e depois fica.
 */
export function PageThumbnail({
    pageId,
    title,
    className,
}: {
    pageId: number;
    title: string;
    className?: string;
}) {
    const box = useRef<HTMLDivElement>(null);
    const [near, setNear] = useState(false);
    const [scale, setScale] = useState(0);
    const [loaded, setLoaded] = useState(false);
    const [turn, setTurn] = useState(false);
    const release = useRef<(() => void) | null>(null);

    useEffect(() => {
        const node = box.current;

        if (!node) {
            return;
        }

        const resize = new ResizeObserver(([entry]) => {
            setScale(entry.contentRect.width / FRAME_WIDTH);
        });
        resize.observe(node);

        const intersect = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setNear(true);
                    intersect.disconnect();
                }
            },
            { rootMargin: '300px' },
        );
        intersect.observe(node);

        return () => {
            resize.disconnect();
            intersect.disconnect();
        };
    }, []);

    // Perto do ecrã, espera pela vez; a vez acaba quando a montra carrega
    // (ou, se nunca carregar, ao fim de 15 s, para não prender a fila).
    useEffect(() => {
        if (!near) {
            return;
        }

        let timeout: number | undefined;
        release.current = acquire(() => {
            setTurn(true);
            timeout = window.setTimeout(() => release.current?.(), 15000);
        });

        return () => {
            window.clearTimeout(timeout);
            release.current?.();
        };
    }, [near]);

    return (
        <div
            ref={box}
            inert
            aria-hidden
            className={cn(
                'relative aspect-[16/10] overflow-hidden bg-muted',
                className,
            )}
        >
            {!loaded && <Skeleton className="absolute inset-0 rounded-none" />}
            {turn && scale > 0 && (
                <iframe
                    src={preview(pageId).url}
                    title={title}
                    loading="lazy"
                    scrolling="no"
                    tabIndex={-1}
                    aria-hidden
                    onLoad={() => {
                        setLoaded(true);
                        release.current?.();
                    }}
                    style={{
                        width: FRAME_WIDTH,
                        height: FRAME_HEIGHT,
                        transform: `scale(${scale})`,
                    }}
                    className={cn(
                        'pointer-events-none absolute top-0 left-0 max-w-none origin-top-left border-0 transition-opacity duration-300',
                        loaded ? 'opacity-100' : 'opacity-0',
                    )}
                />
            )}
        </div>
    );
}
