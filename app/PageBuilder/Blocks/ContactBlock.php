<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\RenderContext;
use App\Services\StoreSettings;

class ContactBlock extends Block
{
    public function type(): string
    {
        return 'contact';
    }

    public function label(): string
    {
        return 'Contacto';
    }

    public function description(): string
    {
        return 'Formulário de mensagem e os contactos da loja.';
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::textarea('text', 'Texto')->translatable()->max(400),
            Field::toggle('show_form', 'Mostrar o formulário', true),
            Field::toggle('show_details', 'Mostrar email, telefone e redes', true),
        ];
    }

    public function resolve(array $data, RenderContext $context): array
    {
        return $data + ['responseHours' => app(StoreSettings::class)->toForm()['response_hours']];
    }
}
