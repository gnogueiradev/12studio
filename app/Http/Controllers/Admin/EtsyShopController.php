<?php

namespace App\Http\Controllers\Admin;

use App\Etsy\EtsyException;
use App\Etsy\EtsySettings;
use App\Etsy\EtsyShop;
use App\Etsy\EtsyTokens;
use App\Http\Controllers\Admin\Concerns\AttemptsEtsy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\Etsy\SaveSectionMapRequest;
use App\Http\Requests\Etsy\SaveShopSectionRequest;
use App\Http\Requests\Etsy\UpdateEtsyShopRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Etsy -> Loja: os textos que os compradores leem na loja, as seccoes, e a
 * que seccao vai cada categoria do 12studio.
 */
class EtsyShopController extends Controller
{
    use AttemptsEtsy;

    public function __construct(
        private EtsyTokens $tokens,
        private EtsyShop $shop,
        private EtsySettings $settings,
    ) {}

    public function index(): Response
    {
        $connection = $this->tokens->connection();
        $error = null;
        $texts = null;
        $sections = [];

        if ($connection !== null) {
            try {
                $texts = $this->shop->texts();
                $sections = $this->shop->sections();
            } catch (EtsyException $exception) {
                $error = $exception->getMessage();
                $texts = null;
                $sections = [];
            }
        }

        $map = $this->settings->sectionMap();

        return Inertia::render('admin/etsy/loja', [
            'connected' => $connection !== null,
            'error' => $error,
            'shopName' => $connection?->shop_name,
            'shop' => $texts === null ? null : [
                'title' => $texts['title'] ?? '',
                'announcement' => $texts['announcement'] ?? '',
                'saleMessage' => $texts['sale_message'] ?? '',
                'digitalSaleMessage' => $texts['digital_sale_message'] ?? '',
                'policyAdditional' => $texts['policy_additional'] ?? '',
            ],
            'sections' => array_map(fn (array $section): array => [
                'id' => $section['shop_section_id'],
                'title' => $section['title'],
                'activeListings' => $section['active_listing_count'],
            ], $sections),
            // So as categorias em uso: uma arquivada nao recebe anuncios.
            'categories' => Category::query()
                ->where('status', '!=', 'archived')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'sectionId' => $map[$category->id] ?? null,
                ])
                ->all(),
        ]);
    }

    public function updateTexts(UpdateEtsyShopRequest $request): RedirectResponse
    {
        return $this->attempt(function () use ($request): null {
            $this->shop->updateTexts($request->validated());

            return null;
        }, 'Textos da loja atualizados na Etsy.');
    }

    public function saveSection(SaveShopSectionRequest $request): RedirectResponse
    {
        $sectionId = $request->validated('section_id');

        return $this->attempt(function () use ($request, $sectionId): null {
            $this->shop->saveSection((string) $request->validated('title'), $sectionId === null ? null : (int) $sectionId);

            return null;
        }, $sectionId === null ? 'Secção criada na Etsy.' : 'Secção renomeada na Etsy.');
    }

    public function deleteSection(AdminActionRequest $request, int $section): RedirectResponse
    {
        return $this->attempt(function () use ($section): null {
            $this->shop->deleteSection($section);

            return null;
        }, 'Secção apagada. Os anúncios que lá estavam ficaram sem secção.');
    }

    /**
     * Escolha nossa: grava-se ca, e so se nota no proximo envio de um anuncio.
     */
    public function saveSectionMap(SaveSectionMapRequest $request): RedirectResponse
    {
        $this->settings->save(['section_map' => (array) $request->validated('sections')]);

        $this->toast('Guardado. Os anúncios enviados daqui em diante seguem para a secção da categoria.');

        return back();
    }
}
