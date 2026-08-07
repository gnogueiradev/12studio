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
        // Ledger de idempotencia: o id do evento Stripe e a PROPRIA chave
        // primaria. Um INSERT duplicado viola a PK ⇒ evento ja processado ⇒
        // responder 200 e parar. Primeira das tres guardas de idempotencia
        // (as outras: unique orders.stripe_session_id e o transitionOrder).
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->string('id', 60)->primary();
            $table->string('type', 60);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // Definicoes editaveis pelo admin em runtime (custo do kWh, wattagem,
        // custo/hora de trabalho, nome da loja...). Estrutural vai para config/.
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 60)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('webhook_events');
    }
};
