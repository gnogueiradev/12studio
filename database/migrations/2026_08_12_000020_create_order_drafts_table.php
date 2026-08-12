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
        // Encomenda manual guardada a meio. NAO e uma encomenda: nao gasta
        // numero da sequencia anual, nao move stock e nao passa pelas
        // invariantes do OrderService — por isso vive aqui e nao como um
        // `status = 'draft'` na tabela `orders`, onde contaminaria contagens,
        // listagens e o quadro de producao.
        Schema::create('order_drafts', function (Blueprint $table): void {
            $table->id();
            // Trabalho a meio de uma pessoa, nao estado partilhado: cada admin
            // so ve os seus. Cascade porque um rascunho sem autor nao serve
            // para nada.
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            // Denormalizados so para a listagem nao ter de abrir o JSON de
            // cada linha. A autoridade e sempre o payload.
            $table->string('customer_name')->nullable();
            $table->integer('total_cents')->default(0);
            // O formulario inteiro, tal como o frontend o envia.
            $table->json('payload');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_drafts');
    }
};
