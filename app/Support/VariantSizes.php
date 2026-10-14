<?php

namespace App\Support;

/**
 * Os tamanhos de um produto, que vivem como texto livre em
 * `variants.size_label`.
 *
 * Nao ha tabela de tamanhos de proposito: o tamanho e o que o dono escreveu na
 * variante, e o grupo "10cm" sao as variantes que dizem 10cm. A chave e o que
 * torna isso fiavel — o mesmo tamanho escrito com outra caixa ou com um espaco
 * a mais continua a ser o mesmo grupo.
 *
 * Comparacao em PHP e nao em SQL: o LOWER() do SQLite so conhece ASCII, e
 * "MÉDIO" ficava longe de "médio".
 */
final class VariantSizes
{
    /** Sem espacos nas pontas, em minusculas. Sem tamanho e ''. */
    public static function key(?string $label): string
    {
        return mb_strtolower(trim((string) $label));
    }
}
