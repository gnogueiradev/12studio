import { cn } from '@/lib/utils';
import type { BlockLink, StoreBlock } from '@/types/store';
import { LinkButton } from './link-button';
import { styleProp } from './props';

type Data = { link: BlockLink | null };

const VARIANTS = {
    primary: 'default',
    outline: 'outline',
    link: 'link',
} as const;
const SIZES = { sm: 'sm', md: 'default', lg: 'lg' } as const;

export function ButtonBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.link) {
        return null;
    }

    const variant = styleProp<string>(block, 'variant') ?? 'primary';
    const size = styleProp<string>(block, 'size') ?? 'md';
    const full = styleProp<boolean>(block, 'full_width') === true;

    return (
        <div className={cn(full && 'w-full')}>
            <LinkButton
                link={data.link}
                variant={
                    VARIANTS[variant as keyof typeof VARIANTS] ?? 'default'
                }
                size={SIZES[size as keyof typeof SIZES] ?? 'default'}
                className={cn(full && 'w-full')}
            />
        </div>
    );
}
