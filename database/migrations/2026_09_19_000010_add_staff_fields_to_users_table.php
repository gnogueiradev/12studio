<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equipa: o dono cria contas de admin e de producao no backoffice.
     *
     * So acrescenta colunas, todas com default ou nullable — nenhuma linha que
     * ja exista muda de significado. O dono NAO e atribuido aqui: e o comando
     * `users:make-owner`, corrido a mao depois do deploy.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_owner')->default(false)->after('is_admin');
            // Null = cliente ou admin; 'production' = equipa so do quadro.
            $table->string('staff_role', 20)->nullable()->after('is_owner');
            $table->boolean('must_change_password')->default(false)->after('staff_role');
            $table->timestamp('disabled_at')->nullable()->after('must_change_password');
            // Sem ->constrained() de proposito: no SQLite, acrescentar uma FK
            // obriga o Laravel a RECONSTRUIR a tabela users inteira (copia,
            // drop, rename) — com as encomendas a apontar para ela. Um ALTER
            // ADD COLUMN simples e o que torna isto aditivo a serio.
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('disabled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['created_by_user_id']);
            $table->dropColumn(['is_owner', 'staff_role', 'must_change_password', 'disabled_at', 'created_by_user_id']);
        });
    }
};
