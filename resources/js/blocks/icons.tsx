import {
    Box,
    Clock,
    Gift,
    Heart,
    Leaf,
    MapPin,
    MessageCircle,
    Package,
    Palette,
    Pencil,
    Printer,
    Recycle,
    Ruler,
    ShieldCheck,
    Sparkles,
    Star,
    Truck,
    Wrench,
} from 'lucide-react';
import type { LucideProps } from 'lucide-react';

/**
 * Os ícones que os blocos podem usar. A lista tem de bater certo com
 * App\PageBuilder\Field::ICONS — o BlockIconsTest compara as duas.
 */
export const BLOCK_ICONS = {
    sparkles: Sparkles,
    printer: Printer,
    package: Package,
    truck: Truck,
    heart: Heart,
    palette: Palette,
    ruler: Ruler,
    'message-circle': MessageCircle,
    'shield-check': ShieldCheck,
    leaf: Leaf,
    clock: Clock,
    gift: Gift,
    star: Star,
    wrench: Wrench,
    box: Box,
    'map-pin': MapPin,
    pencil: Pencil,
    recycle: Recycle,
} as const;

export function BlockIcon({ name, ...props }: LucideProps & { name: string }) {
    const Icon = BLOCK_ICONS[name as keyof typeof BLOCK_ICONS] ?? Sparkles;

    return <Icon aria-hidden="true" {...props} />;
}
