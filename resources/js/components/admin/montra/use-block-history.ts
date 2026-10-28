import { useCallback, useRef, useState } from 'react';
import type { EditorBlock } from '@/types/page-builder';

/** Quantos passos se guardam para trás (e para a frente). */
const LIMIT = 50;
/** Alterações seguidas ao mesmo bloco, sem uma pausa maior, são um passo. */
const BURST = 500;

type History = {
    past: EditorBlock[][];
    present: EditorBlock[];
    future: EditorBlock[][];
};

type Updater = EditorBlock[] | ((current: EditorBlock[]) => EditorBlock[]);

/**
 * Os blocos da página com desfazer/refazer. Cada alteração é um passo; quem
 * passar uma `burst` (o id do bloco, ao escrever num campo) junta as
 * alterações seguidas num só passo até haver meio segundo de pausa.
 */
export function useBlockHistory(initial: EditorBlock[]) {
    const [history, setHistory] = useState<History>({
        past: [],
        present: initial,
        future: [],
    });
    const last = useRef<{ key: string; at: number } | null>(null);

    const setBlocks = useCallback((updater: Updater, burst?: string) => {
        const now = Date.now();
        const coalesce =
            burst !== undefined &&
            last.current?.key === burst &&
            now - last.current.at < BURST;
        last.current = burst === undefined ? null : { key: burst, at: now };

        setHistory((current) => {
            const next =
                typeof updater === 'function'
                    ? updater(current.present)
                    : updater;

            if (next === current.present) {
                return current;
            }

            return {
                past: coalesce
                    ? current.past
                    : [...current.past, current.present].slice(-LIMIT),
                present: next,
                future: [],
            };
        });
    }, []);

    const undo = useCallback(() => {
        last.current = null;
        setHistory((current) =>
            current.past.length === 0
                ? current
                : {
                      past: current.past.slice(0, -1),
                      present: current.past[current.past.length - 1],
                      future: [current.present, ...current.future].slice(
                          0,
                          LIMIT,
                      ),
                  },
        );
    }, []);

    const redo = useCallback(() => {
        last.current = null;
        setHistory((current) =>
            current.future.length === 0
                ? current
                : {
                      past: [...current.past, current.present].slice(-LIMIT),
                      present: current.future[0],
                      future: current.future.slice(1),
                  },
        );
    }, []);

    /** Troca tudo e esquece o histórico (o que veio do servidor). */
    const reset = useCallback((blocks: EditorBlock[]) => {
        last.current = null;
        setHistory({ past: [], present: blocks, future: [] });
    }, []);

    return {
        blocks: history.present,
        setBlocks,
        undo,
        redo,
        reset,
        canUndo: history.past.length > 0,
        canRedo: history.future.length > 0,
    };
}
