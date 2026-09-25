<?php

namespace App\Http\Controllers\Admin;

use App\Alerts\AlertChannel;
use App\Alerts\AlertSender;
use App\Alerts\AlertWebhooks;
use App\Alerts\DiscordMessage;
use App\Alerts\SecurityAlerts;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\Alert\UpdateAlertWebhookRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definicoes -> Alertas: os webhooks do Discord, um por canal, com o guia de
 * como os obter.
 *
 * Admins (decisao do dono), com a password pedida outra vez. O URL completo
 * nunca volta ao browser depois de guardado — so o fim, para distinguir. E
 * cada mudanca avisa o #seguranca, no webhook novo e no antigo.
 */
class AlertSettingsController extends Controller
{
    /** A credencial do Jenkins para os avisos de deploy (Jenkinsfile). */
    private const JENKINS_CREDENTIAL = '12studio-discord-webhook-sistema';

    public function __construct(
        private AlertWebhooks $webhooks,
        private AlertSender $sender,
        private SecurityAlerts $alerts,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/definicoes/alertas', [
            'channels' => array_map(fn (string $channel): array => [
                'key' => $channel,
                'name' => AlertChannel::LABELS[$channel],
                'description' => AlertChannel::DESCRIPTIONS[$channel],
                'source' => $this->webhooks->source($channel),
                'hint' => AlertWebhooks::hint($this->webhooks->url($channel)),
                'envName' => 'DISCORD_WEBHOOK_'.strtoupper($channel),
            ], AlertChannel::ALL),
            'jenkinsCredential' => self::JENKINS_CREDENTIAL,
        ]);
    }

    public function update(UpdateAlertWebhookRequest $request, string $channel): RedirectResponse
    {
        $previousSecurity = $this->webhooks->url(AlertChannel::SECURITY);
        $replaced = $this->webhooks->source($channel) !== AlertWebhooks::SOURCE_OFF;

        $this->webhooks->set($channel, (string) $request->validated('url'));
        $this->alerts->webhookChanged($channel, $replaced ? 'trocado' : 'ligado', $this->user($request), $previousSecurity);

        $this->toast('Webhook de '.AlertChannel::LABELS[$channel].' guardado. Carrega em "Testar" para confirmar.');

        return back();
    }

    public function destroy(AdminActionRequest $request, string $channel): RedirectResponse
    {
        $previousSecurity = $this->webhooks->url(AlertChannel::SECURITY);

        $this->webhooks->forget($channel);
        $this->alerts->webhookChanged($channel, 'removido do backoffice', $this->user($request), $previousSecurity);

        $this->toast($this->webhooks->source($channel) === AlertWebhooks::SOURCE_ENV
            ? 'Removido. O canal passa a usar o webhook do .env.'
            : 'Removido. Este canal deixa de receber alertas.', 'info');

        return back();
    }

    /**
     * Sem fila: quem carrega em "Testar" quer saber ja se chegou.
     */
    public function test(AdminActionRequest $request, string $channel): RedirectResponse
    {
        $ok = $this->sender->sendNow($channel, DiscordMessage::make('🔔 Teste de ligação', DiscordMessage::SUCCESS)
            ->description('Enviado por '.$this->user($request)->name.' a partir do backoffice. Se estás a ler isto em '
                .AlertChannel::LABELS[$channel].', os alertas deste canal estão ligados.'));

        $ok
            ? $this->toast('Mensagem enviada — confirma que apareceu em '.AlertChannel::LABELS[$channel].'.')
            : $this->toast('O Discord não aceitou a mensagem. Confirma o URL do webhook (pode ter sido apagado no Discord).', 'error');

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
