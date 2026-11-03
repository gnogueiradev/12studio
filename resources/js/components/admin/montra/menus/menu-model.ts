import { randomId } from '@/lib/utils';
import type { StoreLink } from '@/types/page-builder';

export type ItemType = 'path' | 'url' | 'email';

export type MenuItem = {
    /** Só no editor: seleção e drag and drop. */
    uid: string;
    label: Record<string, string>;
    type: ItemType;
    target: string;
    children: MenuItem[];
};

export type ServerItem = Omit<MenuItem, 'uid' | 'children'> & {
    children: ServerItem[];
};

export type MenuLocation = 'header' | 'footer';

export type MenuLimits = { items: number; children: number };

/**
 * Erros do último "Guardar", presos ao item (uid) e não à posição — assim
 * continuam no sítio certo depois de arrastares.
 */
export type MenuErrors = {
    top?: string;
    byUid: Record<string, Record<string, string>>;
};

export const NO_ERRORS: MenuErrors = { byUid: {} };

/** O mesmo endereço do StoreMenus::COMPLAINTS_BOOK_URL. */
export const COMPLAINTS_BOOK_URL = 'https://www.livroreclamacoes.pt/Inicio/';

/** Os rótulos do lang/{locale}/store.php (footer.complaints_book). */
const COMPLAINTS_BOOK_LABEL: Record<string, string> = {
    pt: 'Livro de Reclamações',
    es: 'Libro de Reclamaciones',
    en: 'Complaints Book',
    fr: 'Livre de réclamations',
};

export const LOCALE_NAMES: Record<string, string> = {
    pt: 'Português',
    es: 'Espanhol',
    en: 'Inglês',
    fr: 'Francês',
};

export function blankItem(): MenuItem {
    return {
        uid: randomId(),
        label: {},
        type: 'path',
        target: '',
        children: [],
    };
}

export function complaintsBookItem(locales: string[]): MenuItem {
    return {
        ...blankItem(),
        label: Object.fromEntries(
            locales
                .filter((locale) => COMPLAINTS_BOOK_LABEL[locale])
                .map((locale) => [locale, COMPLAINTS_BOOK_LABEL[locale]]),
        ),
        type: 'url',
        target: COMPLAINTS_BOOK_URL,
    };
}

/**
 * Dá uids aos itens do servidor. Com `previous`, reaproveita os uids por
 * posição — depois de guardar, a seleção continua no mesmo item.
 */
export function withUids(
    items: ServerItem[],
    previous: MenuItem[] = [],
): MenuItem[] {
    return items.map((item, index) => ({
        // Um rótulo vazio chega do PHP como [] — o spread dá {} na mesma.
        label: { ...item.label },
        type: item.type,
        target: item.target,
        uid: previous[index]?.uid ?? randomId(),
        children: withUids(item.children ?? [], previous[index]?.children),
    }));
}

export function strip(items: MenuItem[]): ServerItem[] {
    return items.map((item) => ({
        label: item.label,
        type: item.type,
        target: item.target,
        children: strip(item.children),
    }));
}

/** Compara como o servidor grava: espaços e rótulos vazios não contam. */
export function sameMenu(
    items: MenuItem[],
    saved: ServerItem[],
    locales: string[],
): boolean {
    const normalize = (list: (MenuItem | ServerItem)[]): unknown =>
        list.map((item) => [
            locales.map((locale) => (item.label?.[locale] ?? '').trim()),
            item.type,
            item.target.trim(),
            normalize(item.children ?? []),
        ]);

    return (
        JSON.stringify(normalize(items)) === JSON.stringify(normalize(saved))
    );
}

export type FoundItem = {
    item: MenuItem;
    parent: MenuItem | null;
    index: number;
};

export function findItem(
    items: MenuItem[],
    uid: string | null,
): FoundItem | null {
    if (!uid) {
        return null;
    }

    for (const [index, item] of items.entries()) {
        if (item.uid === uid) {
            return { item, parent: null, index };
        }

        const childIndex = item.children.findIndex(
            (child) => child.uid === uid,
        );

        if (childIndex >= 0) {
            return {
                item: item.children[childIndex],
                parent: item,
                index: childIndex,
            };
        }
    }

    return null;
}

export function updateItem(
    items: MenuItem[],
    uid: string,
    change: (item: MenuItem) => MenuItem,
): MenuItem[] {
    return items.map((item) =>
        item.uid === uid
            ? change(item)
            : {
                  ...item,
                  children: item.children.map((child) =>
                      child.uid === uid ? change(child) : child,
                  ),
              },
    );
}

export function removeItem(items: MenuItem[], uid: string): MenuItem[] {
    return items
        .filter((item) => item.uid !== uid)
        .map((item) => ({
            ...item,
            children: item.children.filter((child) => child.uid !== uid),
        }));
}

export function labelOf(item: MenuItem, locale: string): string {
    return (item.label[locale] ?? '').trim();
}

/** Quantos idiomas têm texto. */
export function filledLocales(item: MenuItem, locales: string[]): number {
    return locales.filter((locale) => labelOf(item, locale) !== '').length;
}

export function isComplaintsBook(item: MenuItem): boolean {
    return item.type === 'url' && item.target.includes('livroreclamacoes.pt');
}

export function hasComplaintsBook(items: MenuItem[]): boolean {
    return items.some(
        (item) =>
            isComplaintsBook(item) || item.children.some(isComplaintsBook),
    );
}

/** Para onde leva, em poucas palavras: "Catálogo", "etsy.com", "Email". */
export function destinationLabel(
    item: MenuItem,
    links: StoreLink[],
): string | null {
    const target = item.target.trim();

    if (target === '') {
        return null;
    }

    if (item.type === 'email') {
        return 'Email';
    }

    if (item.type === 'url') {
        if (isComplaintsBook(item)) {
            return 'Livro de Reclamações';
        }

        try {
            return new URL(target).host.replace(/^www\./, '') || target;
        } catch {
            return target;
        }
    }

    return links.find((link) => link.path === target)?.label ?? target;
}

/**
 * Passa os erros do servidor ("items.0.children.1.target") para os uids dos
 * itens que foram enviados. Devolve também o primeiro item com erro.
 */
export function mapErrors(
    errors: Record<string, string>,
    sent: MenuItem[],
): { errors: MenuErrors; first: string | null } {
    const byUid: MenuErrors['byUid'] = {};
    let top: string | undefined;
    let first: string | null = null;

    for (const [key, message] of Object.entries(errors)) {
        const match = /^items\.(\d+)(?:\.children\.(\d+))?\.(.+)$/.exec(key);
        const parent = match ? sent[Number(match[1])] : undefined;
        const item =
            match && match[2] !== undefined
                ? parent?.children[Number(match[2])]
                : parent;

        if (!match || !item) {
            top ??= message;
            continue;
        }

        byUid[item.uid] = { ...byUid[item.uid], [match[3]]: message };
        first ??= item.uid;
    }

    return { errors: { top, byUid }, first };
}

export function hasErrors(errors: MenuErrors, uid: string): boolean {
    return Object.keys(errors.byUid[uid] ?? {}).length > 0;
}
