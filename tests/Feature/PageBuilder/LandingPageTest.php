<?php

namespace Tests\Feature\PageBuilder;

use App\Models\User;
use App\PageBuilder\PageBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
    }

    private function natal(array $dates = [], bool $publish = true): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->createLanding(['name' => 'Natal', 'slug' => 'natal'] + $dates);
        $service->saveDraft($page, [['type' => 'banner', 'content' => [
            'pt' => ['title' => 'Feliz Natal'],
            'en' => ['title' => 'Merry Christmas'],
        ]]]);

        if ($publish) {
            $service->publish($page);
        }
    }

    public function test_a_published_landing_answers_in_every_language(): void
    {
        $this->natal();

        $this->get('/natal')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('store/page')
            ->where('page.title', 'Natal')
            ->where('page.blocks.0.data.title', 'Feliz Natal'));

        $this->get('/en/natal')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.data.title', 'Merry Christmas'));
    }

    public function test_a_draft_landing_is_only_seen_by_the_admin(): void
    {
        $this->natal(publish: false);

        $this->get('/natal')->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/natal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('page.draft', true));
    }

    public function test_a_landing_outside_its_dates_is_not_found(): void
    {
        Carbon::setTestNow('2026-11-15 12:00');

        $this->natal(['starts_at' => '2026-12-01 00:00', 'ends_at' => '2026-12-26 00:00']);
        $this->get('/natal')->assertNotFound();

        Carbon::setTestNow('2026-12-10 12:00');
        $this->get('/natal')->assertOk();

        Carbon::setTestNow('2026-12-27 12:00');
        $this->get('/natal')->assertNotFound();
    }

    public function test_unknown_slugs_are_not_found(): void
    {
        $this->get('/nao-existe')->assertNotFound();
        $this->get('/en/nao-existe')->assertNotFound();
    }
}
