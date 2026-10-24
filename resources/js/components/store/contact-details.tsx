import { Clock, Mail, MapPin, Phone } from 'lucide-react';
import type { ReactNode } from 'react';
import { SocialIcon } from '@/components/store/social-icon';
import { useT } from '@/lib/i18n';
import type { Storefront } from '@/types/store';

function Row({
    icon,
    label,
    children,
}: {
    icon: ReactNode;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex gap-3 text-left">
            <span className="mt-0.5 text-muted-foreground">{icon}</span>
            <div className="grid gap-0.5">
                <span className="text-xs text-muted-foreground">{label}</span>
                <span className="text-sm">{children}</span>
            </div>
        </div>
    );
}

/**
 * Email, telefone, horário, morada e redes da loja (Definições → Montra). Só
 * mostra o que está preenchido. Usado no painel "Fala connosco" e no bloco de
 * contacto.
 */
export function ContactDetails({
    contact,
    social,
}: Pick<Storefront, 'contact' | 'social'>) {
    const t = useT();

    return (
        <div className="flex flex-col gap-6">
            <div className="grid gap-4">
                {contact.email && (
                    <Row
                        icon={<Mail aria-hidden="true" className="size-4" />}
                        label={t('contact.email')}
                    >
                        <a
                            href={`mailto:${contact.email}`}
                            className="underline-offset-4 hover:underline"
                        >
                            {contact.email}
                        </a>
                    </Row>
                )}
                {contact.phone && (
                    <Row
                        icon={<Phone aria-hidden="true" className="size-4" />}
                        label={t('contact.phone')}
                    >
                        <a
                            href={`tel:${contact.phone.replace(/[^\d+]/g, '')}`}
                            className="underline-offset-4 hover:underline"
                        >
                            {contact.phone}
                        </a>
                    </Row>
                )}
                {contact.hours && (
                    <Row
                        icon={<Clock aria-hidden="true" className="size-4" />}
                        label={t('contact.hours')}
                    >
                        {contact.hours}
                    </Row>
                )}
                {contact.address && (
                    <Row
                        icon={<MapPin aria-hidden="true" className="size-4" />}
                        label={t('contact.address')}
                    >
                        {contact.address}
                    </Row>
                )}
            </div>

            {social.length > 0 && (
                <div className="grid gap-3 text-left">
                    <span className="text-xs text-muted-foreground">
                        {t('contact.social')}
                    </span>
                    <ul className="flex flex-wrap gap-2">
                        {social.map((link) => (
                            <li key={link.network}>
                                <a
                                    href={link.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="flex items-center gap-2 rounded-full border border-border px-3 py-1.5 text-sm transition-colors hover:border-foreground/40"
                                >
                                    <SocialIcon
                                        network={link.network}
                                        className="size-4"
                                    />
                                    {link.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
