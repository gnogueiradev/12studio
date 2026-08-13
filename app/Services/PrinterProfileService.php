<?php

namespace App\Services;

use App\Models\PrinterProfile;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PrinterProfileService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): PrinterProfile
    {
        return DB::transaction(function () use ($data): PrinterProfile {
            $data = $this->normalizeRate($data);

            // A primeira impressora e a predefinida sem ninguem pedir: uma
            // calculadora sem impressora escolhida cai no custo/hora do config
            // e mostra um aviso, e ninguem percebia porque e que o aviso
            // aparecia depois de criar a unica impressora que tem.
            $makeDefault = ($data['is_default'] ?? false) || ! PrinterProfile::query()->exists();
            unset($data['is_default']);

            $profile = PrinterProfile::query()->create($data);

            if ($makeDefault) {
                $this->setDefault($profile);
            }

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PrinterProfile $profile, array $data): PrinterProfile
    {
        return DB::transaction(function () use ($profile, $data): PrinterProfile {
            $data = $this->normalizeRate($data);

            $makeDefault = (bool) ($data['is_default'] ?? false);
            unset($data['is_default']);

            $profile->update($data);

            if ($makeDefault) {
                $this->setDefault($profile);
            }

            return $profile->refresh();
        });
    }

    /**
     * A impressora predefinida e unica — ha um indice unico PARCIAL na tabela a
     * garanti-lo. Por isso a anterior tem de ser limpa ANTES de marcar a nova,
     * e as duas escritas tem de caber na mesma transacao: entre elas a base
     * fica sem predefinida nenhuma.
     */
    public function setDefault(PrinterProfile $profile): void
    {
        DB::transaction(function () use ($profile): void {
            PrinterProfile::query()
                ->where('is_default', true)
                ->whereKeyNot($profile->getKey())
                ->update(['is_default' => false]);

            // Uma impressora arquivada nao pode ser a predefinida: a
            // calculadora ficava a usar o custo/hora de uma maquina que ja nao
            // existe na oficina.
            $profile->update(['is_default' => true, 'active' => true]);
        });
    }

    /**
     * Regra global de eliminacao: nunca hard-delete. Arquivar uma impressora
     * tira-a do seletor; as variantes que ja a usam ficam intactas (a FK
     * `variants.printer_profile_id` e restrictOnDelete de proposito).
     */
    public function archive(PrinterProfile $profile): void
    {
        DB::transaction(function () use ($profile): void {
            $profile->update(['active' => false, 'is_default' => false]);

            // A oficina nao pode ficar sem predefinida: passa para a primeira
            // ativa que sobrar. Se nao sobrar nenhuma, a calculadora avisa e
            // cai no config — que e o comportamento correto para uma loja sem
            // impressoras configuradas.
            $this->promoteFallback();
        });
    }

    public function restore(PrinterProfile $profile): void
    {
        DB::transaction(function () use ($profile): void {
            $profile->update(['active' => true]);

            $this->promoteFallback();
        });
    }

    /**
     * A impressora a usar num calculo: a escolhida, senao a predefinida.
     * Devolve null quando nao ha nenhuma ativa — quem chama cai no
     * PricingSettings::fallbackHourlyRateCents() e avisa o utilizador.
     */
    public function resolve(?int $printerProfileId): ?PrinterProfile
    {
        if ($printerProfileId !== null) {
            $chosen = PrinterProfile::query()->find($printerProfileId);

            if ($chosen !== null && $chosen->active) {
                return $chosen;
            }
        }

        return $this->default();
    }

    public function default(): ?PrinterProfile
    {
        return PrinterProfile::query()
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();
    }

    /**
     * Garante que existe uma predefinida enquanto houver impressoras ativas.
     * Sem transacao propria: e sempre chamado de dentro de uma.
     */
    private function promoteFallback(): void
    {
        if (PrinterProfile::query()->where('active', true)->where('is_default', true)->exists()) {
            return;
        }

        $next = PrinterProfile::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();

        $next?->update(['is_default' => true]);
    }

    /**
     * Euros por hora do formulario -> centimos inteiros. Mesmo padrao do
     * MaterialService::normalizePrice().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeRate(array $data): array
    {
        if (array_key_exists('hourly_rate', $data)) {
            $data['hourly_rate_cents'] = Money::fromDecimal((string) $data['hourly_rate']);
            unset($data['hourly_rate']);
        }

        return $data;
    }
}
