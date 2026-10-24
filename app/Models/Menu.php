<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um menu da montra. Os itens tem a forma descrita no StoreMenus.
 *
 * @property int $id
 * @property string $location
 * @property array<int, array<string, mixed>> $items
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['location', 'items'])]
class Menu extends Model
{
    public const LOCATIONS = ['header', 'footer'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
        ];
    }
}
