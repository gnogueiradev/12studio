<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Um recibo da Etsy e a encomenda do 12studio em que se tornou.
     *
     * O `etsy_receipt_id` unico e a ancora de tudo: e ele que garante que uma
     * venda entra uma so vez e desce o stock uma so vez, corra a importacao
     * as vezes que correr.
     *
     * De proposito, o que NAO esta aqui: o email do comprador, a morada (essa
     * fica na encomenda, que precisa dela para enviar) e o recibo em bruto.
     * Os termos da API da Etsy pedem o minimo de dados de compradores, e so
     * pelo tempo necessario.
     *
     * Tabela nova — nao toca em nada que ja exista. A chave estrangeira para
     * `orders` nasce com esta tabela, portanto o SQLite nao reconstroi a das
     * encomendas.
     */
    public function up(): void
    {
        Schema::create('etsy_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('etsy_receipt_id')->unique();
            // Null quando o recibo ficou por rever sem chegar a ser encomenda
            // (outra moeda, sem linhas).
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            // imported | needs_review | failed
            $table->string('status', 20);
            // O estado LA: paid, completed, open, canceled, fully refunded…
            $table->string('etsy_status', 30)->nullable();

            // O que o comprador escreveu. Texto de um estranho: no MCP sai
            // sempre embrulhado como dados.
            $table->text('buyer_message')->nullable();
            $table->text('gift_message')->nullable();

            // Os totais como a Etsy os da (imposto, desconto, total) e, do
            // pagamento, o bruto, a taxa e o liquido. Em centimos.
            $table->json('totals')->nullable();
            // As linhas que nao casaram com nenhuma variante.
            $table->json('unmatched_lines')->nullable();

            $table->timestamp('etsy_created_at')->nullable();
            $table->timestamp('etsy_updated_at')->nullable();
            $table->timestamp('shipped_on_etsy_at')->nullable();
            // Para a fase do envio: quando o seguimento foi mandado para la.
            $table->timestamp('tracking_pushed_at')->nullable();
            $table->string('tracking_push_error', 500)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index('status', 'etsy_receipts_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etsy_receipts');
    }
};
