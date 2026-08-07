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
        // Sequencia anual para numeros humanos ("2026-0042"). Incrementada com
        // `UPDATE ... SET last_number = last_number + 1 ... RETURNING` — statement
        // unico e atomico, porque o SQLite nao tem SELECT ... FOR UPDATE.
        // NUNCA derivar numeros de contagens de linhas (corridas + buracos).
        Schema::create('order_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 20)->unique();
            // Nullable: compra como convidado (contas de cliente so na Fase 5).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 120);
            $table->string('email', 255);
            $table->string('phone', 30)->nullable();
            // Clientes PT pedem "fatura com contribuinte" — recolhido no checkout.
            $table->string('nif', 20)->nullable();

            // Pipeline de fulfilment. O indicador FINANCEIRO e payment_status —
            // os dois nunca se misturam (invariantes no OrderService).
            $table->string('status', 20)->default('pending_payment');
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_status', 20)->default('pending');

            // Centro de todos os canais: vendas Vinted/Instagram/presenciais
            // entram aqui como encomendas manuais e partilham stock e producao.
            $table->string('sales_channel', 20)->default('website');
            $table->string('external_order_reference', 100)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Ancora de idempotencia do webhook (unique). Null em manuais.
            $table->string('stripe_session_id', 255)->nullable()->unique();
            $table->string('stripe_payment_intent_id', 255)->nullable();

            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->string('currency', 3)->default('EUR');

            // Snapshots imutaveis — editar moradas/produtos no admin nunca pode
            // reescrever o historial de uma encomenda (tambem exigencia da futura
            // faturacao certificada).
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            $table->string('shipping_method_name', 120)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('tracking_url', 255)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('admin_note')->nullable();
            // Pagou mas o decremento de stock falhou (oversell) — problema
            // operacional a resolver pelo admin, nunca um pagamento perdido.
            $table->boolean('stock_issue')->default(false);
            // Link de tracking para convidados: /encomenda/{number}?token=...
            $table->string('guest_access_token', 64)->unique();

            // Campos agnosticos do fornecedor de faturacao certificada (Moloni/
            // InvoiceXpress) — unica superficie de contacto da integracao futura.
            $table->string('invoice_provider', 30)->nullable();
            $table->string('invoice_external_id', 100)->nullable();
            $table->string('invoice_number', 60)->nullable();
            $table->string('invoice_url', 255)->nullable();
            $table->timestamp('invoiced_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'payment_status'], 'orders_status_payment_idx');
            $table->index('sales_channel', 'orders_sales_channel_idx');
            $table->index('email', 'orders_email_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_sequences');
    }
};
