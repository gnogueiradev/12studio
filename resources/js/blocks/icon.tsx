import type { CSSProperties } from 'react';
import { MenuLink } from '@/components/store/menu-link';
import { cn } from '@/lib/utils';
import type { BlockLink, StoreBlock } from '@/types/store';
import { BlockIcon } from './icons';
import { styleProp, TEXT_COLORS } from './props';

type Data = { icon: string; link: BlockLink | null };

export function IconBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const size = styleProp<number>(block, 'size') ?? 40;
    const color = styleProp<string>(block, 'color') ?? 'gold';

    const icon = (
        <BlockIcon
            name={data.icon}
            className={cn(
                'size-[var(--icon-size)]',
                TEXT_COLORS[color] ?? TEXT_COLORS.gold,
            )}
            style={{ '--icon-size': `${size}px` } as CSSProperties}
        />
    );

    return data.link ? (
        <MenuLink
            item={data.link}
            aria-label={data.link.label}
            className="inline-flex"
        >
            {icon}
        </MenuLink>
    ) : (
        icon
    );
}
