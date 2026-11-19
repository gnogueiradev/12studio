<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            // fixed: `value` em centimos. percent: `value` de 1 a 100.
            $table->string('type', 10);
            $table->unsignedInteger('value');
            $table->unsignedInteger('min_subtotal_cents')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Null = ilimitado.
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->boolean('single_use_per_email')->default(false);
            $table->string('channel', 20)->index();
            // Preenchido nos codigos pessoais, sempre em minusculas.
            $table->string('email', 255)->nullable();
            $table->boolean('is_active')->default(true);
            // Quando o email de boas-vindas saiu. Um job repetido nao o reenvia.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        // Um so codigo de boas-vindas por email, para sempre: sobrevive a conta
        // apagada e recriada e a dois jobs ao mesmo tempo. O Blueprint nao sabe
        // escrever indices parciais.
        DB::statement("CREATE UNIQUE INDEX promo_codes_welcome_email_unique ON promo_codes (email) WHERE channel = 'welcome'");

        Schema::create('promo_code_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained();
            // Uma encomenda so regista um uso, mesmo que volte a ficar paga.
            $table->foreignId('order_id')->unique()->constrained();
            $table->string('email', 255)->nullable()->index();
            // O email so quando o codigo e de uso unico por email. O indice unico
            // depende de uma coluna de outra tabela, e os NULL nao colidem entre si.
            $table->string('unique_email', 255)->nullable();
            $table->unsignedInteger('discount_cents');
            $table->timestamps();

            $table->unique(['promo_code_id', 'unique_email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_code_redemptions');
        Schema::dropIfExists('promo_codes');
    }
};
