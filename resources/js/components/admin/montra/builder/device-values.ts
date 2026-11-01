import type { EditorDeviceProps } from '@/types/page-builder';
import type { StoreDevice, StoreDeviceValues } from '@/types/store';

const ORDER: StoreDevice[] = ['desktop', 'tablet', 'mobile'];

export const DEVICE_NAMES: Record<StoreDevice, string> = {
    desktop: 'computador',
    tablet: 'tablet',
    mobile: 'telemóvel',
};

export type DeviceReading = {
    /** O valor escrito neste dispositivo. */
    own: unknown;
    /** O que vem do tamanho acima. */
    inherited: unknown;
    /** De que dispositivo vem o `inherited`. */
    from: StoreDevice | null;
};

/** Lê um valor como o NodeTree o resolve: o que falta herda do tamanho acima. */
export function readDevice(
    values: StoreDeviceValues | undefined,
    device: StoreDevice,
): DeviceReading {
    const own = values?.[device] ?? undefined;

    for (let at = ORDER.indexOf(device) - 1; at >= 0; at--) {
        const inherited = values?.[ORDER[at]] ?? undefined;

        if (inherited !== undefined) {
            return { own, inherited, from: ORDER[at] };
        }
    }

    return { own, inherited: undefined, from: null };
}

/** Escreve só neste dispositivo; os outros ficam como estavam. */
export function writeDevice(
    values: StoreDeviceValues | undefined,
    device: StoreDevice,
    value: unknown,
): StoreDeviceValues {
    return { ...values, [device]: value };
}

/** O "×": tira este dispositivo, que volta a herdar. */
export function clearDevice(
    values: StoreDeviceValues | undefined,
    device: StoreDevice,
): StoreDeviceValues {
    const next = { ...values };
    delete next[device];

    return next;
}

/**
 * Esconder é a exceção à herança: esconder só no computador não pode esconder
 * também no telemóvel. Por isso, ao ligar, os tamanhos abaixo ainda por definir
 * ficam com `false` explícito. Tudo desligado é o mesmo que nada.
 */
export function writeHide(
    values: StoreDeviceValues | undefined,
    device: StoreDevice,
    hidden: boolean,
): StoreDeviceValues {
    const next: StoreDeviceValues = { ...values, [device]: hidden };

    if (hidden) {
        for (const below of ORDER.slice(ORDER.indexOf(device) + 1)) {
            next[below] ??= false;
        }
    }

    return Object.values(next).some(Boolean) ? next : {};
}

/** Sem nenhum dispositivo, a propriedade sai do JSON (o gravado fica esparso). */
export function withProp(
    props: EditorDeviceProps,
    key: string,
    values: StoreDeviceValues,
): EditorDeviceProps {
    const next = { ...props };

    if (Object.keys(values).length === 0) {
        delete next[key];
    } else {
        next[key] = values;
    }

    return next;
}
