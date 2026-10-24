<?php

namespace App\Images;

/**
 * Um modelo com uma imagem no disco a que o GenerateImageVariants sabe fazer
 * as versoes reduzidas: hoje as fotografias de produto, amanha a biblioteca de
 * imagens dos blocos da montra.
 *
 * O modelo guarda o resultado em tres colunas — width, height e variants (json)
 * — com a forma devolvida pelo ImageVariants::generate().
 */
interface HasImageVariants
{
    public function imageDisk(): string;

    public function imagePath(): string;
}
