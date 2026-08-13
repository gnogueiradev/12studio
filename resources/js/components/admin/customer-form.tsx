import { CustomerFields } from '@/components/admin/customer-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { CustomerFormData } from '@/types/customer';
import { isCustomerFormReady } from '@/types/customer';

type Props = {
    data: CustomerFormData;
    setData: <K extends keyof CustomerFormData>(
        key: K,
        value: CustomerFormData[K],
    ) => void;
    errors: Partial<Record<keyof CustomerFormData, string>>;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
    tagSuggestions?: string[];
};

/**
 * Os campos do cliente numa página. O modal da listagem usa os mesmos
 * `CustomerFields` com outro invólucro — o que muda entre os dois é a moldura
 * e o destino depois de gravar, nunca os campos.
 */
export default function CustomerForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
    tagSuggestions,
}: Props) {
    return (
        <form onSubmit={onSubmit} className="flex max-w-xl flex-col gap-6">
            <CustomerFields
                data={data}
                setData={setData}
                errors={errors}
                tagSuggestions={tagSuggestions}
            />

            <div>
                <Button
                    type="submit"
                    className="rounded-full"
                    disabled={processing || !isCustomerFormReady(data)}
                >
                    {processing && <Spinner />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
