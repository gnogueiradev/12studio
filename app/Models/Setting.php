<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * Convencao qrcode: const arrays em vez de PHP enums. Mudar a moeda muda
     * o simbolo e a formatacao — nao converte valores nenhuns.
     *
     * Mapa codigo => nome e nao lista de codigos: o select das definicoes
     * mostra "EUR — Euro", e um "BRL" sozinho nao diz nada a quem nunca
     * negociou em reais. A chave continua a ser a autoridade da validacao.
     */
    public const CURRENCIES = [
        'EUR' => 'Euro',
        'USD' => 'Dólar',
        'GBP' => 'Libra',
        'BRL' => 'Real',
        'CHF' => 'Franco suíço',
    ];

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
