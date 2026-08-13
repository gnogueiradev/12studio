<?php

namespace App\Models;

use Database\Factories\ColorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma cor e um nome e um tom. Nada mais.
 *
 * Nao tem material — o mesmo "Preto" imprime-se em PLA, PETG ou TPU — e nao tem
 * preco: quem custa dinheiro e a bobine, e isso vive no Material.
 *
 * @property int $id
 * @property string $name
 * @property string $hex_color
 * @property string|null $image
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'hex_color', 'image', 'is_active', 'sort_order'])]
class Color extends Model
{
    /** @use HasFactory<ColorFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Variant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /**
     * Estado apresentavel da cor. Vive aqui e nao no controlador pelo mesmo
     * motivo que o Material::state(): so ha um sitio a decidi-lo, e a listagem,
     * a pastilha e os cartoes de topo leem todos por ele.
     *
     * Duas posicoes so: uma cor nao tem stock. Quem fica sem bobines e o
     * material.
     */
    public function state(): string
    {
        return $this->is_active ? 'active' : 'archived';
    }
}
