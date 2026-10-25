import { Link, usePage } from '@inertiajs/react';
import { Clock, MessageCircle } from 'lucide-react';
import { ContactDetails } from '@/components/store/contact-details';
import { ConversationForm } from '@/components/store/conversation-form';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Storefront } from '@/types/store';

/**
 * O botão "Fala connosco", fixo no canto em todas as páginas da montra. Abre
 * um painel com o formulário rápido (abre uma conversa; numa página de
 * produto, já vai com ele) e os contactos da loja.
 */
export function ContactFab({
    contact,
    social,
    className,
}: Pick<Storefront, 'contact' | 'social'> & { className?: string }) {
    const t = useT();
    const url = useLocalizedUrl();
    // Numa página de produto, a pergunta já vai com ele.
    const product =
        (
            usePage().props as {
                product?: { id: number; name: string };
            }
        ).product ?? null;

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button
                    className={cn(
                        'fixed right-4 bottom-4 z-40 h-12 gap-2 rounded-full px-4 shadow-lg md:right-6 md:bottom-6 md:px-5',
                        className,
                    )}
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
                    <ConversationForm
                        kind={product ? 'question' : 'contact'}
                        product={product}
                        idPrefix="fab"
                    />
                    <ContactDetails contact={contact} social={social} />
                    <Link
                        href={url('/conversa')}
                        className="text-sm text-muted-foreground underline"
                    >
                        {t('conversation.resend_link')}
                    </Link>
                </div>
            </SheetContent>
        </Sheet>
    );
}
