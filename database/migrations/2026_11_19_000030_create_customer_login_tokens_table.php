<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ligacoes de entrada por email. So o hash do segredo fica guardado: quem
     * ler a base de dados nao consegue entrar como ninguem.
     */
    public function up(): void
    {
        Schema::create('customer_login_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255)->index();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_tokens');
    }
};
