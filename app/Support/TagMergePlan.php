<?php

namespace App\Support;

/**
 * O que fazer quando o admin renomeia uma etiqueta.
 *
 * Renomear "natl" para "natal" numa lista onde "natal" ja existe nao pode ser um
 * erro de validacao: a pagina de gestao existe precisamente para corrigir esse
 * engano, e recusar deixava o admin com os dois nomes e sem saida. O certo e
 * reapontar os usos e ficar com uma linha so.
 *
 * A decisao vive aqui, fora do servico e da base de dados, pela mesma razao do
 * ColorMergePlan: o risco desta operacao esta em ESCOLHER, e uma escolha
 * testavel sem BD e uma escolha que se le. Os dois passos que a aplicam
 * (reapontar o pivot, apagar a origem) sao SQL trivial.
 */
class TagMergePlan
{
    private function __construct(
        public readonly string $name,
        public readonly string $slug,
        /** Id da etiqueta que sobrevive; null quando e so mudar o nome. */
        public readonly ?int $mergeInto,
    ) {}

    /**
     * @param  int  $tagId  a etiqueta que esta a ser editada
     * @param  string  $newName  o nome escrito pelo admin
     * @param  array<string, int>  $slugsInScope  slug => id, do mesmo ambito,
     *                                            incluindo a propria
     */
    public static function for(int $tagId, string $newName, array $slugsInScope): self
    {
        $name = trim($newName);
        $slug = str($name)->slug()->value();

        $occupant = $slugsInScope[$slug] ?? null;

        /*
         * `$occupant === $tagId` e o caso mais comum e o mais facil de errar:
         * "Natal" -> "natal" da o mesmo slug, portanto a propria etiqueta ocupa
         * o lugar. Fundir consigo mesma apagava-a. Aqui e so mudar o nome.
         */
        return new self(
            name: $name,
            slug: $slug,
            mergeInto: $occupant === null || $occupant === $tagId ? null : $occupant,
        );
    }

    public function isMerge(): bool
    {
        return $this->mergeInto !== null;
    }
}
