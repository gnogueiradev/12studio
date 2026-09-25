<?php

namespace App\Alerts;

use App\Services\SettingService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Onde vive o URL de cada webhook do Discord.
 *
 * Primeiro o backoffice (Definicoes -> Alertas), guardado na tabela settings
 * CIFRADO com a APP_KEY — o URL e uma password: quem o tem escreve no canal,
 * e um backup da BD nao o pode trazer em claro. Depois o .env
 * (DISCORD_WEBHOOK_*), que fica como alternativa e como rede: se a BD falhar,
 * os erros ainda chegam ao #sistema pelo .env.
 *
 * Nunca lanca: e lido de dentro do report() (erros 500).
 */
class AlertWebhooks
{
    /**
     * So webhooks do Discord: https://discord.com/api/webhooks/{id}/{token}
     * (e discordapp.com, ptb., canary.). Qualquer admin pode mudar isto, e um
     * URL livre deixava desviar os alertas — dados de clientes incluidos —
     * para um servidor qualquer.
     */
    public const PATTERN = '#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api(?:/v\d+)?/webhooks/\d+/[\w-]+$#';

    public const SOURCE_BACKOFFICE = 'backoffice';

    public const SOURCE_ENV = 'env';

    public const SOURCE_OFF = 'off';

    public function __construct(
        private SettingService $settings,
    ) {}

    public function url(string $channel): ?string
    {
        return $this->stored($channel) ?? $this->fromEnv($channel);
    }

    public function source(string $channel): string
    {
        return match (true) {
            $this->stored($channel) !== null => self::SOURCE_BACKOFFICE,
            $this->fromEnv($channel) !== null => self::SOURCE_ENV,
            default => self::SOURCE_OFF,
        };
    }

    public function set(string $channel, string $url): void
    {
        $this->settings->set(self::key($channel), Crypt::encryptString($url));
    }

    public function forget(string $channel): void
    {
        $this->settings->forget(self::key($channel));
    }

    /**
     * O que se pode mostrar de um URL guardado: os ultimos 4 caracteres do
     * token. Chega para distinguir dois webhooks e nao serve para nada mais.
     */
    public static function hint(?string $url): ?string
    {
        return $url === null ? null : '…'.substr($url, -4);
    }

    public static function isDiscordWebhook(string $url): bool
    {
        return preg_match(self::PATTERN, $url) === 1;
    }

    private function stored(string $channel): ?string
    {
        try {
            $encrypted = $this->settings->get(self::key($channel));

            if (! is_string($encrypted) || $encrypted === '') {
                return null;
            }

            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // A APP_KEY mudou: o valor guardado ja nao se le. Fica como se
            // nao houvesse, e o backoffice mostra o canal por configurar.
            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function fromEnv(string $channel): ?string
    {
        $url = config("alerts.webhooks.{$channel}");

        return is_string($url) && $url !== '' ? $url : null;
    }

    private static function key(string $channel): string
    {
        return "alerts.webhook.{$channel}";
    }
}
