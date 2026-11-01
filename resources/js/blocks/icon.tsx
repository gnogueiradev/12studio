import { MenuLink } from '@/components/store/menu-link';
import { cn } from '@/lib/utils';
import type { BlockLink, StoreBlock } from '@/types/store';
import { BlockIcon } from './icons';
import { deviceClasses, deviceVars, TEXT_COLORS } from './props';

type Data = { icon: string; link: BlockLink | null };

export function IconBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    const icon = (
        <BlockIcon
            name={data.icon}
            className={cn(
                'size-[var(--icon-size-m)] md:size-[var(--icon-size-t)] lg:size-[var(--icon-size-d)]',
                deviceClasses(block, 'color', TEXT_COLORS, 'gold'),
            )}
            style={deviceVars(block, 'size', 'icon-size', 40, 'px')}
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
