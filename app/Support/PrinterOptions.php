<?php

namespace App\Support;

use App\Models\PrinterProfile;

/**
 * As impressoras disponiveis, em lista plana.
 *
 * Irma do ColorOptions e do MaterialOptions, e pelo mesmo motivo: a calculadora
 * e o formulario da variante — que agora vive dentro do modal do produto —
 * pedem a mesma lista, e uma consulta duplicada eram duas oportunidades de a
 * ordem ou a regra de arquivamento divergirem.
 *
 * A predefinida vem primeiro: e a que o seletor mostra quando ninguem escolhe.
 */
class PrinterOptions
{
    /**
     * As arquivadas ficam de fora, sem excepcao. Ao contrario da cor e do
     * material, uma variante que aponte para uma impressora arquivada nao perde
     * nada ao ve-la desaparecer do seletor: o PrinterProfileService::resolve()
     * cai na predefinida e o painel de custo diz qual usou.
     *
     * @return array<int, array{id: int, name: string, hourlyRateCents: int, isDefault: bool}>
     */
    public static function all(): array
    {
        return PrinterProfile::query()
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (PrinterProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'hourlyRateCents' => $profile->hourly_rate_cents,
                'isDefault' => $profile->is_default,
            ])
            ->all();
    }
}
