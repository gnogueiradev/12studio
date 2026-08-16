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
     * O custo/hora vai JA DERIVADO (energia + depreciacao + manutencao) e nao
     * as quatro colunas: o seletor so quer um numero para por ao lado do nome,
     * e a tarifa — que e global — nao tem de viajar ate ao browser para la ser
     * multiplicada. O calculo a serio nao passa por este valor; ver
     * PrinterProfile::hourlyCostMicros().
     *
     * @return array<int, array{id: int, name: string, hourlyCostMicros: int, isDefault: bool}>
     */
    public static function all(int $electricityPriceMicrosPerKwh): array
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
                'hourlyCostMicros' => $profile->hourlyCostMicros($electricityPriceMicrosPerKwh),
                'isDefault' => $profile->is_default,
            ])
            ->all();
    }
}
