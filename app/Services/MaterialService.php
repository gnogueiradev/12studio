<?php

namespace App\Services;

use App\Models\Material;
use App\Support\ColorPalette;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class MaterialService
{
    /**
     * As cores escolhidas no modal nascem com o material, e nao depois: um
     * material sem cor nenhuma nao gera variantes, por isso deixar o segundo
     * passo falhar sozinho era publicar um material inutil.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Material
    {
        // Resolver ANTES de abrir a transacao: e a leitura do catalogo que
        // decide se cada cor ja existe, e la dentro ela mudava a cada linha
        // inserida.
        $colors = ColorPalette::resolve($this->pullColors($data));

        return DB::transaction(function () use ($data, $colors): Material {
            $material = Material::query()->create($this->normalizePrice($data));

            foreach ($colors as $index => $color) {
                $material->colors()->create([
                    'name' => $color['name'],
                    'hex_color' => $color['hex'],
                    // Sem override: a cor herda o preco/kg do material, que e
                    // o que o modal acabou de pedir ao admin.
                    'price_per_kg_cents' => null,
                    'is_active' => true,
                    // A ordem por que o admin ligou as chips. O sort_order da
                    // linha irma noutro material nao servia: e um ordinal
                    // dentro desse material, e a primeira cor e 0 em toda a
                    // gente.
                    'sort_order' => $index,
                ]);
            }

            return $material;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Material $material, array $data): Material
    {
        $material->update($this->normalizePrice($data));

        return $material;
    }

    /**
     * Regra global de eliminacao: nunca hard-delete. Um material arquivado
     * sai do seletor da variante, mas as cores e as variantes que ja o usam
     * ficam intactas — a FK `variants.color_id` e restrictOnDelete de
     * proposito.
     */
    public function archive(Material $material): void
    {
        $material->update(['active' => false]);
    }

    /**
     * Ao contrario do produto — que volta como rascunho para nao reaparecer na
     * loja sem ninguem o rever — um material so tem duas posicoes. Restaurar e
     * voltar a poder escolhe-lo numa variante, e nada disso e visivel para o
     * cliente.
     */
    public function restore(Material $material): void
    {
        $material->update(['active' => true]);
    }

    /**
     * `colors` vem do formulario mas nao e coluna de `materials` — sai do
     * payload antes do create(), como o ProductService faz com as tags.
     *
     * Desduplica pelo nome, sem distinguir maiusculas, e reindexa: e o indice
     * do array que vira `sort_order`, por isso ele tem de ficar denso.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{name: string, hex: string}>
     */
    private function pullColors(array &$data): array
    {
        $colors = $data['colors'] ?? [];
        unset($data['colors']);

        if (! is_array($colors)) {
            return [];
        }

        $unique = [];

        foreach ($colors as $color) {
            $unique[mb_strtolower(trim($color['name']))] ??= [
                'name' => $color['name'],
                'hex' => $color['hex'],
            ];
        }

        return array_values($unique);
    }

    /**
     * Euros por kg do formulario -> centimos inteiros.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePrice(array $data): array
    {
        if (array_key_exists('price_per_kg', $data)) {
            $data['price_per_kg_cents'] = Money::fromDecimal((string) $data['price_per_kg']);
            unset($data['price_per_kg']);
        }

        return $data;
    }
}
