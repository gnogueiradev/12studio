<?php

namespace App\Providers;

use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
