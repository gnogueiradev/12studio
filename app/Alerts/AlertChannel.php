<?php

namespace App\Alerts;

/**
 * Os quatro canais do Discord. O valor e a chave em config('alerts.webhooks')
 * e no backoffice (Definicoes -> Alertas).
 *
 * Convencao qrcode: const arrays em vez de PHP enums.
 */
final class AlertChannel
{
    public const ORDERS = 'encomendas';

    public const STOCK = 'stock';

    public const SECURITY = 'seguranca';

    public const SYSTEM = 'sistema';

    public const ALL = [self::ORDERS, self::STOCK, self::SECURITY, self::SYSTEM];

    /** O nome sugerido para o canal no Discord. */
    public const LABELS = [
        self::ORDERS => '#encomendas',
        self::STOCK => '#stock-producao',
        self::SECURITY => '#seguranca',
        self::SYSTEM => '#sistema',
    ];

    public const DESCRIPTIONS = [
        self::ORDERS => 'Encomendas novas, pagamentos, envios, cancelamentos e encomendas paradas à espera de pagamento.',
        self::STOCK => 'Quadro de produção, encomendas prontas a enviar, ajustes de stock, stock baixo e bobines a acabar.',
        self::SECURITY => 'Logins, passwords, equipa, chaves de API, OAuth e tudo o que o Claude altera pelo MCP.',
        self::SYSTEM => 'Erros, tarefas falhadas, backup diário, scheduler parado e deploys.',
    ];

    /**
     * Backoffice primeiro, .env depois (AlertWebhooks).
     */
    public static function webhook(string $channel): ?string
    {
        return app(AlertWebhooks::class)->url($channel);
    }
}
