<?php

namespace App\Alerts;

/**
 * Os quatro canais do Discord. O valor e a chave em config('alerts.webhooks').
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

    public static function webhook(string $channel): ?string
    {
        $url = config("alerts.webhooks.{$channel}");

        return is_string($url) && $url !== '' ? $url : null;
    }
}
