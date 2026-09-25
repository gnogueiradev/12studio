<?php

namespace App\Alerts;

use App\Models\McpActivity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Laravel\Passport\Token;
use Throwable;

/**
 * #seguranca: quem entra, quem falha, a equipa, as chaves de API, o OAuth e
 * tudo o que o Claude escreve ou tenta sem poder.
 *
 * Nunca leva segredos: nem passwords tentadas, nem tokens, nem URLs de
 * webhook — so quem, de onde e o que.
 */
class SecurityAlerts
{
    public function __construct(
        private AlertSender $sender,
    ) {}

    public function login(User $user, bool $remember): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make("🔓 Entrou — {$user->name}", DiscordMessage::INFO)
                ->field('Email', $user->email)
                ->field('Papel', $this->role($user))
                ->field('Lembrar-me', $remember ? 'sim' : null)
                ->field('IP', request()->ip())
                ->field('Browser', $this->browser(), inline: false),
        ));
    }

    /**
     * Login recusado. A mensagem ao visitante e sempre a mesma; aqui diz-se
     * o que foi de facto — ate a conta desativada, que la fora falha calada.
     */
    public function loginFailed(?string $email, ?User $user): void
    {
        $this->guard(function () use ($email, $user): void {
            $user ??= $email === null ? null : User::query()->where('email', Str::lower($email))->first();

            $reason = match (true) {
                $user === null => 'Email desconhecido',
                $user->isDisabled() => 'Conta desativada',
                default => 'Password ou código errado',
            };

            $who = $user === null ? ($email ?? '?') : $user->name;

            $this->send(DiscordMessage::make("⚠️ Login falhado — {$who}", DiscordMessage::WARNING)
                ->field('Email tentado', $email)
                ->field('Motivo', $reason)
                ->field('IP', request()->ip())
                ->field('Browser', $this->browser(), inline: false));
        });
    }

    public function lockout(?string $email): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make('🚨 Bloqueado por excesso de tentativas', DiscordMessage::DANGER)
                ->field('Email tentado', $email)
                ->field('IP', request()->ip())
                ->field('Browser', $this->browser(), inline: false),
        ));
    }

    /**
     * Password mudada, por qualquer caminho: definicoes, link de reposicao ou
     * o dono. Diz quantas chaves de API morreram com ela.
     */
    public function passwordChanged(User $user, int $revokedKeys): void
    {
        $this->guard(function () use ($user, $revokedKeys): void {
            $actor = auth()->user();

            $this->send(DiscordMessage::make("🔑 Password mudada — {$user->name}", DiscordMessage::WARNING)
                ->field('Por', match (true) {
                    ! $actor instanceof User => 'Link de reposição por email',
                    $actor->is($user) => 'O próprio',
                    default => $actor->name,
                })
                ->field('Chaves de API revogadas', $revokedKeys > 0 ? (string) $revokedKeys : null)
                ->field('IP', request()->ip()));
        });
    }

    /**
     * Chaves revogadas sozinhas porque a conta mudou (papel, desativada).
     */
    public function keysAutoRevoked(User $user, int $count, string $reason): void
    {
        if ($count === 0) {
            return;
        }

        $this->guard(fn () => $this->send(
            DiscordMessage::make("🔒 {$count} ".($count === 1 ? 'chave revogada' : 'chaves revogadas')." — {$user->name}", DiscordMessage::WARNING)
                ->field('Porquê', $reason),
        ));
    }

    public function staffChanged(User $staff, User $by, string $change): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make("👥 Equipa: {$change} — {$staff->name}", DiscordMessage::INFO)
                ->field('Email', $staff->email)
                ->field('Papel', $this->role($staff))
                ->field('Por', $by->name)
                ->url(route('admin.utilizadores.index')),
        ));
    }

    public function twoFactor(User $user, bool $enabled): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make(
                ($enabled ? '🛡️ 2FA ligado — ' : '⚠️ 2FA desligado — ').$user->name,
                $enabled ? DiscordMessage::SUCCESS : DiscordMessage::WARNING,
            )->field('IP', request()->ip()),
        ));
    }

    public function passkey(User $user, ?string $name, bool $added): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make(
                ($added ? '🗝️ Passkey adicionada — ' : '🗝️ Passkey removida — ').$user->name,
                $added ? DiscordMessage::INFO : DiscordMessage::WARNING,
            )
                ->field('Nome', $name)
                ->field('IP', request()->ip()),
        ));
    }

    /**
     * Chave de API criada, ou aplicacao ligada por OAuth. As sem validade
     * chegam marcadas: so morrem revogadas.
     */
    public function keyCreated(User $user, string $name, string $access, ?CarbonInterface $expiresAt, bool $oauth = false): void
    {
        $this->guard(function () use ($user, $name, $access, $expiresAt, $oauth): void {
            $write = $access === 'write';
            $never = ! $oauth && $expiresAt === null;

            $this->send(DiscordMessage::make(
                ($oauth ? '🔗 Aplicação ligada por OAuth — ' : '🗝️ Chave de API criada — ').$name,
                $write || $never ? DiscordMessage::WARNING : DiscordMessage::INFO,
            )
                ->field('Conta', $user->name)
                ->field('Acesso', $write ? 'Leitura e escrita' : 'Só leitura')
                ->field('Validade', $oauth ? null : ($never ? '⚠️ Sem validade — só morre revogada' : $expiresAt->format('Y-m-d')))
                ->field('IP', request()->ip())
                ->url(route('admin.chaves-api.index')));
        });
    }

    public function keyRevoked(User $user, Token $token): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make('🗑️ Chave revogada — '.($token->name ?? $token->client->name ?? '?'), DiscordMessage::INFO)
                ->field('Conta', $user->name)
                ->field('Por', $this->sender->actor(auth()->user() instanceof User ? auth()->user() : null)),
        ));
    }

    public function keysRevokedAll(User $user, int $count): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make("🗑️ Revogar tudo — {$user->name}", DiscordMessage::WARNING)
                ->field('Chaves revogadas', (string) $count),
        ));
    }

    public function oauthDenied(User $user, ?string $client): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make('🚫 Ligação OAuth recusada — '.($client ?? '?'), DiscordMessage::INFO)
                ->field('Conta', $user->name)
                ->field('IP', request()->ip()),
        ));
    }

    /**
     * Registo dinamico: e publico (e assim que o claude.ai se apresenta).
     * Um registo que nao foste tu a pedir e o primeiro sinal de alguem a
     * experimentar.
     *
     * @param  array<int, string>  $redirects
     */
    public function oauthClientRegistered(?string $name, array $redirects): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make('🆕 Cliente OAuth registado — '.($name ?? '?'), DiscordMessage::INFO)
                ->field('Redirects', implode("\n", $redirects), inline: false)
                ->field('IP', request()->ip())
                ->field('Browser', $this->browser(), inline: false),
        ));
    }

    /**
     * Cada chamada do MCP que nao seja uma leitura bem sucedida: escritas (o
     * que mudou), recusas e erros. As leituras vao no resumo de 15 minutos.
     */
    public function mcpActivity(McpActivity $activity): void
    {
        $this->guard(function () use ($activity): void {
            $write = $activity->arguments !== null;

            if ($activity->result === McpActivity::RESULT_OK && ! $write) {
                return;
            }

            [$title, $level] = match ($activity->result) {
                McpActivity::RESULT_OK => ["✍️ Claude alterou — {$activity->tool}", DiscordMessage::INFO],
                McpActivity::RESULT_DENIED => ["⛔ MCP recusado — {$activity->tool}", DiscordMessage::DANGER],
                McpActivity::RESULT_VALIDATION_ERROR => ["⚠️ MCP com dados inválidos — {$activity->tool}", DiscordMessage::WARNING],
                default => ["💥 MCP com erro — {$activity->tool}", DiscordMessage::DANGER],
            };

            $this->send(DiscordMessage::make($title, $level)
                ->field('Conta', $activity->user?->name)
                ->field('Chave', $activity->client)
                ->field('O que mudou', $this->json($activity->changes), inline: false)
                ->field('Pedido', $activity->changes === null ? $this->json($activity->arguments) : null, inline: false)
                ->field('IP', $activity->ip));
        });
    }

    /**
     * @param  array<int, array{client: string, account: string, total: int, tools: array<string, int>}>  $groups
     */
    public function mcpReads(array $groups, int $minutes): void
    {
        $this->guard(function () use ($groups, $minutes): void {
            foreach ($groups as $group) {
                arsort($group['tools']);

                $this->send(DiscordMessage::make("👀 Claude consultou {$group['total']}× nos últimos {$minutes} min", DiscordMessage::INFO)
                    ->description(collect($group['tools'])->map(fn (int $count, string $tool): string => "{$tool} ×{$count}")->implode("\n"))
                    ->field('Conta', $group['account'])
                    ->field('Chave', $group['client']));
            }
        });
    }

    /**
     * Um webhook do Discord foi ligado, trocado ou removido no backoffice.
     * Chega ao #seguranca de agora E ao de antes: quem desviasse os alertas
     * para um servidor seu nao o conseguia fazer sem deixar rasto no antigo.
     */
    public function webhookChanged(string $channel, string $change, User $by, ?string $previousSecurityUrl): void
    {
        $this->guard(function () use ($channel, $change, $by, $previousSecurityUrl): void {
            $message = DiscordMessage::make('🔧 Webhook de '.(AlertChannel::LABELS[$channel] ?? $channel)." {$change}", DiscordMessage::WARNING)
                ->field('Por', $by->name)
                ->field('IP', request()->ip())
                ->url(route('admin.alertas.index'));

            $this->send($message);

            if ($previousSecurityUrl !== null && $previousSecurityUrl !== AlertChannel::webhook(AlertChannel::SECURITY)) {
                $this->sender->sendToUrl($previousSecurityUrl, $message);
            }
        });
    }

    public function keyExpiring(Token $token, User $user): void
    {
        $this->guard(fn () => $this->send(
            DiscordMessage::make('⏳ Chave de API a expirar — '.($token->name ?? '?'), DiscordMessage::WARNING)
                ->field('Conta', $user->name)
                ->field('Expira', $token->expires_at?->format('Y-m-d'))
                ->url(route('admin.chaves-api.index')),
        ));
    }

    private function role(User $user): string
    {
        return match ($user->role()) {
            'owner' => 'Dono',
            User::ROLE_ADMIN => 'Administrador',
            User::ROLE_PRODUCTION => 'Produção',
            default => 'Cliente',
        };
    }

    private function browser(): ?string
    {
        $agent = request()->userAgent();

        return $agent === null ? null : Str::limit($agent, 200);
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     */
    private function json(?array $data): ?string
    {
        if ($data === null || $data === []) {
            return null;
        }

        // Cortado ANTES de abrir o bloco: um corte no limite do campo (1024)
        // deixava o ``` por fechar.
        $json = Str::limit((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 950);

        return "```json\n{$json}\n```";
    }

    private function send(DiscordMessage $message): void
    {
        $this->sender->send(AlertChannel::SECURITY, $message);
    }

    private function guard(callable $build): void
    {
        try {
            $build();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
