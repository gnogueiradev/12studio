<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A formula de precos passou a ser menos agressiva: o custo/hora da maquina
 * desceu de 0,50 EUR para 0,20 EUR, a reserva de falhas de 8% para 5%, e as
 * duas tabelas de faixas (multiplicador por custo, manuseamento por peso)
 * deixaram de existir.
 *
 * O config/pricing.php sozinho nao chega, por duas razoes:
 *
 *   1. o custo/hora que a calculadora usa vem do PERFIL DE IMPRESSORA, e o
 *      valor do config e so a rede de seguranca para quando nao ha nenhum;
 *   2. qualquer chave `pricing.*` gravada na tabela `settings` SOBREPOE-SE ao
 *      config — uma reserva de 8% gravada la deixava a formula nova sem efeito
 *      nenhum em producao, e sem nada a dize-lo.
 *
 * Nao toca em `orders`, `order_items` nem `variants`: os precos ja praticados
 * sao fotografias (ver create_order_items_table) e nao se recalculam.
 */
return new class extends Migration
{
    /** O custo/hora antigo, em centimos. */
    private const OLD_HOURLY_RATE_CENTS = 50;

    private const NEW_HOURLY_RATE_CENTS = 20;

    /** A reserva de falhas antiga, em pontos base. */
    private const OLD_FAILURE_RESERVE_BP = 800;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            // SO as linhas ainda no valor por omissao antigo. Uma taxa que o
            // dono ja afinou a mao e uma decisao dele — mesmo raciocinio do
            // firstOrCreate do DatabaseSeeder.
            DB::table('printer_profiles')
                ->where('hourly_rate_cents', self::OLD_HOURLY_RATE_CENTS)
                ->update(['hourly_rate_cents' => self::NEW_HOURLY_RATE_CENTS]);

            // Estas duas ja nao sao lidas por codigo nenhum: o PricingSettings
            // deixou de ter chave para elas. Ficariam para sempre a ocupar
            // espaco e a confundir quem espreitasse a tabela.
            DB::table('settings')
                ->whereIn('key', ['pricing.resale_multipliers', 'pricing.handling_tiers'])
                ->delete();

            // A coluna `value` tem cast 'json' (App\Models\Setting), logo o
            // inteiro 800 esta gravado como a string '800'. Apagar a chave e o
            // que faz o config voltar a mandar — e so se ela estiver mesmo no
            // valor antigo: uma reserva que o dono escolheu fica de pe.
            DB::table('settings')
                ->where('key', 'pricing.failure_reserve_bp')
                ->where('value', json_encode(self::OLD_FAILURE_RESERVE_BP))
                ->delete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Vazio de proposito, e nao por esquecimento. As linhas de `settings`
     * apagadas nao tem de onde ser reconstruidas, e repor 0,20 -> 0,50 EUR/h
     * apanhava tambem os perfis que alguem tenha posto a 0,20 a mao. O ponto
     * de restauro e a copia que o `db:backup` tira antes de cada migracao no
     * deploy (ver Jenkinsfile).
     */
    public function down(): void {}
};
