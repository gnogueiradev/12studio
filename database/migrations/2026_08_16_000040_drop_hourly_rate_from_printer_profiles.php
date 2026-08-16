<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cai a coluna que a 2026_08_16_000030 substituiu.
 *
 * Sozinha na sua migracao de proposito: um `dropColumn` no SQLite so e
 * ALTER TABLE nativo a partir do 3.35; abaixo disso o Laravel RECONSTROI a
 * tabela, e a reconstrucao nao sabe do indice unico parcial
 * `printer_profiles_one_default` (DDL cru na 2026_08_13_000020, invisivel ao
 * Blueprint). Perde-lo nao da erro nenhum — da duas impressoras predefinidas
 * ao mesmo tempo e uma calculadora a escolher a errada.
 *
 * Por isso o indice recria-se sempre a seguir, com IF NOT EXISTS: no caminho
 * nativo nao custa nada, no caminho da reconstrucao e o que tapa o buraco.
 */
return new class extends Migration
{
    private const ONE_DEFAULT_INDEX =
        'CREATE UNIQUE INDEX IF NOT EXISTS printer_profiles_one_default '
        .'ON printer_profiles (is_default) WHERE is_default = 1';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('printer_profiles', function (Blueprint $table): void {
            $table->dropColumn('hourly_rate_cents');
        });

        DB::statement(self::ONE_DEFAULT_INDEX);
    }

    /**
     * Reverse the migrations.
     *
     * A coluna volta, o valor nao: o custo/hora que cada perfil tinha nao se
     * reconstroi a partir da potencia, do preco e da vida util — a decomposicao
     * nao tem inversa. Quem voltar atras fica com todos os perfis no valor por
     * omissao antigo. O ponto de restauro e a copia que o `db:backup` tira
     * antes de cada migracao no deploy (ver Jenkinsfile).
     */
    public function down(): void
    {
        Schema::table('printer_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('hourly_rate_cents')->default(20)->after('name');
        });

        DB::statement(self::ONE_DEFAULT_INDEX);
    }
};
