import { MenuLink } from '@/components/store/menu-link';
import { Button } from '@/components/ui/button';
import type { BlockLink } from '@/types/store';

/** O botão de um bloco: interno pelo Inertia, externo noutro separador. */
export function LinkButton({
    link,
    variant = 'default',
    size = 'lg',
    className,
}: {
    link: BlockLink | null | undefined;
    variant?: 'default' | 'outline' | 'secondary' | 'ghost' | 'link';
    size?: 'default' | 'sm' | 'lg';
    className?: string;
}) {
    if (!link) {
        return null;
    }

    return (
        <Button asChild variant={variant} size={size} className={className}>
            <MenuLink item={link} />
        </Button>
    );
}
