<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A loja da Etsy ligada ao 12studio. Uma linha, no maximo.
     *
     * Tabela propria e nao a `settings`: o SettingService carrega a tabela
     * uma vez por processo e guarda-a em memoria, e aqui ha tres processos
     * (web, fila, scheduler) a renovar o mesmo token de hora a hora — o que
     * esperou pela vez tem de ler o que o outro acabou de gravar, nao a copia
     * que tinha.
     *
     * Os dois tokens ficam cifrados com a APP_KEY (cast `encrypted` no
     * modelo). `text` e nao `string`: cifrados, passam bem dos 255.
     *
     * Tabela nova — nao toca em nada que ja exista.
     */
    public function up(): void
    {
        Schema::create('etsy_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('shop_name', 120);
            $table->unsignedBigInteger('etsy_user_id');
            $table->string('scopes', 500);
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('access_expires_at');
            // De quando em diante se importam encomendas. Nunca para tras da
            // primeira ligacao: descontava stock de vendas ja despachadas.
            $table->timestamp('orders_since')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // A Etsy recusou o token de renovacao: so religando. Enquanto
            // estiver preenchido ninguem volta a tentar.
            $table->timestamp('broken_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etsy_connections');
    }
};
