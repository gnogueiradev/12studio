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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            // nullOnDelete: apagar uma categoria nao pode arrastar produtos —
            // ficam apenas sem categoria.
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('status', 10)->default('draft');
            $table->boolean('featured')->default(false);
            // Snapshot da taxa em cada order_item na venda; aqui e so o valor atual.
            $table->unsignedTinyInteger('vat_rate')->default(23);

            // in_stock: pecas ja impressas, envio imediato e stock fisico.
            // made_to_order: impressa apos a encomenda (usa capacidade, nao stock).
            // custom: personalizada — isenta do direito de resolucao de 14 dias
            // (a pagina do produto mostra a nota de devolucao).
            $table->string('fulfillment_mode', 20)->default('in_stock');
            $table->unsignedSmallInteger('production_time_days')->nullable();
            $table->boolean('allow_backorder')->default(false);
            // Limite SOFT de unidades simultaneamente em producao (plano, risco 5):
            // a verificacao e agregada e pode exceder por 1 em corridas — aceitavel.
            $table->unsignedSmallInteger('max_open_production_qty')->nullable();

            // [{key, label, type: 'text'|'number', required, maxLength}]
            $table->json('personalization_fields')->nullable();
            // Acrescimo fixo POR UNIDADE quando personalizado (decisao V1).
            $table->unsignedInteger('personalization_surcharge_cents')->nullable();

            $table->string('seo_title', 120)->nullable();
            $table->string('seo_description', 200)->nullable();
            $table->timestamps();

            $table->index(['status', 'featured'], 'products_status_featured_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
