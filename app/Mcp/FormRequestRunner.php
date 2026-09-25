<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

/**
 * Valida os argumentos de uma ferramenta com o MESMO FormRequest que o
 * formulario do backoffice usa.
 *
 * Nao so as regras: o FormRequest inteiro corre — o prepareForValidation (a
 * virgula dos precos passa a ponto), o authorize (so admins), as rules, os
 * after() (promocao abaixo do normal, revenda abaixo do preco de venda, a cor
 * existe neste filamento, a matriz da pelo menos uma peca) e as mensagens em
 * portugues. Extrair so as regras deixava estas quatro verificacoes para tras,
 * e o Claude passava a conseguir gravar o que o formulario recusa.
 *
 * Os parametros de rota que os pedidos leem (`variant`, `product`) sao
 * passados a mao, porque aqui nao ha rota.
 */
final class FormRequestRunner
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * @template TRequest of FormRequest
     *
     * @param  class-string<TRequest>  $class
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function validate(string $class, array $input, User $user, array $routeParameters = []): array
    {
        $base = Request::create('/mcp', 'POST', $input);

        /** @var TRequest $request */
        $request = $class::createFrom($base);
        $request->setContainer($this->container)
            ->setRedirector($this->container->make(Redirector::class));
        $request->setUserResolver(fn (): User => $user);

        $route = new Route('POST', '/mcp', []);
        $route->bind($base);

        foreach ($routeParameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(fn (): Route => $route);

        $request->validateResolved();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        return $validated;
    }
}
