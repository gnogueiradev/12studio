import { Head } from '@inertiajs/react';
import { FileWarning } from 'lucide-react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { BlockList } from '@/blocks';
import { useT } from '@/lib/i18n';
import type { PreviewMessage } from '@/types/page-builder';
import type { StoreBlock, StorePageData } from '@/types/store';

/**
 * Uma página da montra montada por blocos (App\PageBuilder). O conteúdo, a
 * ordem e o estilo vêm todos do servidor.
 */
export default function StorePage({ page }: { page: StorePageData }) {
    const t = useT();

    return (
        <>
            <Head title={page.title} />

            {page.draft && (
                <p
                    role="status"
                    className="flex items-center justify-center gap-2 bg-warning-soft px-4 py-2 text-center text-sm text-warning-soft-foreground"
                >
                    <FileWarning aria-hidden="true" className="size-4" />
                    {t('preview.draft')}
                </p>
            )}

            {page.preview ? (
                <EditorPreview initial={page.blocks} />
            ) : (
                <BlockList blocks={page.blocks} />
            )}
        </>
    );
}

/**
 * A página dentro do iframe do editor: troca os blocos pelos que o editor
 * manda (já renderizados pelo servidor), realça o bloco selecionado e, ao
 * clicar num bloco, diz ao editor qual foi — em vez de seguir links.
 */
function EditorPreview({ initial }: { initial: StoreBlock[] }) {
    const t = useT();
    const [blocks, setBlocks] = useState(initial);
    const [selected, setSelected] = useState<string | null>(null);
    // "Ver como cliente": o mesmo endereço aberto num separador, fora do
    // editor. Aí é só o rascunho, com a faixa a dizê-lo. No SSR não há
    // window: o servidor desenha a versão do iframe.
    const framed = useSyncExternalStore(
        noSubscription,
        () => window.self !== window.top,
        () => true,
    );

    useEffect(() => {
        if (!framed) {
            return;
        }

        const parent = window.parent;
        const origin = window.location.origin;

        const onMessage = (event: MessageEvent) => {
            if (event.origin !== origin || event.source !== parent) {
                return;
            }

            const message = event.data as PreviewMessage | undefined;

            if (message?.source !== 'studio-editor') {
                return;
            }

            if (message.kind === 'blocks') {
                setBlocks(message.blocks as StoreBlock[]);
            }

            if (message.kind === 'select') {
                setSelected(message.id);
            }

            if (message.kind === 'theme') {
                applyTheme(message.css, message.fonts, message.mode);
            }
        };

        window.addEventListener('message', onMessage);
        parent.postMessage(
            {
                source: 'studio-preview',
                kind: 'ready',
            } satisfies PreviewMessage,
            origin,
        );

        return () => window.removeEventListener('message', onMessage);
    }, [framed]);

    useEffect(() => {
        if (selected) {
            document
                .querySelector(`[data-block-id="${CSS.escape(selected)}"]`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, [selected]);

    // Na pré-visualização não se navega (nem no header, que levaria o iframe
    // para uma página que não se deixa emoldurar): um clique escolhe o bloco.
    useEffect(() => {
        if (!framed) {
            return;
        }

        const onClick = (event: MouseEvent) => {
            const target = event.target as HTMLElement;

            if (target.closest('a, button, [role="button"]')) {
                event.preventDefault();
            }

            const id =
                target.closest<HTMLElement>('[data-block-id]')?.dataset.blockId;

            if (id) {
                setSelected(id);
                window.parent.postMessage(
                    {
                        source: 'studio-preview',
                        kind: 'select',
                        id,
                    } satisfies PreviewMessage,
                    window.location.origin,
                );
            }
        };

        document.addEventListener('click', onClick, true);

        return () => document.removeEventListener('click', onClick, true);
    }, [framed]);

    if (!framed) {
        return (
            <>
                <p
                    role="status"
                    className="flex items-center justify-center gap-2 bg-warning-soft px-4 py-2 text-center text-sm text-warning-soft-foreground"
                >
                    <FileWarning aria-hidden="true" className="size-4" />
                    {t('preview.draft')}
                </p>
                <BlockList blocks={blocks} />
            </>
        );
    }

    return (
        <>
            {selected && (
                <style>{`[data-block-id="${CSS.escape(selected)}"]{outline:2px solid var(--ring);outline-offset:-2px}`}</style>
            )}
            <style>{`[data-block-id]{cursor:pointer}[data-block-id]:hover{outline:1px dashed var(--ring);outline-offset:-1px}`}</style>
            <BlockList blocks={blocks} />
            {blocks.length === 0 && (
                <p className="px-4 py-24 text-center text-sm text-muted-foreground">
                    Página vazia: junta blocos no editor.
                </p>
            )}
        </>
    );
}

/** O "estar dentro de um iframe" não muda depois de a página abrir. */
function noSubscription(): () => void {
    return () => {};
}

/**
 * O tema por gravar do editor do tema: troca o CSS do <style id="store-theme">
 * e junta as letras escolhidas (o HTML vem do servidor, que é a mesma origem;
 * só se aproveitam os <link> e <style> dele).
 */
function applyTheme(css: string, fonts: string, mode: 'light' | 'dark') {
    document.documentElement.classList.toggle('dark', mode === 'dark');

    let style = document.getElementById('store-theme');

    if (!style) {
        style = document.createElement('style');
        style.id = 'store-theme';
        document.head.appendChild(style);
    }

    style.textContent = css;

    document
        .querySelectorAll('[data-theme-preview]')
        .forEach((node) => node.remove());

    const parsed = new DOMParser().parseFromString(fonts, 'text/html');

    parsed.querySelectorAll('link, style').forEach((node) => {
        const clone = document.importNode(node, true) as HTMLElement;
        clone.dataset.themePreview = '';
        document.head.appendChild(clone);
    });
}
