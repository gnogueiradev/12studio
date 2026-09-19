<?php

namespace App\Mcp\Tools;

use App\Mcp\McpAuditor;
use App\Models\McpActivity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base de todas as ferramentas do 12studio.
 *
 * O `handle()` e daqui e e final: cada ferramenta escreve so o `run()`, e a
 * base trata do que nao pode ser esquecido numa delas — confirmar que quem
 * chama e admin, medir, auditar, e transformar erros em respostas que o
 * Claude entende (sem stack traces).
 */
abstract class StudioTool extends Tool
{
    /**
     * Leituras nao guardam argumentos no rasto; as escritas sim.
     */
    protected bool $auditArguments = false;

    abstract protected function run(Request $request, User $user): Response|ResponseFactory;

    final public function handle(Request $request, McpAuditor $auditor): Response|ResponseFactory
    {
        $started = hrtime(true);
        $user = $request->user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            $auditor->record($user instanceof User ? $user : null, $this->name(), McpActivity::RESULT_DENIED);

            return Response::error('Sem permissão.');
        }

        $denied = $this->deny($user);

        if ($denied !== null) {
            $auditor->record($user, $this->name(), McpActivity::RESULT_DENIED);

            return Response::error($denied);
        }

        $arguments = $this->auditArguments ? $request->all() : null;

        try {
            $response = $this->run($request, $user);
            $result = $this->isError($response) ? McpActivity::RESULT_ERROR : McpActivity::RESULT_OK;
        } catch (AuthorizationException) {
            $this->audit($auditor, $user, McpActivity::RESULT_DENIED, $arguments, $started);

            return Response::error('Sem permissão.');
        } catch (ValidationException $exception) {
            $this->audit($auditor, $user, McpActivity::RESULT_VALIDATION_ERROR, $arguments, $started);

            return Response::error(
                'Dados inválidos: '.collect($exception->errors())->flatten()->implode(' '),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->audit($auditor, $user, McpActivity::RESULT_ERROR, $arguments, $started);

            return Response::error('Erro interno ao executar a ferramenta. Nada foi alterado além do que a resposta indicar.');
        }

        $this->audit($auditor, $user, $result, $arguments, $started, $this->changes);

        return $response;
    }

    /**
     * Antes/depois dos campos alterados, preenchido pelas escritas.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $changes = null;

    private function isError(Response|ResponseFactory $response): bool
    {
        return $response instanceof Response
            ? $response->isError()
            : $response->responses()->contains(fn (Response $item): bool => $item->isError());
    }

    /**
     * Razao para recusar esta chamada (ou null para seguir). As ferramentas
     * de escrita usam-na para o scope e o limite de escritas.
     */
    protected function deny(User $user): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>|null  $arguments
     * @param  array<string, mixed>|null  $changes
     */
    private function audit(
        McpAuditor $auditor,
        User $user,
        string $result,
        ?array $arguments,
        int|float $started,
        ?array $changes = null,
    ): void {
        $auditor->record(
            $user,
            $this->name(),
            $result,
            $arguments,
            $changes,
            (int) ((hrtime(true) - $started) / 1_000_000),
        );
    }
}
