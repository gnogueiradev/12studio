import { useEffect, useRef } from 'react';

export const STRUCTURES: { label: string; columns: number[] }[] = [
    { label: '1 coluna', columns: [100] },
    { label: '50 / 50', columns: [50, 50] },
    { label: '66 / 34', columns: [66, 34] },
    { label: '34 / 66', columns: [34, 66] },
    { label: '3 colunas', columns: [33, 33, 34] },
    { label: '4 colunas', columns: [25, 25, 25, 25] },
];

const WIDTH = 264;
const HEIGHT = 196;
const GUTTER = 8;

type Props = {
    /** O ponto do clique, em coordenadas do ecrã. */
    anchor: { x: number; y: number };
    onPick: (columns: number[]) => void;
    onClose: () => void;
};

/**
 * As estruturas, num popover junto ao "▥". Abre fora do iframe (tem de caber
 * mesmo com a página reduzida), por isso fecha também quando a janela perde o
 * foco: um clique dentro do iframe não chega cá como clique.
 */
export function StructurePicker({ anchor, onPick, onClose }: Props) {
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        box.current?.querySelector('button')?.focus();

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                // O Esc fecha isto; não larga também a seleção.
                event.stopImmediatePropagation();
                onClose();
            }
        };
        const onPointer = (event: PointerEvent) => {
            if (!box.current?.contains(event.target as Node)) {
                onClose();
            }
        };

        window.addEventListener('keydown', onKey, true);
        window.addEventListener('pointerdown', onPointer, true);
        window.addEventListener('blur', onClose);

        return () => {
            window.removeEventListener('keydown', onKey, true);
            window.removeEventListener('pointerdown', onPointer, true);
            window.removeEventListener('blur', onClose);
        };
    }, [onClose]);

    const left = Math.max(
        GUTTER,
        Math.min(anchor.x - WIDTH / 2, window.innerWidth - WIDTH - GUTTER),
    );
    const top =
        anchor.y + HEIGHT + GUTTER * 2 > window.innerHeight
            ? Math.max(GUTTER, anchor.y - HEIGHT - GUTTER)
            : anchor.y + GUTTER;

    return (
        <div
            ref={box}
            role="dialog"
            aria-label="Escolher estrutura"
            style={{ left, top, width: WIDTH }}
            className="fixed z-50 rounded-lg border border-border bg-popover p-2 text-popover-foreground shadow-lg"
        >
            <p className="px-1 pb-2 text-xs font-medium text-muted-foreground">
                Escolhe uma estrutura
            </p>
            <div className="grid grid-cols-3 gap-1.5">
                {STRUCTURES.map(({ label, columns }) => (
                    <button
                        key={label}
                        type="button"
                        onClick={() => onPick(columns)}
                        className="flex flex-col gap-1.5 rounded-md border border-border/60 p-1.5 text-center text-[11px] leading-tight transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <span aria-hidden className="flex h-8 gap-0.5">
                            {columns.map((width, index) => (
                                <span
                                    key={index}
                                    className="rounded-sm bg-muted-foreground/40"
                                    style={{ flex: width }}
                                />
                            ))}
                        </span>
                        {label}
                    </button>
                ))}
            </div>
        </div>
    );
}
