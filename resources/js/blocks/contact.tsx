import { usePage } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { ContactDetails } from '@/components/store/contact-details';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { BlockSection } from './section';

type Data = {
    title: string;
    text: string;
    show_form: boolean;
    show_details: boolean;
    responseHours: number | null;
};

export function ContactBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const t = useT();
    const { storefront } = usePage().props;

    const hasDetails =
        data.show_details &&
        storefront !== undefined &&
        (Object.keys(storefront.contact).length > 0 ||
            storefront.social.length > 0);

    return (
        <BlockSection style={block.style}>
            <div
                className={cn(
                    'grid gap-10',
                    data.show_form &&
                        hasDetails &&
                        'md:grid-cols-[3fr_2fr] md:gap-16',
                )}
            >
                <div className="flex flex-col gap-4 text-left">
                    {data.title && (
                        <h2 className="text-2xl font-semibold tracking-tight text-balance md:text-3xl">
                            {data.title}
                        </h2>
                    )}
                    {data.text && (
                        <p className="max-w-xl text-base text-muted-foreground md:text-lg">
                            {data.text}
                        </p>
                    )}
                    {data.responseHours !== null && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Clock aria-hidden="true" className="size-4" />
                            {t.choice(
                                'contact.response_time',
                                data.responseHours,
                            )}
                        </p>
                    )}
                    {data.show_form && (
                        <p className="mt-2 rounded-lg bg-accent p-4 text-sm text-accent-foreground">
                            {t('contact.form_soon')}
                        </p>
                    )}
                </div>
                {hasDetails && storefront && (
                    <div className="rounded-xl border border-border/60 bg-card p-6 text-card-foreground">
                        <ContactDetails
                            contact={storefront.contact}
                            social={storefront.social}
                        />
                    </div>
                )}
            </div>
        </BlockSection>
    );
}
