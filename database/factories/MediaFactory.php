<?php

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'path' => 'media/'.fake()->uuid().'.jpg',
            'original_name' => 'foto.jpg',
            'alt' => null,
            'mime' => 'image/jpeg',
            'size' => 1024,
            'width' => 1600,
            'height' => 1200,
            'variants' => null,
        ];
    }
}
