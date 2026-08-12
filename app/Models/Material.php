<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $family
 * @property string|null $supplier
 * @property int $price_per_kg_cents
 * @property int $spools_in_stock
 * @property int $min_spools
 * @property bool $active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'family', 'supplier', 'price_per_kg_cents', 'spools_in_stock', 'min_spools', 'active', 'sort_order'])]
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    /**
     * Familias de polimero que imprimimos. Constante do modelo (padrao do
     * Product::STATUSES): e daqui que saem o Rule::in da validacao e as opcoes
     * do seletor, para nao haver duas listas a divergir.
     *
     * @var array<int, string>
     */
    public const FAMILIES = ['PLA', 'PETG', 'TPU', 'ABS', 'Resina'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_per_kg_cents' => 'integer',
            'spools_in_stock' => 'integer',
            'min_spools' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Minimo a zero e "sem alerta", nao "alerta sempre" — senao qualquer
     * material que ninguem configurou aparecia a amarelo.
     */
    public function isLowStock(): bool
    {
        return $this->min_spools > 0 && $this->spools_in_stock < $this->min_spools;
    }

    /**
     * Estado apresentavel do material. Deriva de duas colunas, e existe aqui
     * para so haver um sitio a decide-lo: a listagem pinta a pastilha, as chips
     * contam por ele e os cartoes de topo somam por ele.
     *
     * Arquivado ganha a stock baixo de proposito — um material que ja nao se
     * usa nao tem stock "em falta", tem stock a mais.
     */
    public function state(): string
    {
        if (! $this->active) {
            return 'archived';
        }

        return $this->isLowStock() ? 'low_stock' : 'active';
    }

    /** @return HasMany<Color, $this> */
    public function colors(): HasMany
    {
        return $this->hasMany(Color::class);
    }
}
