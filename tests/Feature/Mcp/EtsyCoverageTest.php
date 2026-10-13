<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Tool;
use ReflectionProperty;
use Tests\TestCase;

/**
 * O dono pediu uma garantia: tudo o que o `aserper/etsy-mcp` fazia, o 12studio
 * faz. Este teste e essa garantia por escrito.
 *
 * O mapa tem as 41 capacidades de la, cada uma com a ferramenta de ca que a
 * cobre. As que ja foram entregues tem de estar registadas no servidor; as que
 * ainda nao, estao em PENDING com a fase em que entram. PENDING so encolhe: no
 * fim do plano fica vazia, e a partir dai tirar uma ferramenta do servidor
 * parte este teste.
 */
class EtsyCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Capacidade do etsy-mcp => ferramenta do 12studio que a cobre. */
    private const MAP = [
        // Listings
        'search_listings' => 'etsy_market_listings',
        'get_listing' => 'etsy_listing_get',
        'create_draft_listing' => 'etsy_listing_push',
        'update_listing' => 'etsy_listing_save',
        'delete_listing' => 'etsy_listing_delete',
        'get_listings_by_shop' => 'etsy_listings_list',
        // Shop
        'get_shop' => 'etsy_status',
        'update_shop' => 'etsy_shop_update',
        'find_shops' => 'etsy_market_shops',
        'get_shop_sections' => 'etsy_sections_list',
        'manage_shop_sections' => 'etsy_section_save',
        // Orders
        'get_shop_receipts' => 'etsy_receipts_list',
        'get_receipt' => 'etsy_receipt_get',
        'update_receipt' => 'etsy_receipt_update',
        'create_shipment' => 'etsy_order_ship',
        // Transactions
        'get_transactions_by_shop' => 'etsy_transactions_list',
        'get_transaction' => 'etsy_transactions_list',
        'get_transactions_by_receipt' => 'etsy_transactions_list',
        // Shipping
        'get_shipping_profiles' => 'etsy_shipping_list',
        'create_shipping_profile' => 'etsy_shipping_profile_save',
        'update_shipping_profile' => 'etsy_shipping_profile_save',
        'manage_shipping_destinations' => 'etsy_shipping_destination_save',
        'get_shipping_carriers' => 'etsy_shipping_list',
        // Reviews
        'get_reviews_by_listing' => 'etsy_reviews_list',
        'get_reviews_by_shop' => 'etsy_reviews_list',
        // Users
        'get_me' => 'etsy_status',
        'get_user' => 'etsy_user_get',
        'get_user_addresses' => 'etsy_user_get',
        // Taxonomy
        'get_buyer_taxonomy' => 'etsy_taxonomy',
        'get_taxonomy_properties' => 'etsy_taxonomy',
        // Images/Video
        'get_listing_images' => 'etsy_listing_get',
        'upload_listing_image' => 'etsy_listing_push',
        'upload_listing_video' => 'etsy_listing_push',
        'delete_listing_image' => 'etsy_listing_save',
        // Inventory
        'get_listing_inventory' => 'etsy_listing_get',
        'update_listing_inventory' => 'etsy_listing_push',
        // Payments
        'get_payments' => 'etsy_payments_list',
        'get_ledger_entries' => 'etsy_ledger_list',
        // Return Policies
        'get_return_policies' => 'etsy_shipping_list',
        'manage_return_policy' => 'etsy_return_policy_save',
        // Auth: a ligacao faz-se no browser; a ferramenta diz o estado.
        'authenticate' => 'etsy_status',
    ];

    /**
     * Ferramentas do mapa que ainda nao existem, com a fase do plano em que
     * entram. Quando uma fase fecha, as linhas dela saem daqui.
     */
    private const PENDING = [
        // Fase 3 — publicar, desativar, apagar
        'etsy_listing_delete',
        // Fase 4 — encomendas
        'etsy_receipts_list', 'etsy_receipt_get', 'etsy_transactions_list',
        'etsy_payments_list', 'etsy_ledger_list',
        // Fase 5 — envio
        'etsy_receipt_update', 'etsy_order_ship',
    ];

    public function test_the_map_still_has_the_forty_one_capabilities(): void
    {
        $this->assertCount(41, self::MAP);
    }

    public function test_every_capability_already_delivered_has_its_tool_on_the_server(): void
    {
        $registered = $this->registeredToolNames();

        foreach (self::MAP as $capability => $tool) {
            if (in_array($tool, self::PENDING, true)) {
                continue;
            }

            $this->assertContains($tool, $registered, "A capacidade \"{$capability}\" do etsy-mcp ficou sem ferramenta: falta \"{$tool}\" no StudioServer.");
        }
    }

    public function test_nothing_is_left_pending_once_it_exists(): void
    {
        // Uma ferramenta que ja esta no servidor e continua em PENDING era uma
        // garantia a menos sem ninguem dar por isso.
        $this->assertSame([], array_values(array_intersect(self::PENDING, $this->registeredToolNames())));
    }

    public function test_pending_only_lists_tools_the_map_needs(): void
    {
        $this->assertSame([], array_values(array_diff(self::PENDING, self::MAP)));
    }

    /**
     * @return array<int, string>
     */
    private function registeredToolNames(): array
    {
        $this->actingAs(User::factory()->admin()->create());

        /** @var array<int, class-string<Tool>> $classes */
        $classes = (new ReflectionProperty(StudioServer::class, 'tools'))->getDefaultValue();

        return array_map(fn (string $class): string => app($class)->name(), $classes);
    }
}
