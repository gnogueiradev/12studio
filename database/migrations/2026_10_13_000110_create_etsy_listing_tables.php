<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que liga o catalogo do 12studio aos anuncios da Etsy.
     *
     *   etsy_listings        um produto <-> um anuncio, com o conteudo proprio
     *                        da Etsy (o titulo e a descricao la nao sao os do
     *                        site: tem outro limite, outro publico, outra lingua)
     *   etsy_variants        uma variante <-> o "product" e a "offering" que a
     *                        Etsy lhe deu no inventario do anuncio
     *   etsy_listing_images  uma fotografia do produto <-> a imagem la
     *
     * Tudo em tabelas proprias, de proposito: `products`, `variants` e
     * `product_images` nao ganham coluna nenhuma. As chaves estrangeiras
     * nascem com as tabelas, portanto o SQLite nao reconstroi as que ja
     * existem.
     *
     * Tabelas novas — nao tocam em nada que ja exista.
     */
    public function up(): void
    {
        Schema::create('etsy_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            // Null enquanto o anuncio ainda nao existe la (conteudo preparado
            // ca, por enviar) ou depois de ser apagado.
            $table->unsignedBigInteger('etsy_listing_id')->nullable()->unique();
            // O estado LA: draft, active, inactive, sold_out, expired — ou
            // deleted, quando foi apagado e so ficou ca o conteudo.
            $table->string('state', 20)->nullable();
            $table->string('url', 500)->nullable();

            // Conteudo proprio da Etsy. Vazio = "usa o do produto".
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->json('materials')->nullable();
            $table->unsignedBigInteger('taxonomy_id')->nullable();
            $table->string('who_made', 20)->nullable();
            $table->string('when_made', 20)->nullable();
            $table->unsignedBigInteger('shipping_profile_id')->nullable();
            $table->unsignedBigInteger('return_policy_id')->nullable();
            $table->unsignedBigInteger('readiness_state_id')->nullable();
            $table->unsignedBigInteger('shop_section_id')->nullable();
            $table->json('properties')->nullable();

            $table->string('video_path')->nullable();
            $table->unsignedBigInteger('etsy_video_id')->nullable();

            // Que dimensao da variante (cor, material, tamanho) ficou em que
            // propriedade da Etsy. Guardado para nao mudar de um envio para
            // o seguinte.
            $table->json('property_map')->nullable();

            $table->boolean('sync_enabled')->default(true);
            $table->timestamp('auto_deactivated_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_hash', 64)->nullable();
            $table->timestamp('remote_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('etsy_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('etsy_listings')->cascadeOnDelete();
            $table->unsignedBigInteger('etsy_product_id')->nullable();
            $table->unsignedBigInteger('etsy_offering_id')->nullable();
            // Preco proprio na Etsy, IVA incluido. Null = o do site (com a
            // margem global, se houver).
            $table->unsignedInteger('etsy_price_cents')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('last_quantity_pushed')->nullable();
            $table->timestamps();

            $table->index('listing_id', 'etsy_variants_listing_idx');
        });

        Schema::create('etsy_listing_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained('etsy_listings')->cascadeOnDelete();
            // Null numa imagem que ja la estava quando o anuncio foi ligado
            // (nao veio de ca), ou cuja fotografia foi apagada ca entretanto —
            // e e por ficar a linha que ainda se consegue apaga-la la.
            $table->foreignId('product_image_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('etsy_image_id')->nullable();
            $table->unsignedTinyInteger('rank')->nullable();
            $table->string('source_path')->nullable();
            // A fotografia fica no 12studio mas nao vai para a Etsy.
            $table->boolean('excluded')->default(false);
            $table->timestamps();

            $table->index('listing_id', 'etsy_listing_images_listing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etsy_listing_images');
        Schema::dropIfExists('etsy_variants');
        Schema::dropIfExists('etsy_listings');
    }
};
