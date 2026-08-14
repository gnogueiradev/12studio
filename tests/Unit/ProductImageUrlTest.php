<?php

namespace Tests\Unit;

use App\Models\ProductImage;
use Tests\TestCase;

/**
 * Teste-guarda do URL das fotografias.
 *
 * O disco publico ja construiu o URL a partir do APP_URL, e o resultado era uma
 * avaria silenciosa: com o `.env` a dizer `localhost:8000` e a app servida pelo
 * Herd em `12studio.test`, cada `<img>` apontava para um host onde nao estava
 * nada a escuta — a pagina desenhava e as fotografias davam 404, na listagem e
 * na galeria do modal ao mesmo tempo.
 *
 * O ProductImageTest nao apanha isto: usa `Storage::fake('public')`, que
 * substitui a configuracao do disco pela do disco falso. Por isso o teste vive
 * aqui, contra a configuracao a serio.
 */
class ProductImageUrlTest extends TestCase
{
    public function test_the_url_is_relative_to_whatever_host_served_the_page(): void
    {
        $image = new ProductImage(['path' => 'products/abc123.jpg']);

        $this->assertSame('/storage/products/abc123.jpg', $image->url);
    }

    public function test_the_url_does_not_carry_a_host(): void
    {
        // Com o APP_URL a apontar para outro sitio, o URL da imagem nao pode
        // mudar: e essa dependencia que partia as fotografias.
        config(['app.url' => 'http://localhost:8000']);

        $image = new ProductImage(['path' => 'products/abc123.jpg']);

        $this->assertStringNotContainsString('http', $image->url);
    }
}
