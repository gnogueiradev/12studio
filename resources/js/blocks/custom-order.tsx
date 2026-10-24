import { usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { StoreBlock } from '@/types/store';
import { BlockSection } from './section';

type Data = { title: string; text: string };

/**
 * O pedido de peça personalizada. O formulário em 3 passos (com ficheiros)
 * chega com as conversas; até lá, o bloco aponta para o email da loja.
 */
export function CustomOrderBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const t = useT();
    const email = usePage().props.storefront?.contact.email;

    return (
        <BlockSection style={block.style} id="pedido">
            <div className="mx-auto max-w-2xl rounded-xl border border-border/60 bg-card p-8 text-center text-card-foreground md:p-12">
                {data.title && (
                    <h2 className="text-2xl font-semibold tracking-tight text-balance md:text-3xl">
                        {data.title}
                    </h2>
                )}
                {data.text && (
                    <p className="mt-3 text-base text-muted-foreground">
                        {data.text}
                    </p>
                )}
                <p className="mt-6 rounded-lg bg-accent p-4 text-sm text-accent-foreground">
                    {t('contact.form_soon')}
                </p>
                {email && (
                    <Button asChild size="lg" className="mt-6">
                        <a href={`mailto:${email}`}>
                            <Mail aria-hidden="true" />
                            {email}
                        </a>
                    </Button>
                )}
            </div>
        </BlockSection>
    );
}
