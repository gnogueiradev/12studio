<?php

namespace App\Models;

use Database\Factories\PrinterProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma impressora e o que custa te-la a trabalhar durante uma hora.
 *
 * O custo/hora nao e so eletricidade — cobre desgaste, consumiveis,
 * manutencao, depreciacao e o facto de a maquina estar ocupada. Uma A1 e uma
 * P1S nao custam o mesmo por hora, e e por isso que isto e uma tabela e nao
 * uma definicao unica.
 *
 * @property int $id
 * @property string $name
 * @property int $hourly_rate_cents
 * @property string|null $notes
 * @property bool $is_default
 * @property bool $active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Variant> $variants
 */
#[Fillable(['name', 'hourly_rate_cents', 'notes', 'is_default', 'active', 'sort_order'])]
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
            'hourly_rate_cents' => 'integer',
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
}
