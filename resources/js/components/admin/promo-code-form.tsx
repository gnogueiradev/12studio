import type { InertiaFormProps } from '@inertiajs/react';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { PromoCodeFormData } from '@/types/promo';
import { PROMO_CHANNELS, PROMO_TYPES } from '@/types/promo';

type Props = {
    form: InertiaFormProps<PromoCodeFormData>;
    /** Os canais que se podem escolher neste código. */
    channels: string[];
    onSubmit: (event: React.FormEvent) => void;
    submitLabel: string;
};

export function PromoCodeForm({
    form,
    channels,
    onSubmit,
    submitLabel,
}: Props) {
    const { data, setData, errors, processing } = form;

    return (
        <form onSubmit={onSubmit} className="flex max-w-3xl flex-col gap-4">
            <Panel title="Código">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="code">Código</Label>
                        <Input
                            id="code"
                            value={data.code}
                            onChange={(event) =>
                                setData(
                                    'code',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            maxLength={40}
                            required
                            className="font-mono"
                        />
                        <InputError message={errors.code} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Canal</Label>
                        <Select
                            value={data.channel}
                            onValueChange={(value) => setData('channel', value)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {PROMO_CHANNELS.filter((channel) =>
                                    channels.includes(channel.value),
                                ).map((channel) => (
                                    <SelectItem
                                        key={channel.value}
                                        value={channel.value}
                                    >
                                        {channel.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.channel} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Tipo</Label>
                        <Select
                            value={data.type}
                            onValueChange={(value) => setData('type', value)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {PROMO_TYPES.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.type} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="value">
                            {data.type === 'percent'
                                ? 'Percentagem (%)'
                                : 'Valor (€)'}
                        </Label>
                        <Input
                            id="value"
                            value={data.value}
                            onChange={(event) =>
                                setData('value', event.target.value)
                            }
                            inputMode="decimal"
                            required
                            className="tabular-nums"
                        />
                        <InputError message={errors.value} />
                    </div>
                </div>
            </Panel>

            <Panel title="Condições">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="min_subtotal">
                            Subtotal mínimo (€)
                        </Label>
                        <Input
                            id="min_subtotal"
                            value={data.min_subtotal}
                            onChange={(event) =>
                                setData('min_subtotal', event.target.value)
                            }
                            inputMode="decimal"
                            className="tabular-nums"
                        />
                        <InputError message={errors.min_subtotal} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="max_uses">Limite de usos</Label>
                        <Input
                            id="max_uses"
                            type="number"
                            min={1}
                            value={data.max_uses}
                            onChange={(event) =>
                                setData('max_uses', event.target.value)
                            }
                            placeholder="Ilimitado"
                            className="tabular-nums"
                        />
                        <InputError message={errors.max_uses} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="starts_at">Início</Label>
                        <Input
                            id="starts_at"
                            type="datetime-local"
                            value={data.starts_at}
                            onChange={(event) =>
                                setData('starts_at', event.target.value)
                            }
                        />
                        <InputError message={errors.starts_at} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="expires_at">Validade</Label>
                        <Input
                            id="expires_at"
                            type="datetime-local"
                            value={data.expires_at}
                            onChange={(event) =>
                                setData('expires_at', event.target.value)
                            }
                        />
                        <InputError message={errors.expires_at} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="email">
                            Email
                            <span className="font-normal text-muted-foreground">
                                (só nos códigos pessoais)
                            </span>
                        </Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            disabled={
                                data.channel === 'welcome' ||
                                data.channel === 'repeat'
                            }
                            onChange={(event) =>
                                setData('email', event.target.value)
                            }
                        />
                        <InputError message={errors.email} />
                    </div>

                    <Label className="font-normal">
                        <Checkbox
                            checked={data.single_use_per_email}
                            onCheckedChange={(checked) =>
                                setData(
                                    'single_use_per_email',
                                    checked === true,
                                )
                            }
                        />
                        Uma vez por email
                    </Label>

                    <Label className="font-normal">
                        <Checkbox
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked === true)
                            }
                        />
                        Ativo
                    </Label>
                </div>
            </Panel>

            <div>
                <Button
                    type="submit"
                    className="rounded-full"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
