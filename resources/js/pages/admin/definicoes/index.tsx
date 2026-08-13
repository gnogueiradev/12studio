import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatCentsIn } from '@/lib/money';
import type { Option } from '@/lib/options';
import { cn } from '@/lib/utils';
import { index, update } from '@/routes/admin/definicoes';

type Props = {
    settings: { currency: string };
    currencies: Option[];
};

/**
 * O preço de exemplo da pré-visualização. Um valor com cêntimos e com
 * separador de milhares nenhum: chega para se ver o símbolo mudar de lado
 * (24,90 € → 24,90 £) sem distrair com um número grande.
 */
const EXAMPLE_CENTS = 2490;

export default function SettingsIndex({ settings, currencies }: Props) {
    const { data, setData, patch, processing, errors } = useForm({
        currency: settings.currency,
    });

    /**
     * Moeda da última gravação bem-sucedida, ou null. O toast do servidor
     * desaparece sozinho; esta linha fica, e é ela que diz em que é que a
     * loja ficou.
     */
    const [saved, setSaved] = useState<string | null>(null);

    const dirty = data.currency !== settings.currency;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update().url, { onSuccess: () => setSaved(data.currency) });
    };

    const status = saved
        ? {
              text: `Guardado. A loja passa a mostrar ${formatCentsIn(EXAMPLE_CENTS, saved)}.`,
              className: 'text-success',
          }
        : dirty
          ? { text: 'Alterações por guardar.', className: 'text-warning' }
          : {
                text: 'Sem alterações por guardar.',
                className: 'text-muted-foreground',
            };

    return (
        <>
            <Head title="Definições" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Definições"
                    description="O que podes mudar sem um novo deploy."
                />

                <form
                    onSubmit={submit}
                    className="flex max-w-2xl flex-col gap-4"
                >
                    <Panel
                        title="Moeda"
                        description={
                            <>
                                Muda o símbolo e a formatação em toda a loja —{' '}
                                <strong className="font-semibold text-foreground">
                                    não converte valor nenhum
                                </strong>
                                . Um preço de{' '}
                                {formatCentsIn(
                                    EXAMPLE_CENTS,
                                    settings.currency,
                                )}{' '}
                                passa a ser o mesmo número na moeda nova.
                            </>
                        }
                    >
                        <div className="flex flex-wrap items-center gap-4">
                            <Select
                                value={data.currency}
                                onValueChange={(value) => {
                                    setSaved(null);
                                    setData('currency', value);
                                }}
                            >
                                {/*
                                 * O campo não tem <Label> próprio: quem o
                                 * nomeia é o título do painel, e repeti-lo por
                                 * cima do select era dizer "Moeda" duas vezes
                                 * a dois centímetros de distância.
                                 */}
                                <SelectTrigger
                                    className="w-48"
                                    aria-label="Moeda"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {currencies.map((currency) => (
                                        <SelectItem
                                            key={currency.value}
                                            value={currency.value}
                                        >
                                            {currency.value} — {currency.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {/*
                             * `tabular-nums` para os dígitos não mudarem de
                             * largura ao saltar de moeda: sem isso a linha
                             * inteira dança a cada escolha do select.
                             */}
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <span className="tabular-nums">
                                    {formatCentsIn(
                                        EXAMPLE_CENTS,
                                        settings.currency,
                                    )}
                                </span>
                                <span aria-hidden="true">→</span>
                                <span className="sr-only">passa a</span>
                                <span className="font-semibold text-foreground tabular-nums">
                                    {formatCentsIn(
                                        EXAMPLE_CENTS,
                                        data.currency,
                                    )}
                                </span>
                            </p>
                        </div>

                        <InputError
                            message={errors.currency}
                            className="mt-3"
                        />
                    </Panel>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={!dirty || processing}>
                            {processing && <Spinner />}
                            Guardar
                        </Button>
                        {/*
                         * `role="status"` porque o botão passa a desativado sem
                         * dizer nada: é esta linha que anuncia a mudança a quem
                         * não a vê.
                         */}
                        <p
                            role="status"
                            className={cn('text-sm', status.className)}
                        >
                            {status.text}
                        </p>
                    </div>
                </form>
            </div>
        </>
    );
}

SettingsIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Definições', href: index() },
    ],
};
