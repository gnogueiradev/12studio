import { router } from '@inertiajs/react';
import { ShoppingBag } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { CartDrawer } from '@/components/store/cart-drawer';
import { QuantityStepper, useMaxQty } from '@/components/store/cart-lines';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CartData } from '@/types/shop';
import type { ProductDetailData, ProductVariantData } from '@/types/store';

/**
 * Comprar a variante escolhida: personalização, quantidade e "Adicionar ao
 * carrinho". Só aparece quando a loja vende e a variante se pode comprar —
 * quem decide isso é o product-main; aqui já se sabe que sim.
 *
 * O botão fica desligado enquanto o pedido corre: um duplo clique no
 * primeiro "Adicionar" criava dois carrinhos. Com sucesso, abre o painel com
 * o carrinho que veio no flash.
 */
export function BuyBox({
    product,
    variant,
    barInfo,
}: {
    product: ProductDetailData;
    variant: ProductVariantData;
    /** O nome e o preço da barra fixa do telemóvel (do product-main). */
    barInfo: ReactNode;
}) {
    const t = useT();
    const price = usePrice();
    const url = useLocalizedUrl();
    const formId = useId();
    const formRef = useRef<HTMLFormElement>(null);

    const [qty, setQty] = useState(1);
    const [values, setValues] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [cart, setCart] = useState<CartData | null>(null);
    const [drawerOpen, setDrawerOpen] = useState(false);

    const limit = useMaxQty();
    const max = variant.buy.maxQty ?? limit;
    // Trocar para uma variante com menos stock não deixa a quantidade acima
    // do teto novo.
    const quantity = Math.min(qty, Math.max(1, max));
    // Com encomenda, levar mais do que há pronto faz a linha toda por
    // encomenda (Buyable): diz-se antes de adicionar, não depois.
    const beyondReady =
        variant.buy.mode === 'in_stock' && quantity > variant.buy.ready;
    const fields = product.personalization?.fields ?? [];
    const surcharge = product.personalization?.surcharge ?? null;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (busy) {
            return;
        }

        setBusy(true);
        router.post(
            url('/carrinho/itens'),
            { variant_id: variant.id, qty: quantity, personalization: values },
            {
                preserveScroll: true,
                onStart: () => setErrors({}),
                onFinish: () => setBusy(false),
                onError: (next) => {
                    setErrors(next);
                    formRef.current?.scrollIntoView({
                        block: 'nearest',
                        behavior: 'smooth',
                    });
                },
                onSuccess: (page) => {
                    const flash = page.flash as { cart?: CartData };

                    if (flash.cart) {
                        setCart(flash.cart);
                        setDrawerOpen(true);
                        setQty(1);
                        setValues({});
                    }
                },
            },
        );
    };

    const id = (field: string) => `${formId}-${field}`;

    return (
        <>
            <form
                id={formId}
                ref={formRef}
                onSubmit={submit}
                noValidate
                className="flex flex-col gap-6"
            >
                {fields.length > 0 && (
                    <fieldset className="flex flex-col gap-4">
                        <legend className="mb-2.5 text-sm">
                            {t('cart.personalization')}
                            {surcharge !== null && surcharge > 0 && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    ·{' '}
                                    {t('cart.surcharge', {
                                        price: price(surcharge),
                                    })}
                                </span>
                            )}
                        </legend>
                        {fields.map((field) => {
                            const error =
                                errors[`personalization.${field.key}`];

                            return (
                                <div key={field.key} className="grid gap-1.5">
                                    <Label htmlFor={id(`p-${field.key}`)}>
                                        {field.label}
                                        {field.required && ' '}
                                        {field.required && (
                                            <span className="font-normal text-muted-foreground">
                                                ({t('cart.required')})
                                            </span>
                                        )}
                                    </Label>
                                    <Input
                                        id={id(`p-${field.key}`)}
                                        type={
                                            field.type === 'number'
                                                ? 'number'
                                                : 'text'
                                        }
                                        inputMode={
                                            field.type === 'number'
                                                ? 'numeric'
                                                : undefined
                                        }
                                        required={field.required}
                                        maxLength={field.maxLength ?? undefined}
                                        aria-invalid={error ? true : undefined}
                                        aria-describedby={
                                            field.maxLength
                                                ? id(`p-${field.key}-hint`)
                                                : undefined
                                        }
                                        className="h-11"
                                        value={values[field.key] ?? ''}
                                        onChange={(event) =>
                                            setValues({
                                                ...values,
                                                [field.key]: event.target.value,
                                            })
                                        }
                                    />
                                    {field.maxLength && (
                                        <p
                                            id={id(`p-${field.key}-hint`)}
                                            className="text-xs text-muted-foreground"
                                        >
                                            {t('cart.max_length', {
                                                max: field.maxLength,
                                            })}
                                        </p>
                                    )}
                                    <InputError message={error} />
                                </div>
                            );
                        })}
                    </fieldset>
                )}

                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <span className="text-sm" aria-hidden="true">
                            {t('cart.quantity')}
                        </span>
                        <QuantityStepper
                            value={quantity}
                            max={max}
                            onChange={setQty}
                            disabled={busy}
                            label={t('cart.quantity')}
                            className="h-12"
                        />
                        {variant.buy.mode === 'in_stock' &&
                            variant.buy.maxQty !== null &&
                            variant.buy.maxQty < limit && (
                                <span className="text-sm text-muted-foreground">
                                    {t('cart.only_left', {
                                        count: variant.buy.maxQty,
                                    })}
                                </span>
                            )}
                    </div>
                    {/* Sempre no DOM (vazio, fora do fluxo) para o leitor
                        de ecrã anunciar quando aparece. */}
                    <p
                        aria-live="polite"
                        className={cn(
                            'text-sm text-muted-foreground',
                            !beyondReady && 'sr-only',
                        )}
                    >
                        {beyondReady &&
                            t.choice('cart.beyond_ready', variant.buy.ready)}
                        {beyondReady &&
                            product.orderLeadDays &&
                            ` ${t('detail.lead.made_to_order', {
                                min: product.orderLeadDays[0],
                                max: product.orderLeadDays[1],
                            })}.`}
                    </p>
                    <InputError message={errors.qty} />

                    <Button
                        type="submit"
                        size="lg"
                        className="h-12 w-full text-base"
                        disabled={busy}
                    >
                        {busy ? (
                            <Spinner aria-hidden="true" />
                        ) : (
                            <ShoppingBag aria-hidden="true" />
                        )}
                        {busy ? t('cart.adding') : t('cart.add')}
                    </Button>
                    <InputError message={errors.variant_id ?? errors.cart} />
                </div>
            </form>

            {/* Telemóvel: o preço e o "Adicionar" ficam sempre à mão. O
                botão submete o mesmo formulário (atributo `form`). */}
            <div className="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur md:hidden">
                {barInfo}
                <Button type="submit" form={formId} disabled={busy}>
                    {busy ? (
                        <Spinner aria-hidden="true" />
                    ) : (
                        <ShoppingBag aria-hidden="true" />
                    )}
                    {t('cart.add_short')}
                </Button>
            </div>

            <CartDrawer
                open={drawerOpen}
                onOpenChange={setDrawerOpen}
                cart={cart}
                onCart={setCart}
            />
        </>
    );
}
