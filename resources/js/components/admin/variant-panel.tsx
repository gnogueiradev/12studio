import { useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import VariantForm from '@/components/admin/variant-form';
import type { VariantPricingPreview } from '@/components/admin/variant-form';
import { Button } from '@/components/ui/button';
import type { ProductProduction } from '@/lib/production';
import { newVariantForm, variantFormFromRow } from '@/lib/variant-form-data';
import { store as storeVariant } from '@/routes/admin/produtos/variantes';
import { update as updateVariant } from '@/routes/admin/variantes';
import type {
    ColorOption,
    MaterialOption,
    VariantFormData,
    VariantRow,
} from '@/types/catalog';
import type { PrinterProfileOption } from '@/types/pricing';

type Props = {
    productId: number;
    productName: string;
    /** Null cria; uma variante da lista edita essa variante. */
    variant: VariantRow | null;
    suggestedSku: string;
    /**
     * O tempo e a gramagem do produto. Uma variante nova nasce com eles: a
     * peça é a mesma em todas, e a aba "Produção" escreve-os em todas de uma
     * vez — uma ficha a abrir vazia era a única que ficava fora dessa regra.
     */
    production: ProductProduction;
    colors: ColorOption[];
    materials: MaterialOption[];
    printers: PrinterProfileOption[];
    pricing: VariantPricingPreview;
    defaultActiveLaborMinutes: number;
    onClose: () => void;
};

/**
 * A ficha da variante, em gaveta dentro da secção "Variantes" da página do
 * produto.
 *
 * Não é um modal por cima: o que se edita pertence ao produto que está à
 * frente, e um overlay por cima de um formulário desta altura era perder o fio
 * ao sítio onde se estava.
 *
 * Não fecha nada ao gravar. O servidor responde `back()`, o Inertia remonta a
 * página do produto e o estado da gaveta nasce fechado — ou seja, aterra-se na
 * lista de variantes já com a nova lá dentro. Em erro de validação o Inertia
 * liga o `preserveState` sozinho e a ficha fica aberta com as mensagens.
 */
export function VariantPanel({
    productId,
    productName,
    variant,
    suggestedSku,
    production,
    colors,
    materials,
    printers,
    pricing,
    defaultActiveLaborMinutes,
    onClose,
}: Props) {
    const { data, setData, post, patch, processing, errors } =
        useForm<VariantFormData>(
            variant === null
                ? newVariantForm(suggestedSku, production)
                : variantFormFromRow(variant),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        // `preserveScroll` para a página não saltar para o topo quando a
        // validação recusa: o campo que falhou pode estar a meio de um
        // formulário com esta altura.
        if (variant === null) {
            post(storeVariant(productId).url, { preserveScroll: true });

            return;
        }

        patch(updateVariant(variant.id).url, { preserveScroll: true });
    };

    return (
        <>
            <div className="flex items-start gap-3.5 border-b border-border/60 bg-secondary/30 px-5 py-4">
                <div className="min-w-0 flex-1">
                    <h3 className="text-[15px] font-semibold">
                        {variant === null ? 'Nova variante' : variant.sku}
                    </h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {productName} — cada variante tem o seu SKU, preço e
                        stock próprios.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 flex-none"
                    onClick={onClose}
                    aria-label="Fechar a ficha da variante"
                >
                    <X className="size-4" />
                </Button>
            </div>

            <div className="p-6">
                <VariantForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel={
                        variant === null
                            ? 'Criar variante'
                            : 'Guardar alterações'
                    }
                    colors={colors}
                    materials={materials}
                    printers={printers}
                    pricing={pricing}
                    defaultActiveLaborMinutes={defaultActiveLaborMinutes}
                    reservedStock={variant?.reservedStock}
                />
            </div>
        </>
    );
}
