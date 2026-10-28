<?php

namespace App\PageBuilder;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * O unico sitio que escreve paginas da montra. Tudo o que entra passa pelo
 * BlockSanitizer; publicar guarda uma revisao (as ultimas 20).
 */
class PageBuilderService
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private BlockSanitizer $sanitizer,
    ) {}

    /**
     * Grava o rascunho. O site publico nao muda.
     *
     * Um rascunho pode estar por acabar (um bloco acabado de juntar, com um
     * campo obrigatorio ainda vazio): so publicar exige os obrigatorios. O
     * resto — textos compridos demais, links inseguros, blocos fora do
     * template, os blocos obrigatorios do template — e recusado ja.
     *
     * @param  array<mixed>  $blocks
     * @param  array<mixed>|null  $seo
     */
    public function saveDraft(Page $page, array $blocks, ?array $seo = null): Page
    {
        $page->draft_blocks = $this->sanitizer->clean($blocks, $page->template(), requireFields: false);

        if ($seo !== null) {
            $page->seo = $this->seo($seo);
        }

        $page->save();

        return $page;
    }

    /** O rascunho passa a ser o que o publico ve. */
    public function publish(Page $page, ?User $user = null): Page
    {
        // De novo pelo sanitizer: um bloco pode ter ficado invalido desde que
        // o rascunho foi gravado (um bloco obrigatorio novo, um tipo que saiu).
        $blocks = $this->sanitizer->clean($page->draft_blocks, $page->template());

        DB::transaction(function () use ($page, $blocks, $user): void {
            $page->forceFill([
                'draft_blocks' => $blocks,
                'published_blocks' => $blocks,
                'status' => 'published',
                'published_at' => Carbon::now(),
            ])->save();

            $page->revisions()->create([
                'blocks' => $blocks,
                'seo' => $page->seo,
                'user_id' => $user?->id,
                'created_at' => Carbon::now(),
            ]);

            $keep = $page->revisions()->limit(PageRevision::KEEP)->pluck('id');
            $page->revisions()->getQuery()->whereNotIn('id', $keep)->delete();
        });

        return $page->refresh();
    }

    /** Tira a pagina do ar. O rascunho e as revisoes ficam. */
    public function unpublish(Page $page): Page
    {
        if ($page->type === 'system' && ! in_array($page->key, DefaultPages::DRAFTS, true)) {
            throw ValidationException::withMessages(['page' => 'Esta página não pode sair do ar: o site precisa dela.']);
        }

        $page->forceFill(['status' => 'unpublished'])->save();

        return $page;
    }

    /** Uma publicacao antiga volta a ser o rascunho (publica-se a seguir). */
    public function restore(Page $page, PageRevision $revision): Page
    {
        if ($revision->page_id !== $page->id) {
            throw ValidationException::withMessages(['revision' => 'Esta versão não é desta página.']);
        }

        return $this->saveDraft($page, $revision->blocks, $revision->seo);
    }

    /** Descarta o rascunho: volta ao que esta publicado. */
    public function discard(Page $page): Page
    {
        if ($page->published_blocks !== null) {
            $page->forceFill(['draft_blocks' => $page->published_blocks])->save();
        }

        return $page;
    }

    /**
     * @param  array<string, mixed>  $attributes  name, slug, starts_at, ends_at
     */
    public function createLanding(array $attributes): Page
    {
        $slug = $this->slug((string) ($attributes['slug'] ?? ''), null);

        return Page::query()->create([
            'type' => 'landing',
            'slug' => $slug,
            'name' => trim((string) $attributes['name']),
            'status' => 'draft',
            'seo' => [(string) config('locales.default') => ['title' => trim((string) $attributes['name'])]],
            'draft_blocks' => [],
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes  name, slug, starts_at, ends_at
     */
    public function updateLanding(Page $page, array $attributes): Page
    {
        if (! $page->isLanding()) {
            throw ValidationException::withMessages(['page' => 'Só as landing pages mudam de endereço e de datas.']);
        }

        $page->forceFill([
            'name' => trim((string) ($attributes['name'] ?? $page->name)),
            'slug' => $this->slug((string) ($attributes['slug'] ?? $page->slug), $page),
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
        ])->save();

        return $page;
    }

    public function duplicate(Page $page): Page
    {
        if ($page->template() !== 'page') {
            throw ValidationException::withMessages(['page' => 'Os templates do catálogo e do produto não se duplicam.']);
        }

        $base = $page->isLanding() ? (string) $page->slug : Str::slug($page->name);
        $slug = $base.'-copia';
        $n = 2;

        while (Page::query()->where('slug', $slug)->exists() || ReservedSlugs::isReserved($slug)) {
            $slug = "{$base}-copia-{$n}";
            $n++;
        }

        return Page::query()->create([
            'type' => 'landing',
            'slug' => $slug,
            'name' => $page->name.' (cópia)',
            'status' => 'draft',
            'seo' => $page->seo,
            'draft_blocks' => $this->sanitizer->clean($page->draft_blocks, 'page', requireFields: false),
        ]);
    }

    public function delete(Page $page): void
    {
        if (! $page->isLanding()) {
            throw ValidationException::withMessages(['page' => 'As páginas de sistema não se apagam.']);
        }

        $page->delete();
    }

    private function slug(string $slug, ?Page $ignore): string
    {
        $slug = Str::lower(trim($slug, " /\t\n"));

        if (! preg_match(self::SLUG_PATTERN, $slug) || strlen($slug) > 80) {
            throw ValidationException::withMessages(['slug' => 'O endereço só pode ter letras minúsculas sem acentos, números e hífens (ex.: natal-2026).']);
        }

        if (ReservedSlugs::isReserved($slug)) {
            throw ValidationException::withMessages(['slug' => "O endereço /{$slug} já é usado pelo site."]);
        }

        $taken = Page::query()->where('slug', $slug)->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['slug' => "Já existe uma página em /{$slug}."]);
        }

        return $slug;
    }

    /**
     * @param  array<mixed>  $seo
     * @return array<string, array{title?: string, description?: string}>
     */
    private function seo(array $seo): array
    {
        $clean = [];

        foreach (array_keys((array) config('locales.available')) as $locale) {
            $entry = is_array($seo[$locale] ?? null) ? $seo[$locale] : [];
            $title = trim((string) (is_scalar($entry['title'] ?? null) ? $entry['title'] : ''));
            $description = trim((string) (is_scalar($entry['description'] ?? null) ? $entry['description'] : ''));

            if (mb_strlen($title) > 70) {
                throw ValidationException::withMessages(["seo.$locale.title" => 'O título para motores de busca não pode ter mais de 70 caracteres.']);
            }

            if (mb_strlen($description) > 170) {
                throw ValidationException::withMessages(["seo.$locale.description" => 'A descrição para motores de busca não pode ter mais de 170 caracteres.']);
            }

            $entry = array_filter(['title' => $title, 'description' => $description]);

            if ($entry !== []) {
                $clean[$locale] = $entry;
            }
        }

        return $clean;
    }
}
