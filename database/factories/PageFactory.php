<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'landing',
            'key' => null,
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'status' => 'draft',
            'seo' => null,
            'draft_blocks' => [],
            'published_blocks' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function published(array $blocks = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'published',
            'draft_blocks' => $blocks,
            'published_blocks' => $blocks,
            'published_at' => now(),
        ]);
    }
}
