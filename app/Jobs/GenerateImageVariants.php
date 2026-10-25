<?php

namespace App\Jobs;

use App\Images\HasImageVariants;
use App\Images\ImageVariants;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Faz as versoes reduzidas de uma imagem acabada de carregar.
 *
 * Na fila e nao no pedido do upload: reduzir uma foto grande leva segundos, e
 * o admin nao tem de ficar a espera disso para continuar a editar o produto.
 * Ate correr, a montra serve o original.
 */
class GenerateImageVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Classe e id em vez do modelo serializado: se a imagem for apagada antes
     * de o job correr, ele encontra nada e sai sem erro.
     *
     * @param  class-string<Model&HasImageVariants>  $model
     */
    public function __construct(
        public string $model,
        public int $id,
    ) {}

    public static function for(Model&HasImageVariants $image): self
    {
        return new self($image::class, (int) $image->getKey());
    }

    public function handle(ImageVariants $variants): void
    {
        /** @var (Model&HasImageVariants)|null $image */
        $image = $this->model::query()->find($this->id);

        if ($image === null) {
            return;
        }

        $old = $image->getAttribute('variants');

        try {
            $result = $variants->generate($image->imageDisk(), $image->imagePath());
        } catch (Throwable $exception) {
            // Uma imagem que o descodificador nao le nao merece tres
            // tentativas nem um alerta no #sistema: a montra continua a
            // mostrar o original.
            Log::warning('Sem versoes reduzidas para a imagem.', [
                'model' => $this->model,
                'id' => $this->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $image->forceFill($result)->saveQuietly();

        // Regerar (images:variants --force) deixa os ficheiros antigos para
        // tras se tiverem outros nomes; os de nome igual ja foram reescritos.
        if (is_array($old)) {
            $kept = array_merge(array_values($result['variants']['webp']), [$result['variants']['og']]);
            $stale = array_diff(
                array_merge(array_values((array) ($old['webp'] ?? [])), [(string) ($old['og'] ?? '')]),
                $kept,
                [''],
            );
            $variants->delete($image->imageDisk(), ['webp' => array_values($stale)]);
        }
    }
}
