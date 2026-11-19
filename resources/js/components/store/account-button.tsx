import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLocalizedUrl, useT } from '@/lib/i18n';

/**
 * A conta no cabeçalho, ao lado do carrinho: com sessão leva a /conta, sem
 * ela a /entrar. O nome diz para onde vai, porque o ícone sozinho não diz.
 */
export function AccountButton({ signedIn }: { signedIn: boolean }) {
    const t = useT();
    const url = useLocalizedUrl();

    return (
        <Button asChild variant="ghost" size="icon">
            <Link
                href={url(signedIn ? '/conta' : '/entrar')}
                aria-label={t(
                    signedIn ? 'account.title' : 'account.login_title',
                )}
            >
                <User aria-hidden="true" />
            </Link>
        </Button>
    );
}
