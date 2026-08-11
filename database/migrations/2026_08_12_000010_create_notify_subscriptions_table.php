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
        // Quem deixou o email na landing "em breve" (resources/js/pages/coming-soon.tsx)
        // para ser avisado no dia da abertura.
        //
        // So o email: um visitante que ainda nao e cliente da-nos o minimo
        // para uma promessa unica, e guardar IP ou user-agent por cima disso
        // seria recolher o que nao precisamos de ter.
        Schema::create('notify_subscriptions', function (Blueprint $table): void {
            $table->id();
            // Unico para o mesmo email nao entrar duas vezes; o controller
            // trata a repeticao como sucesso em vez de a deixar rebentar aqui.
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notify_subscriptions');
    }
};
