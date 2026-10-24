import {
    AtSign,
    Facebook,
    Instagram,
    Music2,
    Pin,
    Store,
    Youtube,
} from 'lucide-react';
import type { LucideProps } from 'lucide-react';

/**
 * O ícone de cada rede (StoreSettings::SOCIAL_NETWORKS). O TikTok e o
 * Pinterest não têm logótipo no lucide: ficam com uma nota musical e um pin,
 * e o nome da rede vai sempre no aria-label de quem usa o ícone.
 */
const ICONS = {
    instagram: Instagram,
    tiktok: Music2,
    facebook: Facebook,
    pinterest: Pin,
    youtube: Youtube,
    etsy: Store,
} as const;

export function SocialIcon({
    network,
    ...props
}: LucideProps & { network: string }) {
    const Icon = ICONS[network as keyof typeof ICONS] ?? AtSign;

    return <Icon aria-hidden="true" {...props} />;
}
