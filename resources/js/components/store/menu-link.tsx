import { Link } from '@inertiajs/react';
import type { AriaAttributes, ReactNode } from 'react';
import type { MenuItem } from '@/types/store';

type Props = {
    item: Pick<MenuItem, 'href' | 'external' | 'label'>;
    className?: string;
    onClick?: () => void;
    'aria-current'?: AriaAttributes['aria-current'];
    children?: ReactNode;
};

/**
 * Um link de menu da montra. Os internos navegam pelo Inertia (sem recarregar
 * a página); os externos e os mailto: são um <a> normal, e os externos abrem
 * noutro separador sem passar o `window.opener`.
 */
export function MenuLink({ item, children, ...props }: Props) {
    const content = children ?? item.label;

    if (item.external) {
        return (
            <a href={item.href} target="_blank" rel="noreferrer" {...props}>
                {content}
            </a>
        );
    }

    if (item.href.startsWith('mailto:')) {
        return (
            <a href={item.href} {...props}>
                {content}
            </a>
        );
    }

    return (
        <Link href={item.href} {...props}>
            {content}
        </Link>
    );
}
