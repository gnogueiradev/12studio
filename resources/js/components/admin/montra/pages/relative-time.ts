import { useSyncExternalStore } from 'react';

const MINUTE = 60_000;

const shortDate = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
});

const shortDateWithYear = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

/**
 * Há quanto tempo, à portuguesa: "agora mesmo", "há 5 min", "há 2 h",
 * "ontem", "há 3 dias" e, a partir de uma semana, a data curta ("a 12 out.").
 */
export function formatRelative(value: string, now: number): string {
    const date = new Date(value);
    const minutes = Math.floor((now - date.getTime()) / MINUTE);

    if (minutes < 1) {
        return 'agora mesmo';
    }

    if (minutes < 60) {
        return `há ${minutes} min`;
    }

    // Os dias contam-se pelo calendário: ontem às 23h já é "ontem" à 1h.
    const days = calendarDays(date, new Date(now));

    if (days === 0) {
        return `há ${Math.floor(minutes / 60)} h`;
    }

    if (days === 1) {
        return 'ontem';
    }

    if (days < 7) {
        return `há ${days} dias`;
    }

    const sameYear = date.getFullYear() === new Date(now).getFullYear();

    return `a ${(sameYear ? shortDate : shortDateWithYear).format(date)}`;
}

function calendarDays(from: Date, to: Date): number {
    const start = new Date(from.getFullYear(), from.getMonth(), from.getDate());
    const end = new Date(to.getFullYear(), to.getMonth(), to.getDate());

    return Math.round((end.getTime() - start.getTime()) / (24 * 60 * MINUTE));
}

/**
 * O minuto atual, que se renova sozinho. No SSR (e na hidratação) é null:
 * o "há 5 min" do servidor podia já não bater certo com o do browser.
 */
export function useNow(): number | null {
    return useSyncExternalStore(subscribe, currentMinute, () => null);
}

function subscribe(callback: () => void): () => void {
    const timer = window.setInterval(callback, 30_000);

    return () => window.clearInterval(timer);
}

function currentMinute(): number {
    return Math.floor(Date.now() / MINUTE) * MINUTE;
}
