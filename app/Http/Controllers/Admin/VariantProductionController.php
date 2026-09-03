<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\Variant\UpdateVariantProductionRequest;
use App\Models\Product;
use App\Services\VariantProductionService;
use Illuminate\Http\RedirectResponse;

/**
 * A aba "Producao" do modal do produto: o tempo e a gramagem da peca, a
 * escrever de uma vez em todas as variantes, e um botao que escreve nelas os
 * precos calculados.
 *
 * Duas accoes e nao uma, porque sao decisoes diferentes: corrigir o que o
 * slicer disse nao e decidir que o preco muda. Ambas respondem com `back()`,
 * como as restantes accoes do modal — o `?editar={id}` no URL de origem e o
 * que reabre o produto certo.
 */
class VariantProductionController extends Controller
{
    public function __construct(
        private VariantProductionService $production,
    ) {}

    public function update(UpdateVariantProductionRequest $request, Product $product): RedirectResponse
    {
        $count = $this->production->updateProduction($product, $request->values());

        $this->toast($count === 1
            ? 'Tempo e gramagem guardados na variante.'
            : "Tempo e gramagem guardados nas {$count} variantes.");

        return back();
    }

    public function applyPrices(AdminActionRequest $request, Product $product): RedirectResponse
    {
        $outcome = $this->production->applyCalculatedPrices($product);

        $this->toast($this->summary($outcome['updated'], $outcome['skipped']), $outcome['updated'] === 0 ? 'error' : 'success');

        return back();
    }

    /**
     * O toast diz quantas foram e quantas ficaram: "aplicado" a secas
     * escondia as variantes a que faltava peso, tempo ou material.
     */
    private function summary(int $updated, int $skipped): string
    {
        if ($updated === 0) {
            return 'Nenhuma variante tem gramagem, tempo e material para calcular o preço.';
        }

        $applied = $updated === 1
            ? 'Preços aplicados a 1 variante.'
            : "Preços aplicados a {$updated} variantes.";

        if ($skipped === 0) {
            return $applied;
        }

        $left = $skipped === 1
            ? '1 ficou por calcular — falta gramagem, tempo ou material.'
            : "{$skipped} ficaram por calcular — falta gramagem, tempo ou material.";

        return "{$applied} {$left}";
    }
}
