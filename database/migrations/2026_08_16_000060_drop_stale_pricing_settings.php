<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Limpa as chaves `pricing.*` que a formula nova deixou sem leitor.
 *
 * A formula deixou de trabalhar com multiplicadores (1,70x, 1,75x) e com um
 * manuseamento em euros, e passou a trabalhar com margens declaradas e com
 * minutos de mao de obra. As oito chaves aqui listadas nao tem acessor nenhum
 * no PricingSettings — ficariam para sempre a ocupar espaco e a fazer crer a
 * quem espreitasse a tabela que ainda mandam em alguma coisa.
 *
 * Ao contrario da 2026_08_15_000010, o apagar e INCONDICIONAL. Naquela, a
 * chave continuava a ser lida e por isso um valor afinado a mao era uma
 * decisao do dono a respeitar; aqui nao ha leitor nenhum, e um valor guardado
 * para uma pergunta que deixou de se fazer nao e uma decisao — e lixo. As
 * margens novas entram pelos valores do config/pricing.php e o dono afina-as
 * outra vez em /admin/definicoes, onde os campos passaram a ser outros de
 * qualquer maneira.
 *
 * Nao toca em `orders`, `order_items` nem `variants`: os precos ja praticados
 * sao fotografias e nao se recalculam.
 */
return new class extends Migration
{
    /** As chaves da formula antiga, todas sem acessor no PricingSettings. */
    private const DEAD_KEYS = [
        'pricing.failure_reserve_bp',
        'pricing.minimum_resale_price_cents',
        'pricing.resale_multiplier_bp',
        'pricing.retail_multiplier_bp',
        'pricing.minimum_retail_multiplier_bp',
        'pricing.handling_cost_cents',
        'pricing.batch_job_handling_cents',
        'pricing.batch_unit_handling_cents',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('settings')->whereIn('key', self::DEAD_KEYS)->delete();
    }

    /**
     * Reverse the migrations.
     *
     * Vazio de proposito. As linhas apagadas nao tem de onde ser
     * reconstruidas, e recria-las com os valores por omissao antigos era
     * inventar decisoes que ninguem tomou. O ponto de restauro e a copia do
     * `db:backup` (ver Jenkinsfile).
     */
    public function down(): void {}
};
