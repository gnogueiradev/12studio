import { useSyncExternalStore } from 'react';

/** O `xl` do Tailwind: a partir daqui o editor de páginas tem três colunas. */
const WIDE_BREAKPOINT = 1280;

const mql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia(`(min-width: ${WIDE_BREAKPOINT}px)`);

function subscribe(callback: (event: MediaQueryListEvent) => void) {
    if (!mql) {
        return () => {};
    }

    mql.addEventListener('change', callback);

    return () => mql.removeEventListener('change', callback);
}

export function useIsWide(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => mql?.matches ?? false,
        () => false,
    );
}
