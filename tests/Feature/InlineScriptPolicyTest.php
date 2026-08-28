<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O CSP autoriza scripts inline por hash e nao por 'unsafe-inline'. Um hash e
 * uma promessa sobre bytes exatos: muda uma virgula no script e o browser
 * deixa de o executar.
 *
 * Este teste e o que torna essa promessa segura de manter. Percorre as paginas
 * reais da app, extrai cada <script> sem src, calcula o sha256 e exige que a
 * politica o conheca. Quem editar o script do tema no app.blade.php ve este
 * teste falhar COM O HASH NOVO na mensagem — em vez de descobrir em producao
 * que o site abre sempre em tema claro.
 *
 * Serve tambem de guarda contra scripts inline novos: qualquer um que apareca
 * numa pagina falha aqui, e a resposta certa e quase sempre move-lo para um
 * ficheiro, nao acrescentar mais um hash.
 */
class InlineScriptPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function pagesRenderedByBlade(): array
    {
        return [
            'montra' => ['/', false],
            'login' => ['/login', false],
            'painel de admin' => ['/admin', true],
            'produtos' => ['/admin/produtos', true],
            'perfil' => ['/settings/profile', true],
        ];
    }

    #[DataProvider('pagesRenderedByBlade')]
    public function test_every_inline_script_is_allowed_by_hash(string $uri, bool $needsAdmin): void
    {
        if ($needsAdmin) {
            $this->actingAs(User::factory()->admin()->create());
        }

        $response = $this->get($uri);

        $response->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        foreach ($this->inlineScriptsIn($response->getContent()) as $script) {
            $hash = base64_encode(hash('sha256', $script, true));

            $this->assertStringContainsString(
                "'sha256-{$hash}'",
                $policy,
                sprintf(
                    "A pagina %s tem um script inline que o CSP nao autoriza.\n".
                    "Se foi o script do tema que mudou, poe este hash no SecurityHeaders:\n\n".
                    "    'sha256-%s'\n\n".
                    "Se e um script NOVO, move-o para um ficheiro em vez de acrescentar outro hash.\n".
                    "Conteudo:\n%s",
                    $uri,
                    $hash,
                    $script,
                ),
            );
        }
    }

    /**
     * O tema escolhido chega ao script por um atributo do <html> e nao
     * interpolado no corpo dele. Se voltar para dentro do script, o hash passa
     * a depender do cookie e a autorizacao por hash deixa de ser possivel — foi
     * por isso que o app.blade.php mudou.
     */
    public function test_the_theme_script_does_not_change_with_the_appearance_cookie(): void
    {
        $hashes = [];

        foreach (['system', 'light', 'dark'] as $appearance) {
            $response = $this->withUnencryptedCookie('appearance', $appearance)->get('/');

            $response->assertOk();

            $scripts = $this->inlineScriptsIn($response->getContent());

            $hashes[$appearance] = array_map(
                fn (string $script): string => base64_encode(hash('sha256', $script, true)),
                $scripts,
            );
        }

        $this->assertSame(
            $hashes['system'],
            $hashes['dark'],
            'O script inline mudou com o cookie de tema: a autorizacao por hash do CSP deixa de funcionar.',
        );

        $this->assertSame($hashes['system'], $hashes['light']);
    }

    /**
     * Tipos que o browser EXECUTA. Um <script> com outro `type` — o
     * application/json com que o Inertia entrega as props da pagina, por
     * exemplo — e um data block: o browser le-o como texto e nunca o corre,
     * por isso o script-src nao se lhe aplica e nao ha violacao nenhuma a
     * reportar. Verificado no browser: a consola so acusava o script do tema.
     *
     * Incluir data blocks aqui obrigaria a por no CSP o hash de um payload que
     * muda a cada pedido — impossivel — para proteger contra nada.
     *
     * @var array<int, string>
     */
    private const EXECUTABLE_TYPES = ['module', 'text/javascript', 'application/javascript'];

    /**
     * @return array<int, string>
     */
    private function inlineScriptsIn(string $html): array
    {
        // Sem src= — os externos sao cobertos por script-src 'self'.
        preg_match_all(
            '#<script\b(?![^>]*\bsrc=)([^>]*)>(.*?)</script>#s',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        $scripts = [];

        foreach ($matches as [, $attributes, $body]) {
            if (preg_match('#\btype\s*=\s*["\']([^"\']+)["\']#i', $attributes, $type) === 1
                && ! in_array(mb_strtolower(trim($type[1])), self::EXECUTABLE_TYPES, true)) {
                continue;
            }

            $scripts[] = $body;
        }

        return $scripts;
    }
}
