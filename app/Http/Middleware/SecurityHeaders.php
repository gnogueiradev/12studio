<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabecalhos de seguranca das respostas que passam pelo PHP.
 *
 * Vivem aqui e nao na configuracao do Nginx Proxy Manager de proposito: assim
 * viajam dentro da imagem, sao guardados por testes, e o rollback do Jenkins
 * reverte-os junto com o codigo a que pertencem. O que o NPM continua a ter de
 * fazer por sua conta e o redirect http -> https e o HSTS dos ficheiros
 * estaticos, que nunca chegam ao PHP.
 */
class SecurityHeaders
{
    /**
     * Politica de conteudo. Cada folga esta aqui por uma razao concreta:
     *
     *   style-src 'unsafe-inline' — os primitivos do Radix posicionam-se com
     *     atributos style= inline, e nao ha forma de os evitar sem reescrever a
     *     UI. E a folga de menor risco: um atacante que consiga injetar CSS nao
     *     consegue executar codigo.
     *   img-src blob: — as pre-visualizacoes de upload da galeria de produto
     *     usam URL.createObjectURL.
     *   img-src data: e font-src data: — icones e fontes embutidos pelo Vite.
     *
     * O script-src NAO leva 'unsafe-inline' — seria abdicar da unica coisa
     * para que o CSP aqui serve. O unico script inline da app vai autorizado
     * por hash (ver a constante abaixo).
     */
    private const CONTENT_SECURITY_POLICY = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
        "img-src 'self' data: blob:",
        "font-src 'self' data:",
        "style-src 'self' 'unsafe-inline'",
        "script-src 'self' '".self::THEME_SCRIPT_HASH."'",
        "connect-src 'self'",
    ];

    /**
     * O unico script inline da app: o que aplica o tema escuro antes do
     * primeiro pixel, no app.blade.php. Um hash e uma promessa sobre bytes
     * exatos — vale para aquele script e para mais nenhum, ao contrario do
     * 'unsafe-inline', que valeria tambem para um que alguem injetasse.
     *
     * Foi medido, nao adivinhado, e o app.blade.php foi mudado para o tornar
     * possivel: o tema chega ao script por um atributo do <html> em vez de ser
     * interpolado no corpo dele, senao o hash mudava com o cookie e era preciso
     * um por tema.
     *
     * Se o script mudar, o tests/Feature/InlineScriptPolicyTest.php falha com o
     * hash novo na mensagem de erro — e ele que torna isto seguro de manter.
     */
    private const THEME_SCRIPT_HASH = 'sha256-pf99VV58o/7L5eBHhk/Psn3QartrDgXPkddtm+kgaow=';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Redundante com o frame-ancestors do CSP, mas o CSP ainda esta em
        // Report-Only: ate ele passar a valer, e isto que impede clickjacking
        // do backoffice.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // O payment=() sai daqui na Fase 3: o Stripe precisa da Payment Request
        // API para o Apple Pay / Google Pay.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()',
        );

        if ($this->shouldSendStrictTransportSecurity($request)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($this->isHtml($response)) {
            $response->headers->set(
                'Content-Security-Policy-Report-Only',
                implode('; ', self::CONTENT_SECURITY_POLICY),
            );
        }

        return $response;
    }

    /**
     * As DUAS condicoes sao obrigatorias, e a segunda e a que interessa.
     *
     * Um HSTS enviado a partir de localhost fica gravado no browser para TODO o
     * localhost: os outros projetos da maquina passam a exigir HTTPS e so se
     * desfaz a mao, em chrome://net-internals/#hsts. Custa cinco minutos a cada
     * pessoa que corra a app em dev e nao ha aviso nenhum quando acontece.
     *
     * O isSecure() so responde a verdade por causa do trustProxies() do
     * bootstrap/app.php — sem ele o proxy termina o TLS e o PHP ve http.
     */
    private function shouldSendStrictTransportSecurity(Request $request): bool
    {
        return $request->isSecure() && app()->isProduction();
    }

    /**
     * O CSP so vai em respostas HTML.
     *
     * Nao e higiene: e orcamento. O bloco de cabecalhos tem de caber no buffer
     * com que o nginx le a resposta do php-fpm, e uma politica destas ocupa
     * perto de 300 bytes que nao fazem falta nenhuma num redirect do Inertia
     * nem numa resposta de asset. Ver tests/Feature/ResponseHeaderSizeTest.php.
     */
    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
