<?php

namespace App\Jobs;

use App\Etsy\EtsyListingSync;
use App\Models\EtsyListingImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Uma fotografia de um produto, a caminho do anuncio dele na Etsy.
 *
 * Uma so tentativa: quem repete uma criacao na Etsy sem saber se a primeira
 * chegou fica com duas. O que falhar fica escrito no anuncio e volta a
 * tentar-se no envio seguinte.
 */
class UploadEtsyListingImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(
        public int $imageId,
    ) {}

    public function handle(EtsyListingSync $sync): void
    {
        $model = EtsyListingImage::query()->find($this->imageId);

        if ($model !== null) {
            $sync->uploadImage($model);
        }
    }
}
