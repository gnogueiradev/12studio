<?php

namespace App\Console\Commands;

use App\Jobs\GenerateImageVariants;
use App\Models\ProductImage;
use Illuminate\Console\Command;

class ImageVariantsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'images:variants {--force : Refaz tambem as imagens que ja tem versoes reduzidas}';

    /**
     * @var string
     */
    protected $description = 'Poe na fila as versoes reduzidas (WebP e Open Graph) das fotografias de produto';

    /**
     * Para as fotografias carregadas antes de as versoes reduzidas existirem,
     * e para as refazer se as medidas mudarem (--force). Corre-se uma vez
     * depois do deploy; dai para a frente, cada upload trata das suas.
     */
    public function handle(): int
    {
        $query = ProductImage::query()
            ->when(! $this->option('force'), fn ($query) => $query->whereNull('variants'))
            ->orderBy('id');

        $count = 0;

        $query->chunkById(100, function ($images) use (&$count): void {
            foreach ($images as $image) {
                dispatch(GenerateImageVariants::for($image));
                $count++;
            }
        });

        $this->info($count === 0
            ? 'Todas as fotografias ja tem versoes reduzidas.'
            : "{$count} fotografia(s) na fila.");

        return self::SUCCESS;
    }
}
