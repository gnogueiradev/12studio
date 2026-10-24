import { Clock, Mail, MapPin, MessageCircle, Phone } from 'lucide-react';
import type { ReactNode } from 'react';
import { SocialIcon } from '@/components/store/social-icon';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
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
        <div className="flex gap-3">
            <span className="mt-0.5 text-muted-foreground">{icon}</span>
            <div className="grid gap-0.5">
                <span className="text-xs text-muted-foreground">{label}</span>
                <span className="text-sm">{children}</span>
            </div>
        </div>
    );
}

/**
 * O botão "Fala connosco", fixo no canto em todas as páginas da montra. Abre
 * um painel com os contactos da loja. O formulário que abre uma conversa
 * entra aqui na fase das conversas — até lá, o painel diz isso mesmo e mostra
 * o email e as redes.
 */
export function ContactFab({
    contact,
    social,
}: Pick<Storefront, 'contact' | 'social'>) {
    const t = useT();

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button
                    className="fixed right-4 bottom-4 z-40 h-12 gap-2 rounded-full px-4 shadow-lg md:right-6 md:bottom-6 md:px-5"
                    aria-label={t('contact.fab')}
                >
                    <MessageCircle aria-hidden="true" className="size-5" />
                    <span className="hidden sm:inline">{t('contact.fab')}</span>
                </Button>
            </SheetTrigger>
            <SheetContent className="store-theme flex w-full flex-col gap-0 bg-background text-foreground sm:max-w-md">
                <SheetHeader className="border-b border-border/60 p-6">
                    <SheetTitle className="text-xl">
                        {t('contact.title')}
                    </SheetTitle>
                    <SheetDescription>{t('contact.intro')}</SheetDescription>
                    {contact.responseHours !== undefined && (
                        <p className="flex items-center gap-2 pt-2 text-sm text-muted-foreground">
                            <Clock aria-hidden="true" className="size-4" />
                            {t.choice(
                                'contact.response_time',
                                contact.responseHours,
                            )}
                        </p>
                    )}
                </SheetHeader>

                <div className="flex flex-1 flex-col gap-6 overflow-y-auto p-6">
                    <p className="rounded-lg bg-accent p-4 text-sm text-accent-foreground">
                        {t('contact.form_soon')}
                    </p>

                    <div className="grid gap-4">
                        {contact.email && (
                            <Row
                                icon={
                                    <Mail
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                }
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
                                icon={
                                    <Phone
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                }
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
                                icon={
                                    <Clock
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                }
                                label={t('contact.hours')}
                            >
                                {contact.hours}
                            </Row>
                        )}
                        {contact.address && (
                            <Row
                                icon={
                                    <MapPin
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                }
                                label={t('contact.address')}
                            >
                                {contact.address}
                            </Row>
                        )}
                    </div>

                    {social.length > 0 && (
                        <div className="grid gap-3">
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
            </SheetContent>
        </Sheet>
    );
}
