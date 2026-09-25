<?php

namespace App\Providers;

use App\Mcp\CurrentToken;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `scoped` e nao `singleton`: a cache de definicoes do SettingService
        // e por pedido. Num worker de filas, cada job arranca com a tabela
        // relida em vez de arrastar valores de horas antes.
        $this->app->scoped(SettingService::class);

        // As rotas do Passport NAO se registam sozinhas: o routes/ai.php
        // regista a mao so as quatro que o OAuth do claude.ai precisa, cada
        // uma com o seu porteiro. As outras — o JSON API de clientes e de
        // personal access tokens, o device flow — seriam atalhos a volta da
        // pagina de chaves (sem confirmar a password). Tem de ser no register: o
        // Passport regista as rotas no boot dele, que corre antes deste.
        Passport::ignoreRoutes();
        Passport::$deviceCodeGrantEnabled = false;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePassport();
        $this->configureRateLimiting();
    }

    /**
     * Passport serve so o MCP: chaves de API (personal access tokens) e, na
     * fase do claude.ai, OAuth. Dois scopes e mais nada.
     */
    protected function configurePassport(): void
    {
        Passport::tokensCan([
            ApiKeyService::SCOPE_READ => 'Ver produtos, stock, catálogo e encomendas',
            ApiKeyService::SCOPE_WRITE => 'Criar e alterar produtos, preços, stock, catálogo e encomendas',
        ]);

        // Tetos do JWT. O OAuth fica curto. A chave pessoal pode nao ter
        // validade, por isso o JWT dela dura 100 anos; a validade escolhida
        // (7/30/90 dias) vive no expires_at da linha e e o EnsureMcpToken que
        // a aplica. Revogar continua a matar qualquer uma na hora.
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::personalAccessTokensExpireIn(CarbonInterval::years(100));

        // Um cliente OAuth que nao peca scope nenhum leva so leitura — nunca
        // escrita por omissao.
        Passport::defaultScopes([ApiKeyService::SCOPE_READ]);

        Passport::authorizationView(fn (array $parameters) => $this->consentPage($parameters));
    }

    /**
     * O ecra "O Claude quer acesso ao 12studio". O destaque vai para o dominio
     * para onde o acesso e entregue (o redirect), e nao para o nome do cliente
     * — esse escolhe-o quem se registou.
     *
     * @param  array<string, mixed>  $parameters  Os que o AuthorizationController do Passport passa.
     */
    private function consentPage(array $parameters): Response
    {
        /** @var Client $client */
        $client = $parameters['client'];
        /** @var Request $request */
        $request = $parameters['request'];
        /** @var User $user */
        $user = $parameters['user'];
        /** @var array<int, Scope> $scopes */
        $scopes = $parameters['scopes'];

        $redirect = (string) ($request->query('redirect_uri') ?: ($client->redirect_uris[0] ?? ''));

        return Inertia::render('auth/oauth-authorize', [
            'clientName' => $client->name,
            'redirectHost' => parse_url($redirect, PHP_URL_HOST) ?: $redirect,
            'scopes' => collect($scopes)
                ->map(fn (Scope $scope): array => ['id' => $scope->id, 'description' => $scope->description])
                ->values()
                ->all(),
            'canWrite' => collect($scopes)->contains(fn (Scope $scope): bool => $scope->id === ApiKeyService::SCOPE_WRITE),
            'authToken' => (string) $parameters['authToken'],
            'csrfToken' => csrf_token(),
            'approveUrl' => route('passport.authorizations.approve'),
            'denyUrl' => route('passport.authorizations.deny'),
            'accountName' => $user->name,
        ])->toResponse($request);
    }

    protected function configureRateLimiting(): void
    {
        // Por token (e nao por IP): os pedidos do claude.ai vem todos dos
        // servidores da Anthropic, e um limite por IP juntava toda a gente.
        RateLimiter::for('mcp', function (Request $request): Limit {
            $user = $request->user();
            $key = $user instanceof User
                ? (CurrentToken::id($user) ?? 'user-'.$user->getKey())
                : 'ip-'.$request->ip();

            return Limit::perMinute(60)->by('mcp:'.$key);
        });

        // O registo dinamico e publico (e assim que o claude.ai se apresenta):
        // cinco por hora por IP chega para ligar, e nao para encher a tabela.
        RateLimiter::for('mcp-register', fn (Request $request): Limit => Limit::perHour(5)->by('mcp-register:'.$request->ip()));

        RateLimiter::for('mcp-token', fn (Request $request): Limit => Limit::perMinute(30)->by('mcp-token:'.$request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // As regras de forca valiam SO em producao, e fora dela o `null` nao
        // significa "sem regras": o Laravel cai no Password::min(8) dele. Ou
        // seja, um staging aceitava oito caracteres sem exigencia nenhuma de
        // complexidade — e um staging com dados a serio e um alvo a serio.
        Password::defaults(function (): ?Password {
            // Nos testes fica o default do framework. As factories e os testes
            // de autenticacao usam passwords simples, e endurecer aqui obrigava
            // a reescrever dezenas de testes sem provar nada sobre producao.
            if (app()->environment('testing')) {
                return null;
            }

            $rules = Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();

            // O uncompromised() consulta a HaveIBeenPwned por HTTP. Em producao
            // a espera vale a pena; em dev punha o formulario de password
            // dependente de haver rede, e a falhar sem explicacao quando nao ha.
            return app()->isProduction() ? $rules->uncompromised() : $rules;
        });
    }
}
