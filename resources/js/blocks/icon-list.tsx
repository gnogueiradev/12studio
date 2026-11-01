import { MenuLink } from '@/components/store/menu-link';
import { cn } from '@/lib/utils';
import type { BlockLink, StoreBlock } from '@/types/store';
import { BlockIcon } from './icons';
import { deviceClasses, deviceVars, TEXT_COLORS } from './props';

type Data = {
    items: { icon: string; text: string; link: BlockLink | null }[];
};

export function IconListBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (data.items.length === 0) {
        return null;
    }

    const color = deviceClasses(block, 'color', TEXT_COLORS, 'gold');

    return (
        <ul
            className="flex flex-col gap-[var(--list-gap-m)] md:gap-[var(--list-gap-t)] lg:gap-[var(--list-gap-d)]"
            style={deviceVars(block, 'gap', 'list-gap', 12, 'px')}
        >
            {data.items.map((item, index) => (
                <li key={index} className="flex items-start gap-3">
                    <BlockIcon
                        name={item.icon}
                        className={cn('mt-0.5 size-5 shrink-0', color)}
                    />
                    {item.link ? (
                        <MenuLink item={item.link} className="hover:underline">
                            {item.text || item.link.label}
                        </MenuLink>
                    ) : (
                        <span>{item.text}</span>
                    )}
                </li>
            ))}
        </ul>
    );
}
