import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { CustomerFormData } from '@/types/customer';
import { CUSTOMER_TYPES } from '@/types/customer';

type Props = {
    data: CustomerFormData;
    setData: <K extends keyof CustomerFormData>(
        key: K,
        value: CustomerFormData[K],
    ) => void;
    errors: Partial<Record<keyof CustomerFormData, string>>;
};

/**
 * Os campos de um cliente, partilhados pelo modal da listagem e pelas páginas
 * de criar e editar. Só o nome é obrigatório: o admin apanha um cliente ao
 * balcão com um nome e mais nada, e obrigá-lo a inventar um email era encher
 * de lixo a tabela que a Fase 5 vai usar para o login.
 *
 * O país não aparece: a validação do código postal é o formato PT `1234-567`,
 * por isso um seletor de país era uma promessa que o formulário não cumpria.
 * O valor viaja no `data` a 'PT'.
 */
export function CustomerFields({ data, setData, errors }: Props) {
    const company = data.customer_type === 'empresa';

    // Aberto de início quando já há morada — a editar um cliente, escondê-la
    // atrás de um "+" era dar a entender que não existia.
    const [showAddress, setShowAddress] = useState(data.line1 !== '');

    return (
        <div className="flex flex-col gap-5">
            <div className="grid gap-2">
                <Label className="text-xs tracking-wider text-muted-foreground uppercase">
                    Tipo de cliente
                </Label>
                <ToggleGroup
                    type="single"
                    value={data.customer_type}
                    // O Radix devolve '' quando se clica no item já ativo; sem
                    // esta guarda o formulário ficava sem tipo nenhum.
                    onValueChange={(value) =>
                        value && setData('customer_type', value)
                    }
                    variant="outline"
                    className="w-fit"
                >
                    {CUSTOMER_TYPES.map((type) => (
                        <ToggleGroupItem
                            key={type.value}
                            value={type.value}
                            className="px-4 data-[state=on]:font-semibold"
                        >
                            {type.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                <InputError message={errors.customer_type} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="name">
                        {company ? 'Nome da empresa' : 'Nome completo'}
                    </Label>
                    <Input
                        id="name"
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        required
                        autoFocus
                        maxLength={120}
                        placeholder={company ? 'Café Bonjardim' : 'Ana Marques'}
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(event) =>
                            setData('email', event.target.value)
                        }
                        placeholder="nome@email.com"
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="phone">Telefone</Label>
                    <Input
                        id="phone"
                        type="tel"
                        value={data.phone}
                        onChange={(event) =>
                            setData('phone', event.target.value)
                        }
                        maxLength={30}
                        placeholder="+351 900 000 000"
                    />
                    <InputError message={errors.phone} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="nif">NIF</Label>
                    <Input
                        id="nif"
                        inputMode="numeric"
                        value={data.nif}
                        onChange={(event) => setData('nif', event.target.value)}
                        maxLength={20}
                        placeholder="000 000 000"
                        className="tabular-nums"
                    />
                    <p className="text-xs text-muted-foreground">
                        {company
                            ? 'Obrigatório para faturar a empresas.'
                            : 'Necessário para fatura com contribuinte.'}
                    </p>
                    <InputError message={errors.nif} />
                </div>
            </div>

            <div>
                <button
                    type="button"
                    onClick={() => setShowAddress((open) => !open)}
                    aria-expanded={showAddress}
                    className="flex items-center gap-2 text-sm font-semibold text-gold hover:underline"
                >
                    {showAddress ? (
                        <Minus className="size-3.5" />
                    ) : (
                        <Plus className="size-3.5" />
                    )}
                    {showAddress ? 'Esconder' : 'Adicionar'} morada de envio
                </button>

                {showAddress && (
                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="line1">Morada</Label>
                            <Input
                                id="line1"
                                value={data.line1}
                                onChange={(event) =>
                                    setData('line1', event.target.value)
                                }
                                maxLength={190}
                                placeholder="Rua, número, andar"
                            />
                            <InputError message={errors.line1} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="line2">Complemento</Label>
                            <Input
                                id="line2"
                                value={data.line2}
                                onChange={(event) =>
                                    setData('line2', event.target.value)
                                }
                                maxLength={190}
                                placeholder="Andar, porta, referência"
                            />
                            <InputError message={errors.line2} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="postal_code">Código postal</Label>
                            <Input
                                id="postal_code"
                                value={data.postal_code}
                                onChange={(event) =>
                                    setData('postal_code', event.target.value)
                                }
                                maxLength={8}
                                placeholder="0000-000"
                                className="tabular-nums"
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
                                maxLength={80}
                                placeholder="Porto"
                            />
                            <InputError message={errors.city} />
                        </div>
                    </div>
                )}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="admin_note">Notas internas</Label>
                <Textarea
                    id="admin_note"
                    value={data.admin_note}
                    onChange={(event) =>
                        setData('admin_note', event.target.value)
                    }
                    rows={2}
                    maxLength={2000}
                    placeholder="Prefere cores neutras, entrega em mão no Porto…"
                />
                <InputError message={errors.admin_note} />
            </div>
        </div>
    );
}
