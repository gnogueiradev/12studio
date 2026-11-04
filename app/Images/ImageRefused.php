<?php

namespace App\Images;

use RuntimeException;

/**
 * Uma imagem que vem de fora e que nao entra. A mensagem e para o utilizador;
 * o motivo e o codigo que fica no log (`mime_invalido`, `ssrf_bloqueado`...).
 */
class ImageRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
