<?php

/**
 * Sanitizacao do HTML que o admin escreve no editor de descricoes.
 *
 * So o admin escreve descricoes, mas elas acabam renderizadas com
 * dangerouslySetInnerHTML na montra — defesa em profundidade: a lista branca
 * abaixo e a unica coisa que sobrevive a `Purifier::clean($html, 'product')`.
 *
 * @link http://htmlpurifier.org/live/configdoc/plain.html
 */
return [

    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    // Cache do serializer do HTMLPurifier. Vive no `storage/` (volume
    // persistente em producao, como o ficheiro SQLite) e esta no .gitignore.
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0755,

    'settings' => [

        // Perfil unico do projeto. Espelha exatamente o que a barra do editor
        // (resources/js/components/admin/rich-text-editor.tsx) consegue
        // produzir — o que nao esta aqui e removido em silencio ao gravar.
        'product' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p,br,strong,em,u,s,h2,h3,ul,ol,li,blockquote,a[href|title]',
            // O editor ja emite <p>; deixar o autoparagraph ligado envolvia
            // outra vez o que ja vinha envolvido.
            'AutoFormat.AutoParagraph' => false,
            'AutoFormat.RemoveEmpty' => true,
            // Links para fora abrem em separador novo e levam
            // rel="noreferrer noopener" automaticamente (TargetNoopener e
            // TargetNoreferrer sao true por omissao no HTMLPurifier).
            'HTML.TargetBlank' => true,
            // Sem esquemas exoticos: nada de javascript: nem data:.
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
        ],

    ],

];
