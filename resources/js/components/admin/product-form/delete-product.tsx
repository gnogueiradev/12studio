import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { Button } from '@/components/ui/button';
import { apagar } from '@/routes/admin/produtos';

type Props = {
    productId: number;
    productName: string;
    /** O anúncio que o produto tem na Etsy, se tiver: vai atrás. */
    etsyListingId: number | null;
};

/**
 * Apagar não é arquivar. O arquivado continua na lista, com a sua chip — é o
 * produto que saiu de linha. O apagado sai de vista: o teste, o duplicado, o
 * engano. Fica guardado na mesma, e é por isso que o diálogo diz onde o ir
 * buscar em vez de avisar que não há volta.
 */
export function DeleteProduct({
    productId,
    productName,
    etsyListingId,
}: Props) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <section className="flex flex-col gap-2.5 rounded-xl border border-border/60 bg-card px-4 py-4">
            <h2 className="text-[13.5px] font-semibold">Apagar</h2>
            <p className="text-xs text-pretty text-muted-foreground">
                Tira o produto da lista e da loja. As encomendas e o histórico
                de stock ficam, e recupera-se em "Apagados".
            </p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start"
                onClick={() => setConfirming(true)}
            >
                Apagar produto
            </Button>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Apagar "${productName}"?`}
                description={
                    <>
                        <p>
                            O produto deixa de aparecer na lista, na loja e nas
                            encomendas novas. As encomendas que já o têm e o
                            histórico de stock não mudam.
                        </p>
                        <p className="mt-2">
                            Fica guardado: encontra-lo em "Apagados", no fundo
                            da lista de produtos, e volta como rascunho.
                        </p>
                        {etsyListingId !== null && (
                            <p className="mt-2 font-medium text-destructive">
                                O anúncio que tem na Etsy ({etsyListingId}) é
                                apagado também, e esse não se recupera:
                                recuperar o produto não o traz de volta.
                            </p>
                        )}
                    </>
                }
                confirmLabel="Apagar produto"
                destructive
                processing={processing}
                onConfirm={() => {
                    setProcessing(true);
                    router.delete(apagar(productId).url, {
                        onFinish: () => {
                            setProcessing(false);
                            setConfirming(false);
                        },
                    });
                }}
            />
        </section>
    );
}
