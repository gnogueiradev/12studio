import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/**
 * Um UUID v4. O crypto.randomUUID() so existe em contextos seguros (https ou
 * localhost) — em http://12studio.test nao ha, e o editor partia. O
 * getRandomValues() existe sempre e chega para o mesmo formato.
 */
export function randomId(): string {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'));

    return [
        hex.slice(0, 4),
        hex.slice(4, 6),
        hex.slice(6, 8),
        hex.slice(8, 10),
        hex.slice(10, 16),
    ]
        .map((group) => group.join(''))
        .join('-');
}
