<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

/**
 * Apaga o IP das conversas com mais de 30 dias. O IP so serve para travar
 * abusos enquanto a conversa e recente; depois disso e dado pessoal a mais.
 */
class ForgetConversationIpsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'conversations:forget-ips {--days=30 : Idade a partir da qual o IP sai}';

    /**
     * @var string
     */
    protected $description = 'Apaga o IP das conversas antigas (RGPD)';

    public function handle(): int
    {
        $updated = Conversation::query()
            ->whereNotNull('ip_address')
            ->where('created_at', '<', now()->subDays(max(1, (int) $this->option('days'))))
            ->update(['ip_address' => null]);

        $this->info("IP apagado em {$updated} conversa(s).");

        return self::SUCCESS;
    }
}
