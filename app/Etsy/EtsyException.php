<?php

namespace App\Etsy;

use RuntimeException;

/**
 * Tudo o que corre mal ao falar com a Etsy.
 *
 * A mensagem e escrita para quem esta do lado de ca — o dono no backoffice, o
 * Claude no MCP — e por isso pode ser mostrada tal e qual: diz o que aconteceu
 * e o que fazer, nunca um token nem um stack trace.
 */
class EtsyException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('A integração com a Etsy está desligada no servidor (ETSY_ENABLED=false). Nada foi enviado.');
    }

    public static function notConfigured(): self
    {
        return new self('Falta a chave da app da Etsy no servidor (ETSY_KEYSTRING e ETSY_SHARED_SECRET). Ver Definições → Etsy.');
    }

    public static function notConnected(): self
    {
        return new self('Ainda não há nenhuma loja da Etsy ligada. Liga-a no backoffice, em Definições → Etsy.');
    }

    public static function reconnect(): self
    {
        return new self('A Etsy deixou de aceitar a ligação à loja. Volta a ligar em Definições → Etsy.');
    }

    public static function unreachable(string $reason): self
    {
        return new self("Não foi possível falar com a Etsy ({$reason}). Não se sabe se o pedido chegou — confirma antes de repetir.");
    }
}
