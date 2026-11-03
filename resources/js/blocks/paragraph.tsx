import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { deviceClasses, TEXT_COLORS } from './props';
import type { DeviceClasses } from './props';
import { RichText } from './text';

type Data = { body: string };

const SIZES: Record<string, DeviceClasses> = {
    sm: ['text-sm', 'md:text-sm', 'lg:text-sm'],
    md: ['text-base', 'md:text-base', 'lg:text-base'],
    lg: ['text-lg', 'md:text-lg', 'lg:text-lg'],
};

export function ParagraphBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.body) {
        return null;
    }

    return (
        <RichText
            html={data.body}
            className={cn(
                deviceClasses(block, 'size', SIZES, 'md'),
                deviceClasses(block, 'color', TEXT_COLORS, 'default'),
            )}
        />
    );
}
