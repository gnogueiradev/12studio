<?php

namespace App\Models;

use Database\Factories\ColorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma cor e um nome, um tom, e as bobines em que existe.
 *
 * Nao tem preco: quem custa dinheiro e a bobine, e isso vive no Material.
 *
 * Tem materiais, mas em N:N (`materials()`), e a diferenca importa. Ate a
 * migracao 000070 a cor PERTENCIA a um material e havia um "Preto" por cada
 * filamento — cinco linhas para uma cor so, que a fusao da 000060 veio juntar.
 * O que voltou nao foi esse acoplamento: foi o facto de que o rosa existe em
 * PLA e em Matte e nao existe em Silk, e sem ele a matriz de criacao de
 * produtos inventava variantes que ninguem consegue imprimir.
 *
 * @property int $id
 * @property string $name
 * @property string $hex_color
 * @property string|null $image
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Material> $materials
 * @property-read int|null $materials_count
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
     * Em que filamentos e que esta cor existe.
     *
     * E daqui que sai a resposta a "posso fazer esta peca em rosa silk?". A
     * declaracao vive na cor e nao no produto porque a limitacao e do stock de
     * bobines, nao da peca: se nao ha rosa silk, nao ha para peca nenhuma, e
     * repetir isso em cada produto era esperar que os cem se lembrassem do
     * mesmo.
     *
     * @return BelongsToMany<Material, $this>
     */
    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Material::class);
    }

    /**
     * Estado apresentavel da cor. Vive aqui e nao no controlador pelo mesmo
     * motivo que o Material::state(): so ha um sitio a decidi-lo, e a listagem,
     * a pastilha e os cartoes de topo leem todos por ele.
     *
     * Tres posicoes, com a mesma forma do Material::state(). Continua sem
     * stock — quem fica sem bobines e o material —, mas ganhou o `no_material`:
     * uma cor que ainda nao diz em que filamentos existe nao gera variante
     * nenhuma, e tem de o dizer na listagem em vez de falhar em silencio na
     * matriz de criacao.
     *
     * Arquivada ganha a `no_material` pelo mesmo motivo que arquivado ganha a
     * stock baixo no Material: uma cor que ja nao se usa nao esta por
     * configurar, esta fora de servico.
     */
    public function state(): string
    {
        if (! $this->is_active) {
            return 'archived';
        }

        return $this->hasMaterials() ? 'active' : 'no_material';
    }

    /**
     * Lê da relacao ja carregada quando ela la esta.
     *
     * A listagem chama o `state()` uma vez por linha; sem este ramo, cada cor
     * do ecra era mais uma consulta. O `exists()` fica para quem tem uma cor
     * solta na mao e nao quer carregar a relacao so para a contar.
     */
    public function hasMaterials(): bool
    {
        if ($this->relationLoaded('materials')) {
            return $this->materials->isNotEmpty();
        }

        if ($this->materials_count !== null) {
            return (int) $this->materials_count > 0;
        }

        return $this->materials()->exists();
    }
}
