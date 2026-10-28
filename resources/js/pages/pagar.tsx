import { Form, Head, usePoll } from '@inertiajs/react';
import { CheckCircle2, Clock, LoaderCircle, XCircle } from 'lucide-react';
import { useEffect } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { pay } from '@/routes/payment-link';

/**
 * O link de pagamento que o dono manda ao cliente (PaymentLinkController).
 *
 * Autonoma, como a coming-soon: o cliente chega aqui de uma conversa de
 * Instagram e so precisa do resumo e do botao. Nada de dados pessoais — o
 * link anda por conversas.
 */

type Item = {
    name: string;
    label: string | null;
    qty: number;
    totalCents: number;
};

type Props = {
    order: {
        number: string;
        items: Item[];
        shippingCents: number;
        adjustmentCents: number;
        totalCents: number;
    };
    state: 'payable' | 'processing' | 'paid' | 'unavailable';
    returning: boolean;
    token: string;
};

export default function Pagar({ order, state, returning, token }: Props) {
    // A espera do aviso do Stripe: de 5 em 5 segundos pergunta-se so pelo
    // estado, ate ele mudar.
    const waiting =
        state === 'processing' || (state === 'payable' && returning);
    const { start, stop } = usePoll(
        5000,
        { only: ['state'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (waiting) {
            start();
        } else {
            stop();
        }

        return () => {
            stop();
        };
    }, [waiting, start, stop]);

    return (
        <>
            <Head title={`Encomenda ${order.number}`}>
                <meta name="robots" content="noindex" />
            </Head>

            <div className="flex min-h-dvh flex-col items-center bg-background px-4 py-10 text-foreground">
                <AppLogoIcon
                    aria-hidden="true"
                    className="mb-8 h-10 w-auto fill-current"
                />

                <main className="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-sm">
                    <h1 className="text-lg font-semibold">
                        Encomenda {order.number}
                    </h1>

                    <ul className="mt-4 divide-y divide-border text-sm">
                        {order.items.map((item, index) => (
                            <li
                                key={index}
                                className="flex justify-between gap-4 py-2"
                            >
                                <span>
                                    {item.qty}× {item.name}
                                    {item.label && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            ({item.label})
                                        </span>
                                    )}
                                </span>
                                <span className="tabular-nums">
                                    {formatCents(item.totalCents)}
                                </span>
                            </li>
                        ))}

                        {order.shippingCents > 0 && (
                            <li className="flex justify-between gap-4 py-2">
                                <span>Portes</span>
                                <span className="tabular-nums">
                                    {formatCents(order.shippingCents)}
                                </span>
                            </li>
                        )}

                        {order.adjustmentCents !== 0 && (
                            <li className="flex justify-between gap-4 py-2">
                                <span>
                                    {order.adjustmentCents < 0
                                        ? 'Desconto'
                                        : 'Ajuste'}
                                </span>
                                <span className="tabular-nums">
                                    {formatCents(order.adjustmentCents)}
                                </span>
                            </li>
                        )}
                    </ul>

                    <p className="mt-2 flex justify-between border-t border-border pt-3 font-semibold">
                        <span>Total</span>
                        <span className="tabular-nums">
                            {formatCents(order.totalCents)}
                        </span>
                    </p>

                    <div className="mt-6">
                        <Status state={state} returning={returning} />

                        {state === 'payable' && !returning && (
                            <Form {...pay.form(token)}>
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            type="submit"
                                            className="w-full"
                                            disabled={processing}
                                        >
                                            {processing && (
                                                <LoaderCircle className="animate-spin" />
                                            )}
                                            Pagar{' '}
                                            {formatCents(order.totalCents)}
                                        </Button>
                                        <InputError
                                            className="mt-2"
                                            message={errors.payment}
                                        />
                                        <p className="mt-3 text-center text-xs text-muted-foreground">
                                            O pagamento é feito na página segura
                                            do Stripe.
                                        </p>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                </main>
            </div>
        </>
    );
}

function Status({
    state,
    returning,
}: {
    state: Props['state'];
    returning: boolean;
}) {
    if (state === 'paid') {
        return (
            <Message icon={<CheckCircle2 className="text-success" />}>
                Esta encomenda está paga. Obrigado!
            </Message>
        );
    }

    if (state === 'unavailable') {
        return (
            <Message icon={<XCircle className="text-muted-foreground" />}>
                Esta encomenda já não está disponível para pagamento.
            </Message>
        );
    }

    // De volta do Stripe antes de o aviso do pagamento chegar, ou um
    // pagamento que demora (uma referência Multibanco por pagar).
    if (state === 'processing' || returning) {
        return (
            <Message icon={<Clock className="text-muted-foreground" />}>
                Obrigado! Estamos a confirmar o pagamento — esta página atualiza
                quando ele chegar.
            </Message>
        );
    }

    return null;
}

function Message({
    icon,
    children,
}: {
    icon: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <p className="flex items-start gap-3 text-sm" role="status">
            <span className="mt-0.5 shrink-0 [&>svg]:size-5">{icon}</span>
            <span>{children}</span>
        </p>
    );
}
