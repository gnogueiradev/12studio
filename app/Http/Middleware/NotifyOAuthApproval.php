<?php

namespace App\Http\Middleware;

use App\Alerts\SecurityAlerts;
use App\Mail\ApiKeyCreatedMail;
use App\Models\User;
use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Email ao dono da conta quando ele aprova uma aplicacao OAuth — o mesmo
 * aviso de quando cria uma chave de API. Se nao foi ele, e por aqui que da
 * por isso. Aprovar e recusar chegam tambem ao #seguranca do Discord.
 *
 * Le o pedido de autorizacao da sessao ANTES de o Passport o consumir, e so
 * avisa quando a aprovacao correu (redirect com `code=`) ou a recusa
 * (redirect com `error=access_denied`).
 */
class NotifyOAuthApproval
{
    public function __construct(
        private SecurityAlerts $alerts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $pending = $this->pendingRequest($request);

        $response = $next($request);

        $user = $request->user();
        $location = (string) $response->headers->get('Location');

        if ($pending === null || ! $user instanceof User) {
            return $response;
        }

        $client = $pending->getClient()->getName().' ('.(parse_url($location, PHP_URL_HOST) ?: '?').')';

        if (str_contains($location, 'code=')) {
            $scopes = array_map(
                fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
                $pending->getScopes(),
            );
            $access = in_array(ApiKeyService::SCOPE_WRITE, $scopes, true) ? ApiKeyService::ACCESS_WRITE : ApiKeyService::ACCESS_READ;

            Mail::to($user)->send(new ApiKeyCreatedMail($user, $client, $access, null));
            $this->alerts->keyCreated($user, $client, $access, null, oauth: true);
        } elseif (str_contains($location, 'error=access_denied')) {
            $this->alerts->oauthDenied($user, $client);
        }

        return $response;
    }

    private function pendingRequest(Request $request): ?AuthorizationRequest
    {
        $serialized = $request->session()->get('authRequest');

        if (! is_string($serialized)) {
            return null;
        }

        try {
            $authRequest = unserialize($serialized, ['allowed_classes' => [
                AuthorizationRequest::class,
                Client::class,
                Scope::class,
                \Laravel\Passport\Bridge\User::class,
            ]]);
        } catch (Throwable) {
            return null;
        }

        return $authRequest instanceof AuthorizationRequest ? $authRequest : null;
    }
}
