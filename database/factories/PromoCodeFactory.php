<?php

namespace Database\Factories;

use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TESTE'.Str::upper(Str::random(8)),
            'type' => PromoCode::TYPE_FIXED,
            'value' => 500,
            'min_subtotal_cents' => 0,
            'starts_at' => null,
            'expires_at' => null,
            'max_uses' => null,
            'single_use_per_email' => false,
            'channel' => 'instagram',
            'email' => null,
            'is_active' => true,
        ];
    }

    public function percent(int $value): static
    {
        return $this->state(['type' => PromoCode::TYPE_PERCENT, 'value' => $value]);
    }
}
