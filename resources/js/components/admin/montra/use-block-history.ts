import { useCallback, useRef, useState } from 'react';

/** Quantos passos se guardam para trás (e para a frente). */
const LIMIT = 50;
/** Alterações seguidas ao mesmo nó, sem uma pausa maior, são um passo. */
const BURST = 500;

type History<T> = {
    past: T[];
    present: T;
    future: T[];
};

type Updater<T> = T | ((current: T) => T);

/**
 * Um valor (a árvore da página) com desfazer/refazer. Cada alteração é um
 * passo; quem passar uma `burst` (o id do nó, ao escrever num campo) junta as
 * alterações seguidas num só passo até haver meio segundo de pausa.
 */
export function useHistory<T>(initial: T) {
    const [history, setHistory] = useState<History<T>>({
        past: [],
        present: initial,
        future: [],
    });
    const last = useRef<{ key: string; at: number } | null>(null);

    const set = useCallback((updater: Updater<T>, burst?: string) => {
        const now = Date.now();
        const coalesce =
            burst !== undefined &&
            last.current?.key === burst &&
            now - last.current.at < BURST;
        last.current = burst === undefined ? null : { key: burst, at: now };

        setHistory((current) => {
            const next =
                typeof updater === 'function'
                    ? (updater as (current: T) => T)(current.present)
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
    const reset = useCallback((value: T) => {
        last.current = null;
        setHistory({ past: [], present: value, future: [] });
    }, []);

    return {
        present: history.present,
        set,
        undo,
        redo,
        reset,
        canUndo: history.past.length > 0,
        canRedo: history.future.length > 0,
    };
}
