import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { marca } from '@/routes/admin/definicoes';

type BrandForm = {
    name: string;
    tagline: string;
};

type Props = {
    brand: BrandForm;
};

/**
 * O nome da loja e a frase do rodapé. Tudo o que o cliente vê com o nome da
 * loja — cabeçalho da montra, título do separador, emails — lê daqui, e não de
 * um "12studio" escrito no código.
 */
export function BrandSettingsPanel({ brand }: Props) {
    const { data, setData, patch, processing, errors } = useForm<BrandForm>({
        ...brand,
    });

    const [saved, setSaved] = useState(false);

    const dirty = data.name !== brand.name || data.tagline !== brand.tagline;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(marca().url, { onSuccess: () => setSaved(true) });
    };

    const change = (key: keyof BrandForm, value: string) => {
        setSaved(false);
        setData((current) => ({ ...current, [key]: value }));
    };

    const status = saved
        ? { text: 'Guardado.', className: 'text-success' }
        : dirty
          ? { text: 'Alterações por guardar.', className: 'text-warning' }
          : {
                text: 'Sem alterações por guardar.',
                className: 'text-muted-foreground',
            };

    const year = new Date().getFullYear();

    return (
        <form onSubmit={submit} className="flex max-w-2xl flex-col gap-4">
            <Panel
                title="Identidade"
                description="O nome que aparece no cabeçalho da loja, no separador do browser e nos emails aos clientes."
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="brand_name">Nome da loja</Label>
                        <Input
                            id="brand_name"
                            value={data.name}
                            maxLength={40}
                            required
                            onChange={(event) =>
                                change('name', event.target.value)
                            }
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="brand_tagline">Frase do rodapé</Label>
                        <Input
                            id="brand_tagline"
                            value={data.tagline}
                            maxLength={120}
                            onChange={(event) =>
                                change('tagline', event.target.value)
                            }
                        />
                        <InputError message={errors.tagline} />
                    </div>
                </div>

                <p className="mt-4 text-sm text-muted-foreground">
                    No rodapé: © {year} {data.name || brand.name}
                    {data.tagline && ` — ${data.tagline}`}
                </p>
            </Panel>

            <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" disabled={!dirty || processing}>
                    {processing && <Spinner />}
                    Guardar
                </Button>
                <p role="status" className={cn('text-sm', status.className)}>
                    {status.text}
                </p>
            </div>
        </form>
    );
}
