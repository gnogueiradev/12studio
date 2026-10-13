<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsySettings;
use App\Etsy\EtsyShop;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\Category;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_sections_list')]
#[Title('Secções da loja na Etsy')]
#[Description('As secções em que a loja arruma os anúncios na Etsy, com quantos anúncios ativos tem cada uma, e a que secção vai cada categoria do 12studio (o mapa escolhe-se no backoffice, em Etsy → Loja). Criar ou renomear: etsy_section_save.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsySectionsListTool extends StudioTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyShop $shop,
        private EtsySettings $settings,
    ) {}

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $map = $this->settings->sectionMap();

        return Response::structured([
            'sections' => $this->shop->sections(),
            'categories' => Category::query()
                ->where('status', '!=', 'archived')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => [
                    'category_id' => $category->id,
                    'category' => $category->name,
                    'shop_section_id' => $map[$category->id] ?? null,
                ])
                ->all(),
        ]);
    }
}
