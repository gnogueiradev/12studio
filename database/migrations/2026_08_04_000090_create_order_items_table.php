<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Nullable de proposito: o item sobrevive ao arquivamento/limpeza da
            // variante — os snapshots abaixo carregam toda a identidade.
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name', 120);
            $table->string('variant_label', 120)->nullable();
            $table->string('sku', 60)->nullable();

            // unit_price_cents e O COBRADO. Em encomendas manuais com preco
            // editado, catalog_unit_price_cents guarda o preco de tabela e
            // price_override_reason o motivo — explica margens baixas mais tarde.
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('catalog_unit_price_cents')->nullable();
            $table->string('price_override_reason', 200)->nullable();
            // Acrescimo de personalizacao POR UNIDADE (decisao V1), separado do
            // unit_price para margem e faturacao verem o acrescimo:
            // line_total = (unit + surcharge) * qty.
            $table->unsignedInteger('personalization_surcharge_cents')->default(0);
            $table->unsignedSmallInteger('qty');
            $table->unsignedInteger('line_total_cents');
            $table->unsignedTinyInteger('vat_rate');
            $table->string('image_url', 255)->nullable();

            // Snapshot dos valores de personalizacao, ex. {"name":"Julia"}.
            // O admin renderiza com os labels dos campos — nunca JSON bruto.
            $table->json('personalization')->nullable();
            $table->string('fulfillment_mode', 20);

            // Producao por ITEM: uma encomenda mista mostra "porta-chaves: pronto,
            // caixa: em impressao". A encomenda deriva o progresso dos itens e
            // auto-avanca para ready_to_ship quando todos ficam ready/not_required.
            $table->string('production_status', 20)->default('not_required');

            $table->timestamps();

            $table->index('production_status', 'order_items_production_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
