<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComingSoonTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_store_shows_the_landing(): void
    {
        config(['access.store_open' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('coming-soon')
                ->missing('products'));
    }

    public function test_open_store_shows_the_montra(): void
    {
        config(['access.store_open' => true]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->has('products'));
    }
}
