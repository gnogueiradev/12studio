<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Memoria dos alertas: o que ja foi avisado e quando, e marcas d'agua.
 *
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value', 'sent_at'])]
class AlertState extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Avisar agora? Sim se nunca avisou, ou se o ultimo aviso ja tem mais do
     * que o intervalo de lembrete. Quem diz sim marca logo o envio.
     */
    public static function claim(string $key): bool
    {
        $state = self::query()->find($key);
        $hours = (int) config('alerts.reminder_hours', 24);

        if ($state?->sent_at !== null && $state->sent_at->gt(now()->subHours($hours))) {
            return false;
        }

        self::query()->updateOrCreate(['key' => $key], ['sent_at' => now()]);

        return true;
    }

    /**
     * O problema passou: a proxima vez que voltar, volta a avisar logo.
     */
    public static function release(string $key): void
    {
        self::query()->whereKey($key)->delete();
    }

    /**
     * Chaves ainda marcadas com este prefixo (ex. "stale-payment:").
     *
     * @return array<int, string>
     */
    public static function keysWithPrefix(string $prefix): array
    {
        return self::query()
            ->where('key', 'like', $prefix.'%')
            ->pluck('key')
            ->all();
    }

    public static function watermark(string $key): ?string
    {
        return self::query()->find($key)?->value;
    }

    public static function setWatermark(string $key, string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
