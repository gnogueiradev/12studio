<?php

namespace App\Models;

use App\Support\Micros;
use Database\Factories\PrinterProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma impressora e os numeros de que o seu custo/hora se faz.
 *
 * Ja aqui viveu um `hourly_rate_cents` unico a cobrir energia, desgaste,
 * manutencao e depreciacao. Calculava bem e explicava mal: nao dizia quanto e
 * que cada coisa pesava, e mudar de tarifa eletrica ou amortizar a maquina
 * mais depressa obrigava a reinterpretar o agregado. Agora as tres parcelas
 * de maquina saem daqui — potencia, compra a dividir pela vida util,
 * manutencao — e a quarta variavel, a tarifa, e global (vive nas definicoes,
 * porque o contrato da luz nao e uma propriedade da impressora).
 *
 * Uma A1 e uma P1S continuam a nao custar o mesmo por hora, e e por isso que
 * isto continua a ser uma tabela e nao uma definicao unica.
 *
 * @property int $id
 * @property string $name
 * @property int $average_power_watts
 * @property int $purchase_price_cents
 * @property int $lifetime_hours
 * @property int $maintenance_micros_per_hour
 * @property string|null $notes
 * @property bool $is_default
 * @property bool $active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Variant> $variants
 */
#[Fillable([
    'name',
    'average_power_watts',
    'purchase_price_cents',
    'lifetime_hours',
    'maintenance_micros_per_hour',
    'notes',
    'is_default',
    'active',
    'sort_order',
])]
class PrinterProfile extends Model
{
    /** @use HasFactory<PrinterProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'average_power_watts' => 'integer',
            'purchase_price_cents' => 'integer',
            'lifetime_hours' => 'integer',
            'maintenance_micros_per_hour' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Variant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /**
     * Metodo e nao acessor, como Color::effectivePricePerKgCents(): e derivado,
     * nao um atributo, e nao tem de aparecer no toArray().
     */
    public function state(): string
    {
        return $this->active ? 'active' : 'archived';
    }

    /**
     * Quanto custa esta maquina por hora, em micros: energia + depreciacao +
     * manutencao. A tarifa entra por argumento porque e global e o modelo nao
     * tem — nem deve ter — acesso as definicoes.
     *
     * PARA MOSTRAR, nao para calcular. O PricingCalculator nao chama isto: ele
     * parte dos MINUTOS e faz uma divisao so por parcela. Passar por um custo/
     * hora intermedio era arredondar a meio — uma maquina de 3000 h de vida
     * util da 133_333,33 micros/h de depreciacao, e o terco perdido reaparecia
     * multiplicado pelas horas da peca.
     */
    public function hourlyCostMicros(int $electricityPriceMicrosPerKwh): int
    {
        // Uma hora a W watts sao W/1000 kWh.
        $electricity = Micros::divRound($this->average_power_watts * $electricityPriceMicrosPerKwh, 1000);

        $depreciation = $this->lifetime_hours <= 0
            ? 0
            : Micros::divRound($this->purchase_price_cents * Micros::PER_CENT, $this->lifetime_hours);

        return $electricity + $depreciation + $this->maintenance_micros_per_hour;
    }
}
