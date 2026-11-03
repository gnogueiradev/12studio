import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocalizedUrl, useT } from '@/lib/i18n';

/**
 * Os erros da montra (StoreErrorPage) com o layout dela: quem chega a um link
 * partido continua na loja, com a pesquisa e o caminho de volta à mão.
 */
export default function StoreError({ status }: { status: 404 | 500 | 503 }) {
    const t = useT();
    const localized = useLocalizedUrl();
    const [query, setQuery] = useState('');

    return (
        <>
            <Head title={t(`error.title_${status}`)} />
            <section className="mx-auto flex w-full max-w-2xl flex-col items-center px-4 py-24 text-center md:py-32">
                <p className="text-sm font-medium tracking-[0.2em] text-gold-text">
                    {status}
                </p>
                <h1 className="mt-4 text-3xl font-semibold tracking-tight text-balance md:text-4xl">
                    {t(`error.title_${status}`)}
                </h1>
                <p className="mt-4 text-lg text-muted-foreground">
                    {t(`error.text_${status}`)}
                </p>

                {status === 404 && (
                    <form
                        role="search"
                        className="relative mt-8 w-full max-w-md"
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (query.trim()) {
                                router.get(localized('/produtos'), {
                                    q: query.trim(),
                                });
                            }
                        }}
                    >
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('catalog.search_placeholder')}
                            aria-label={t('catalog.search_placeholder')}
                            className="h-11 pl-9"
                        />
                    </form>
                )}

                <div className="mt-8 flex flex-wrap justify-center gap-3">
                    <Button asChild>
                        <Link href={localized('/')}>{t('error.home')}</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={localized('/produtos')}>
                            {t('error.catalog')}
                        </Link>
                    </Button>
                </div>
            </section>
        </>
    );
}
