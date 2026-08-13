<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Definicoes editaveis pelo admin em runtime, guardadas na tabela
 * chave-valor `settings`. O que NAO muda sem deploy fica em config/shop.php —
 * este servico so trata do que o dono pode mudar sozinho.
 *
 * Este servico e deliberadamente burro: sabe ler, escrever e esquecer chaves,
 * e nao sabe que chaves existem. Quem conhece nomes, valores por omissao e
 * casts e o SettingService::currency() para a moeda e o
 * App\Services\PricingSettings para a calculadora de precos.
 */
class SettingService
{
    /** @var array<string, mixed> */
    private array $cache = [];

    private bool $loaded = false;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        $this->cache[$key] = $value;
    }

    /**
     * Grava varias chaves de uma vez. Uma seccao das definicoes (os precos sao
     * oito chaves) ou fica toda gravada ou nao fica nenhuma: meia tabela de
     * faixas nova e meia antiga daria precos que nao correspondem a
     * configuracao nenhuma.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                $this->set($key, $value);
            }
        });
    }

    /**
     * Apaga a chave e volta ao valor por omissao do config. Ao contrario do
     * resto do dominio, aqui apagar e mesmo apagar: uma definicao nao tem
     * historial comercial para preservar, e "repor os valores de fabrica" tem
     * de deixar a linha por escrever para o config voltar a mandar.
     */
    public function forget(string $key): void
    {
        Setting::query()->whereKey($key)->delete();

        unset($this->cache[$key]);
    }

    /**
     * Moeda em vigor. Cai para config('shop.currency') enquanto ninguem lhe
     * tiver mexido — a constante do ficheiro deixa de ser a autoridade mas
     * continua a ser o valor por omissao.
     */
    public function currency(): string
    {
        return (string) $this->get('currency', config('shop.currency', 'EUR'));
    }

    /**
     * Uma leitura por pedido: as definicoes sao poucas e leem-se em varios
     * sitios (middleware do Inertia, servicos), e o SQLite tem um so escritor
     * — nao vale a pena bater na tabela de cada vez.
     *
     * A tabela pode nao estar la: `HandleInertiaRequests` le daqui em TODOS
     * os pedidos, incluindo num deploy antes de as migracoes correrem. Um
     * simbolo de moeda nao pode deitar a loja inteira abaixo — sem tabela,
     * fica tudo nos valores por omissao do config.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        // Marcar como carregado ANTES da consulta: se ela falhar, nao vale a
        // pena repetir o erro em cada get() do mesmo pedido.
        $this->loaded = true;

        try {
            $this->cache = Setting::query()->pluck('value', 'key')->all();
        } catch (QueryException) {
            $this->cache = [];
        }
    }
}
