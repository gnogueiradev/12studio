<?php

namespace App\Etsy;

/**
 * Ja ha outro processo a fazer isto mesmo (a enviar este anuncio). Nao e uma
 * falha da Etsy nem um erro nosso: quem pediu a mao e avisado, e quem corre
 * sozinho (a sincronizacao) simplesmente deixa passar esta vez.
 */
class EtsyBusyException extends EtsyException {}
