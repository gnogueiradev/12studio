<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Variant\StoreVariantRequest;
use App\Http\Requests\Variant\UpdateVariantRequest;
use App\Models\Product;
use App\Models\Variant;
use App\Services\VariantService;
use Illuminate\Http\RedirectResponse;

/**
 * Variantes vivem sempre no contexto de um produto (rota shallow): criar por
 * POST em /admin/produtos/{product}/variantes, editar e arquivar por
 * /admin/variantes/{variant}.
 *
 * Nao ha `create` nem `edit`: a ficha da variante vive dentro do modal do
 * produto, na listagem, como o proprio produto. Cor, material, gramagem, tempo
 * de impressao, impressora e custos extra escrevem-se todos lá — e o painel de
 * custo que os acompanha vem da prop `pricing` do ProductController::index,
 * calculada pelo MESMO motor que calcula o preco gravado.
 *
 * Daí que as tres accoes que sobram respondam com `back()`: disparam-se todas
 * de dentro do modal, e voltar ao endereco de onde se veio guarda a pagina, os
 * filtros, a pesquisa e o `?editar={id}` que reabre o produto certo.
 */
class VariantController extends Controller
{
    public function __construct(
        private VariantService $variantService,
    ) {}

    public function store(StoreVariantRequest $request, Product $product): RedirectResponse
    {
        $this->variantService->store($product, $request->validated(), $request->user());

        $this->toast('Variante criada.');

        return back();
    }

    public function update(UpdateVariantRequest $request, Variant $variant): RedirectResponse
    {
        $this->variantService->update($variant, $request->validated(), $request->user());

        $this->toast('Variante atualizada.');

        return back();
    }

    /**
     * "Apagar" = arquivar: a variante tem movimentos de stock e itens de
     * encomenda agarrados (regra global de eliminacao logica).
     */
    public function destroy(Variant $variant): RedirectResponse
    {
        $this->variantService->archive($variant);

        $this->toast('Variante arquivada.');

        return back();
    }
}
