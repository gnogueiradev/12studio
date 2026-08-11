import { Head, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/admin/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatCents } from '@/lib/money';
import { index, update } from '@/routes/admin/definicoes';

type Props = {
    settings: { currency: string };
    currencies: string[];
};

export default function SettingsIndex({ settings, currencies }: Props) {
    const { data, setData, patch, processing, errors } = useForm({
        currency: settings.currency,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update().url);
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
                    className="flex max-w-xl flex-col gap-6"
                >
                    <div className="grid gap-2">
                        <Label>Moeda</Label>
                        <Select
                            value={data.currency}
                            onValueChange={(value) =>
                                setData('currency', value)
                            }
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((code) => (
                                    <SelectItem key={code} value={code}>
                                        {code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                            Muda o símbolo e a formatação em toda a loja —{' '}
                            <strong className="text-foreground">
                                não converte valor nenhum
                            </strong>
                            . Um preço de {formatCents(2490)} passa a ser o
                            mesmo número na moeda nova.
                        </p>
                        <InputError message={errors.currency} />
                    </div>

                    <div>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Guardar
                        </Button>
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
