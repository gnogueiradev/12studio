<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma venda ao balcao nao traz email.
     *
     * A coluna nasceu NOT NULL a pensar na loja online, onde o email e a forma
     * de acompanhar a encomenda. No canal `manual` isso nao existe: o cliente
     * esta a frente, leva a peca na mao, e obrigar a inventar um email era pedir
     * ao admin que sujasse a base de dados para o formulario o deixar passar.
     *
     * Nulo e nao string vazia: com os dois a significarem "sem email", cada
     * sitio que envia mail passava a ter de se lembrar de testar os dois.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('email', 255)->nullable()->change();
        });

        // O que ja la esta vazio passa a nulo, para nao ficarem dois valores a
        // dizer a mesma coisa a partir de agora.
        DB::table('orders')->where('email', '')->update(['email' => null]);
    }

    public function down(): void
    {
        // A coluna volta a NOT NULL, e por isso os nulos tem de sair primeiro.
        DB::table('orders')->whereNull('email')->update(['email' => '']);

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('email', 255)->nullable(false)->change();
        });
    }
};
