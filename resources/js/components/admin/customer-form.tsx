import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { CustomerFormData } from '@/types/customer';

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
};

export default function CustomerForm({
    data,
    setData,
    errors,
    processing,
    onSubmit,
    submitLabel,
}: Props) {
    return (
        <form onSubmit={onSubmit} className="flex max-w-xl flex-col gap-6">
            <div className="grid gap-2">
                <Label htmlFor="name">Nome</Label>
                <Input
                    id="name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    required
                    autoFocus
                    maxLength={120}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(event) =>
                            setData('email', event.target.value)
                        }
                        required
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="phone">Telefone</Label>
                    <Input
                        id="phone"
                        value={data.phone}
                        onChange={(event) =>
                            setData('phone', event.target.value)
                        }
                        maxLength={30}
                    />
                    <InputError message={errors.phone} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="line1">Morada</Label>
                <Input
                    id="line1"
                    value={data.line1}
                    onChange={(event) => setData('line1', event.target.value)}
                    required
                    maxLength={190}
                />
                <InputError message={errors.line1} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="line2">Complemento</Label>
                <Input
                    id="line2"
                    value={data.line2}
                    onChange={(event) => setData('line2', event.target.value)}
                    maxLength={190}
                    placeholder="Andar, porta, referência"
                />
                <InputError message={errors.line2} />
            </div>

            <div className="grid grid-cols-3 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="postal_code">Código postal</Label>
                    <Input
                        id="postal_code"
                        value={data.postal_code}
                        onChange={(event) =>
                            setData('postal_code', event.target.value)
                        }
                        required
                        placeholder="1234-567"
                        maxLength={8}
                    />
                    <InputError message={errors.postal_code} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="city">Localidade</Label>
                    <Input
                        id="city"
                        value={data.city}
                        onChange={(event) =>
                            setData('city', event.target.value)
                        }
                        required
                        maxLength={80}
                    />
                    <InputError message={errors.city} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="country">País</Label>
                    <Input
                        id="country"
                        value={data.country}
                        onChange={(event) =>
                            setData('country', event.target.value.toUpperCase())
                        }
                        required
                        maxLength={2}
                    />
                    <InputError message={errors.country} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="nif">NIF</Label>
                <Input
                    id="nif"
                    value={data.nif}
                    onChange={(event) => setData('nif', event.target.value)}
                    maxLength={20}
                    className="max-w-48"
                />
                <p className="text-xs text-muted-foreground">
                    Necessário para emitir fatura com contribuinte.
                </p>
                <InputError message={errors.nif} />
            </div>

            <div>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
