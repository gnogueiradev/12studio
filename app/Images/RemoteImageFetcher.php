<?php

namespace App\Images;

use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Descarrega uma imagem de um URL dado de fora, sem deixar que esse URL aponte
 * o servidor para a rede interna (SSRF).
 *
 *   - so https, porta 443, sem credenciais no URL;
 *   - todos os enderecos do nome tem de ser publicos, e o pedido vai fixado a
 *     esses (CURLOPT_RESOLVE): sem isso o cURL resolvia outra vez, e um DNS
 *     malicioso podia responder com um IP publico aqui e um interno la;
 *   - cada redirect e validado do zero, com os mesmos criterios.
 *
 * Vai para ficheiro (`sink`) e nao em `stream: true`, de proposito: esse troca
 * o cURL pelo StreamHandler, que ignora as opcoes `curl` — e o IP fixado com
 * elas, sem dar erro nenhum.
 */
class RemoteImageFetcher
{
    private const MAX_REDIRECTS = 3;

    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    /**
     * O que nao e internet publica. Em IPv6 so 2000::/3 esta atribuido, por isso
     * aqui so vao as excecoes dentro dele: as formas que embrulham um IPv4 e os
     * intervalos antigos ficam todos de fora de uma vez.
     */
    private const GLOBAL_IPV6 = '2000::/3';

    private const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '168.63.129.16/32', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '2001::/32', '2001:db8::/32', '2002::/16',
    ];

    public function __construct(
        private HostResolver $resolver,
    ) {}

    /**
     * @return string caminho de um ficheiro temporario, que passa a ser de quem chama
     *
     * @throws ImageRefused
     */
    public function fetch(string $url): string
    {
        // Sem cURL o Guzzle cairia no StreamHandler, e o IP fixado desaparecia
        // em silencio. Antes nao descarregar.
        if (! extension_loaded('curl')) {
            throw new RuntimeException('A extensao curl e obrigatoria para descarregar imagens.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp-img-');

        if ($path === false) {
            throw new RuntimeException('Nao foi possivel criar um ficheiro temporario.');
        }

        try {
            $this->download($url, $path);
        } catch (ImageRefused $refusal) {
            @unlink($path);

            throw $refusal;
        } catch (Throwable) {
            @unlink($path);

            // Sem a excecao original: as do cliente HTTP trazem o URL inteiro na
            // mensagem (um Location malformado, por exemplo), e a mensagem ia
            // parar ao log e ao Discord.
            throw new ImageRefused('download_falhou', 'Não foi possível descarregar a imagem desse URL.');
        }

        return $path;
    }

    private function download(string $url, string $path): void
    {
        $max = (int) config('mcp.images.max_bytes');
        $deadline = microtime(true) + self::TIMEOUT;

        for ($redirects = 0; ; $redirects++) {
            $response = $this->request($url, $this->guard($url), $path, $max, $deadline);

            if (! $response->redirect()) {
                break;
            }

            $location = $response->header('Location');

            if ($location === '') {
                throw new ImageRefused('download_falhou', 'O servidor da imagem respondeu com um redirect sem destino.');
            }

            if ($redirects === self::MAX_REDIRECTS) {
                throw new ImageRefused('redirects_a_mais', 'O URL da imagem tem demasiados redirects (máximo '.self::MAX_REDIRECTS.').');
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        if (! $response->successful()) {
            throw new ImageRefused('download_falhou', "O servidor da imagem respondeu com o erro {$response->status()}.");
        }

        clearstatcache(true, $path);

        if ((int) $response->header('Content-Length') > $max || (int) filesize($path) > $max) {
            throw $this->tooBig($max);
        }
    }

    /**
     * @param  array<int, string>  $pins
     */
    private function request(string $url, array $pins, string $path, int $max, float $deadline): Response
    {
        $cut = false;

        try {
            return Http::withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(max(1, (int) ceil($deadline - microtime(true))))
                ->accept('image/*')
                ->sink($path)
                ->withOptions([
                    // Um proxy resolvia o nome ele proprio, e o IP fixado nao servia de nada.
                    'proxy' => '',
                    // O corte conta o que vem pela rede: com gzip, 8 MB comprimidos
                    // podiam encher o disco. Sem descomprimir, nao passam por imagem.
                    'decode_content' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => $pins,
                        CURLOPT_NOPROGRESS => false,
                        // O valor devolvido e o que aborta. A opcao `progress` do
                        // Guzzle nao serve: deita-o fora, e uma excecao la dentro
                        // so aparece quando o download acaba. O Guzzle 7.11 marca
                        // estas duas como obsoletas e o 8 recusa-as: ao subir de
                        // versao major, isto tem de passar para um sink proprio.
                        CURLOPT_PROGRESSFUNCTION => function ($handle, int|float $total, int|float $received) use ($max, &$cut): int {
                            $cut = $total > $max || $received > $max;

                            return $cut ? 1 : 0;
                        },
                    ],
                ])
                ->get($url);
        } catch (ConnectionException|TransferException $exception) {
            if ($cut) {
                throw $this->tooBig($max);
            }

            // A mensagem da excecao traz o URL inteiro: nao sai daqui.
            if (str_contains($exception->getMessage(), 'cURL error 28') || str_contains($exception->getMessage(), 'timed out')) {
                throw new ImageRefused('timeout', 'O servidor da imagem demorou demasiado a responder.');
            }

            throw new ImageRefused('download_falhou', 'Não foi possível descarregar a imagem desse URL.');
        }
    }

    /**
     * Valida um destino e devolve a entrada para o CURLOPT_RESOLVE.
     *
     * @return array<int, string>
     *
     * @throws ImageRefused
     */
    private function guard(string $url): array
    {
        $parts = str_contains($url, '\\') ? false : parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new ImageRefused('url_invalido', 'O image_url tem de ser um endereço https válido.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 443) !== 443) {
            throw new ImageRefused('url_invalido', 'O image_url não pode trazer credenciais nem usar outra porta além da 443.');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublic([$host]);

            return [];
        }

        // Um nome com outros caracteres ou acabado em ponto podia ser lido de
        // uma maneira aqui e de outra pelo cURL, e escapar ao IP fixado.
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $host) !== 1) {
            throw new ImageRefused('url_invalido', 'O image_url tem um nome de servidor inválido.');
        }

        // 2130706433 e 0x7f.0.0.1 sao 127.0.0.1 para quase toda a gente, e nao
        // ha nenhum dominio de topo so com numeros.
        if (preg_match('/(^|\.)(\d+|0x[0-9a-f]+)$/', $host) === 1) {
            throw $this->blocked();
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new ImageRefused('dns_falhou', 'O servidor desse image_url não existe.');
        }

        $this->assertPublic($addresses);

        $list = implode(',', array_map(
            fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $addresses,
        ));

        return ["{$host}:443:{$list}"];
    }

    /**
     * Basta um interno: o cURL pode escolher qualquer um dos da lista.
     *
     * @param  array<int, string>  $addresses
     */
    private function assertPublic(array $addresses): void
    {
        foreach ($addresses as $address) {
            $outsideGlobalIpv6 = str_contains($address, ':') && ! IpUtils::checkIp($address, self::GLOBAL_IPV6);

            if (filter_var($address, FILTER_VALIDATE_IP) === false || $outsideGlobalIpv6 || IpUtils::checkIp($address, self::BLOCKED_RANGES)) {
                throw $this->blocked();
            }
        }
    }

    private function blocked(): ImageRefused
    {
        return new ImageRefused('ssrf_bloqueado', 'Esse image_url aponta para um endereço interno: só se aceitam endereços públicos.');
    }

    private function tooBig(int $max): ImageRefused
    {
        return new ImageRefused('demasiado_grande', 'A imagem passa do máximo de '.round($max / 1024 / 1024, 1).' MB.');
    }
}
