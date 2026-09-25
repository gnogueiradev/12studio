<?php

namespace App\Console\Commands;

use App\Alerts\SecurityAlerts;
use App\Models\AlertState;
use App\Models\McpActivity;
use Illuminate\Console\Command;

/**
 * Resumo das leituras do Claude, a cada 15 minutos: uma conversa faz dezenas
 * de consultas e uma mensagem por consulta afogava o #seguranca. Escritas,
 * recusas e erros ja sairam um a um (McpAuditor).
 *
 * Conta a partir de uma marca d'agua (o id da ultima linha vista), por isso
 * nunca conta duas vezes nem perde linhas se uma volta falhar.
 */
class McpReadsDigestCommand extends Command
{
    private const WATERMARK = 'mcp-reads:last-id';

    /**
     * @var string
     */
    protected $signature = 'alerts:mcp-reads {--minutes=15 : Intervalo entre resumos, so para o texto}';

    /**
     * @var string
     */
    protected $description = 'Resume no Discord as leituras feitas pelo Claude desde o ultimo resumo';

    public function handle(SecurityAlerts $alerts): int
    {
        $lastId = (int) McpActivity::query()->max('id');
        $watermark = AlertState::watermark(self::WATERMARK);

        // Primeira volta: comeca daqui, sem despejar o historico todo.
        if ($watermark === null) {
            AlertState::setWatermark(self::WATERMARK, (string) $lastId);

            return self::SUCCESS;
        }

        $reads = McpActivity::query()
            ->with('user')
            ->where('id', '>', (int) $watermark)
            ->where('id', '<=', $lastId)
            ->where('result', McpActivity::RESULT_OK)
            ->whereNull('arguments')
            ->get();

        $groups = $reads
            ->groupBy(fn (McpActivity $activity): string => (string) ($activity->token_id ?? 'user-'.$activity->user_id))
            ->map(fn ($group): array => [
                'client' => (string) ($group->first()->client ?? '—'),
                'account' => (string) ($group->first()->user->name ?? '—'),
                'total' => $group->count(),
                'tools' => $group->countBy('tool')->all(),
            ])
            ->values()
            ->all();

        if ($groups !== []) {
            $alerts->mcpReads($groups, (int) $this->option('minutes'));
        }

        AlertState::setWatermark(self::WATERMARK, (string) $lastId);

        return self::SUCCESS;
    }
}
