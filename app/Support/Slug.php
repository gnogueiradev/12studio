<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class Slug
{
    /**
     * Slug ASCII unico a partir de um nome ("Caixa Ámbar" -> "caixa-ambar").
     * Em colisao acrescenta sufixo numerico ("caixa-ambar-2"). O registo
     * atual e ignorado em updates para o slug proprio nao colidir consigo.
     *
     * Conta com os apagados: o slug de um produto na reciclagem continua na
     * tabela, com o indice unico por cima. Sem os ver, um produto novo com o
     * mesmo nome saia daqui com um slug "livre" e rebentava no INSERT.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function unique(string $modelClass, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (
            $modelClass::query()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
