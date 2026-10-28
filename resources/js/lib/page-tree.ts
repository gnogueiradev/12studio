import type {
    StoreContainer,
    StoreDeviceValues,
    StoreNode,
} from '@/types/store';

/** O `type` de um elemento é uma string livre: só o `container` tem filhos. */
export function isContainer(node: StoreNode): node is StoreContainer {
    return node.type === 'container';
}

/**
 * Os valores de uma propriedade nos três tamanhos, com a herança feita: o
 * tablet herda do computador e o telemóvel do tablet. O `fallback` vale para
 * o computador quando o dono não o definiu.
 */
export function resolveDevices<T>(
    v: StoreDeviceValues<T> | undefined,
    fallback?: T,
): StoreDeviceValues<T> {
    const desktop = v?.desktop ?? fallback;
    const tablet = v?.tablet ?? desktop;
    const mobile = v?.mobile ?? tablet;

    return { desktop, tablet, mobile };
}
