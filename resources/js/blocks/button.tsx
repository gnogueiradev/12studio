import { cn } from '@/lib/utils';
import type { BlockLink, StoreBlock, StoreDevice } from '@/types/store';
import { LinkButton } from './link-button';
import { deviceValues } from './props';
import type { DeviceClasses } from './props';

type Data = { link: BlockLink | null };

const VARIANTS = {
    primary: 'default',
    outline: 'outline',
    link: 'link',
} as const;
const SIZES = { sm: 'sm', md: 'default', lg: 'lg' } as const;

const FULL: Record<string, DeviceClasses> = {
    on: ['w-full', 'md:w-full', 'lg:w-full'],
    off: ['w-auto', 'md:w-auto', 'lg:w-auto'],
};

/** Em que tamanhos aparece cada cópia, com o `inline-flex` do Button. */
const SHOWN_ON: Record<string, string> = {
    'desktop,tablet,mobile': '',
    desktop: 'hidden lg:inline-flex',
    tablet: 'hidden md:inline-flex lg:hidden',
    mobile: 'md:hidden',
    'desktop,tablet': 'hidden md:inline-flex',
    'tablet,mobile': 'lg:hidden',
    'desktop,mobile': 'md:hidden lg:inline-flex',
};

const DEVICES: StoreDevice[] = ['desktop', 'tablet', 'mobile'];

/**
 * O aspeto e o tamanho vêm das variantes do Button e não têm classes por
 * tamanho de ecrã: quando mudam entre dispositivos, há uma cópia do botão para
 * cada combinação e só a do tamanho atual fica à vista.
 */
export function ButtonBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.link) {
        return null;
    }

    const variants = deviceValues(block, 'variant', 'primary');
    const sizes = deviceValues(block, 'size', 'md');
    const full = deviceValues(block, 'full_width', false);
    const width = cn(
        FULL[full.mobile ? 'on' : 'off'][0],
        FULL[full.tablet ? 'on' : 'off'][1],
        FULL[full.desktop ? 'on' : 'off'][2],
    );
    const copies = new Map<string, StoreDevice[]>();

    for (const device of DEVICES) {
        const key = `${variants[device]}|${sizes[device]}`;
        copies.set(key, [...(copies.get(key) ?? []), device]);
    }

    return (
        <div className={width}>
            {[...copies].map(([key, devices]) => {
                const [variant, size] = key.split('|');

                return (
                    <LinkButton
                        key={key}
                        link={data.link}
                        variant={
                            VARIANTS[variant as keyof typeof VARIANTS] ??
                            'default'
                        }
                        size={SIZES[size as keyof typeof SIZES] ?? 'default'}
                        className={cn(width, SHOWN_ON[devices.join(',')])}
                    />
                );
            })}
        </div>
    );
}
