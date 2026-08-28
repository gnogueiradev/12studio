<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Teste-guarda de infraestrutura, na familia do NginxVhostConfigTest e do
 * PhpUploadLimitsTest.
 *
 * O .env de producao tem a APP_KEY, a password do Redis, a do admin e as
 * credenciais de SMTP. Nasce do docker/bootstrap-env.sh, e estava a ser criado
 * com 644 — legivel por qualquer utilizador do host.
 *
 * Isto le o script em vez de o correr: e um shell script que so faz sentido
 * dentro do container do deploy, e o que interessa guardar e a decisao.
 */
class EnvFilePermissionsTest extends TestCase
{
    public function test_the_env_file_is_not_world_readable(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*chmod\s+6[45]\d\s/m',
            $this->script(),
            'O .env de producao voltou a ser criado legivel por outros utilizadores do host.',
        );
    }

    public function test_the_env_file_is_closed_to_its_owner(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\s*chmod\s+600\s+"\$PARTIAL"/m',
            $this->script(),
            'O bootstrap-env.sh deixou de fechar o .env ao dono.',
        );
    }

    /**
     * Sem o chown, o chmod 600 deixa o ficheiro do root e o PHP — que corre
     * como `application` — nao o consegue ler. Isso nao da um erro de
     * permissoes legivel: da uma app sem APP_KEY que rebenta em cada pedido.
     * As duas linhas so fazem sentido juntas.
     */
    public function test_the_owner_is_the_user_that_runs_php(): void
    {
        $script = $this->script();

        $this->assertMatchesRegularExpression(
            '/^\s*chown\s+"\$APP_UID:\$APP_GID"\s+"\$PARTIAL"/m',
            $script,
            'Falta o chown: com 600 e sem dono certo, o PHP fica sem conseguir ler o .env.',
        );

        $this->assertMatchesRegularExpression(
            '/APP_UID=\$\(id -u application/',
            $script,
            'O uid passou a ser um numero escrito a mao: se a imagem base o mudar, o deploy parte em silencio.',
        );
    }

    /**
     * A guarda que mais interessa — e a que fala de ORDEM, nao de conteudo.
     *
     * O ficheiro e escrito com um nome provisorio, fechado, e so depois passa a
     * chamar-se .env. Ao contrario, uma falha no chown deixava o .env no sitio
     * com permissoes largas; e como o script nao toca num .env que ja exista, a
     * repeticao do deploy dizia "ja existe" e NUNCA corrigia nada. O modo de
     * falha era silencioso e permanente.
     */
    public function test_permissions_are_set_before_the_file_takes_its_final_name(): void
    {
        $script = $this->script();

        $chmod = $this->offsetOf($script, '/^\s*chmod\s+600\s+"\$PARTIAL"/m', 'o chmod do rascunho');
        $chown = $this->offsetOf($script, '/^\s*chown\s+"\$APP_UID:\$APP_GID"\s+"\$PARTIAL"/m', 'o chown do rascunho');
        $move = $this->offsetOf($script, '/^\s*mv\s+"\$PARTIAL"\s+"\$TARGET"/m', 'o mv para o nome final');

        $this->assertLessThan($move, $chmod, 'O chmod acontece depois do mv: ha uma janela com o .env aberto a toda a gente.');
        $this->assertLessThan($move, $chown, 'O chown acontece depois do mv: ha uma janela com o .env do dono errado.');
    }

    /**
     * Sem o trap, uma falha entre a escrita e o mv deixava um `.env.partial`
     * com todos os segredos la dentro, no diretorio de estado do servidor.
     */
    public function test_a_failed_run_leaves_no_draft_behind(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\s*trap\s+\'rm -f "\$PARTIAL"\'\s+EXIT/m',
            $this->script(),
            'Falta o trap: um deploy falhado deixa um rascunho com segredos no servidor.',
        );
    }

    private function offsetOf(string $script, string $pattern, string $what): int
    {
        if (preg_match($pattern, $script, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->fail("Nao encontrei {$what} no bootstrap-env.sh.");
        }

        return $matches[0][1];
    }

    private function script(): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'docker'.DIRECTORY_SEPARATOR.'bootstrap-env.sh';

        $this->assertFileExists($path, 'docker/bootstrap-env.sh em falta — o Jenkinsfile chama-o no stage Build.');

        return (string) file_get_contents($path);
    }
}
