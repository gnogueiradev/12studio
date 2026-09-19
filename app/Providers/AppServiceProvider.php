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
use Laravel\Passport\Passport;

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

        // As rotas /oauth/* do Passport ficam desligadas ate o OAuth do
        // claude.ai estar feito (com o ecra de consentimento e os redirects
        // fechados). Ate la, a unica forma de ter um token e a pagina de
        // chaves — que pede password e segundo fator. Tem de ser no register:
        // o Passport regista as rotas no boot dele, que corre antes deste.
        Passport::ignoreRoutes();
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

        // Tetos. A chave pessoal leva ainda a validade escolhida (7/30/90
        // dias), que o EnsureMcpToken aplica; o JWT nunca dura mais que isto.
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::personalAccessTokensExpireIn(CarbonInterval::days(max(ApiKeyService::LIFETIMES)));
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
