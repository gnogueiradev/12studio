<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\PageBuilder\BlockRegistry;
use App\PageBuilder\PageBuilderService;
use App\Support\SearchText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mews\Purifier\Facades\Purifier;

/**
 * O unico sitio que escreve traducoes de conteudo (produtos, categorias e o
 * texto das paginas). O backoffice e o MCP passam por aqui.
 *
 * Estado de uma traducao, por idioma:
 *   missing   nao ha (ou nao tem nome)
 *   outdated  foi feita sobre um portugues que entretanto mudou
 *   ok        em dia
 */
class TranslationService
{
    public const MISSING = 'missing';

    public const OUTDATED = 'outdated';

    public const OK = 'ok';

    /** Limites dos campos (os mesmos do portugues). */
    public const LIMITS = ['name' => 160, 'description' => 20000, 'seo_title' => 70, 'seo_description' => 170];

    public function __construct(
        private StoreSettings $store,
        private BlockRegistry $registry,
        private PageBuilderService $pages,
    ) {}

    /**
     * Os idiomas para onde se traduz: os ativos na montra, sem o de omissao.
     *
     * @return array<int, string>
     */
    public function targetLocales(): array
    {
        return array_values(array_diff($this->store->locales(), [(string) config('locales.default')]));
    }

    /**
     * Grava a traducao de um produto. So muda os campos enviados.
     *
     * @param  array<string, mixed>  $fields  name, description, seo_title, seo_description, personalization (chave => rotulo)
     */
    public function setProduct(Product $product, string $locale, array $fields): ProductTranslation
    {
        $this->assertLocale($locale);
        $clean = $this->clean($fields, ['name', 'description', 'seo_title', 'seo_description']);

        return DB::transaction(function () use ($product, $locale, $clean, $fields): ProductTranslation {
            if (array_key_exists('personalization', $fields) && is_array($fields['personalization'])) {
                $this->setPersonalizationLabels($product, $locale, $fields['personalization']);
            }

            /** @var ProductTranslation $translation */
            $translation = $product->translations()->firstOrNew(['locale' => $locale]);
            $translation->fill($clean);
            $translation->search_text = SearchText::normalize($translation->name, $translation->description);

            // So uma traducao do texto principal (nome e descricao) a poe em
            // dia: mexer so no SEO ou nos rotulos nao revê o resto.
            if ($translation->source_hash === null || (array_key_exists('name', $fields) && array_key_exists('description', $fields))) {
                $translation->source_hash = $product->sourceHash();
            }
            $translation->save();

            return $translation;
        });
    }

    /**
     * @param  array<string, mixed>  $fields  name, description
     */
    public function setCategory(Category $category, string $locale, array $fields): CategoryTranslation
    {
        $this->assertLocale($locale);
        // A descricao de uma categoria e texto simples (no portugues tambem).
        if (array_key_exists('description', $fields) && is_scalar($fields['description'])) {
            $fields['description'] = html_entity_decode(strip_tags((string) $fields['description']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $clean = $this->clean($fields, ['name'], plain: ['description']);

        /** @var CategoryTranslation $translation */
        $translation = $category->translations()->firstOrNew(['locale' => $locale]);
        $translation->fill($clean);

        if ($translation->source_hash === null || (array_key_exists('name', $fields) && array_key_exists('description', $fields))) {
            $translation->source_hash = $category->sourceHash();
        }
        $translation->save();

        return $translation;
    }

    public function productStatus(Product $product, string $locale): string
    {
        return $this->status($product->translations->firstWhere('locale', $locale), $product->sourceHash());
    }

    public function categoryStatus(Category $category, string $locale): string
    {
        return $this->status($category->translations->firstWhere('locale', $locale), $category->sourceHash());
    }

    /**
     * As traducoes de um produto para o formulario do backoffice.
     *
     * @return array<string, array<string, mixed>>
     */
    public function productForm(Product $product): array
    {
        $form = [];

        foreach ($this->targetLocales() as $locale) {
            $translation = $product->translations->firstWhere('locale', $locale);
            $labels = [];

            foreach ($product->personalizationFieldList() as $field) {
                $labels[$field['key']] = (string) ($field['label_i18n'][$locale] ?? '');
            }

            $form[$locale] = [
                'status' => $this->status($translation, $product->sourceHash()),
                'name' => (string) $translation?->name,
                'description' => (string) $translation?->description,
                'seo_title' => (string) $translation?->seo_title,
                'seo_description' => (string) $translation?->seo_description,
                'personalization' => (object) $labels,
                'updatedAt' => $translation?->updated_at?->toIso8601String(),
            ];
        }

        return $form;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function categoryForm(Category $category): array
    {
        $form = [];

        foreach ($this->targetLocales() as $locale) {
            $translation = $category->translations->firstWhere('locale', $locale);

            $form[$locale] = [
                'status' => $this->status($translation, $category->sourceHash()),
                'name' => (string) $translation?->name,
                'description' => (string) $translation?->description,
            ];
        }

        return $form;
    }

    /**
     * O que falta traduzir (ou esta desatualizado), para o MCP.
     *
     * @return array<int, array{type: string, id: int, name: string, locale: string, status: string}>
     */
    public function missing(string $type = 'all', ?string $locale = null): array
    {
        $locales = $locale === null ? $this->targetLocales() : [$locale];
        $rows = [];

        if (in_array($type, ['all', 'products'], true)) {
            $products = Product::query()->where('status', 'active')->with('translations')->orderBy('name')->get();

            foreach ($products as $product) {
                foreach ($locales as $code) {
                    $status = $this->productStatus($product, $code);

                    if ($status !== self::OK) {
                        $rows[] = ['type' => 'product', 'id' => $product->id, 'name' => $product->name, 'locale' => $code, 'status' => $status];
                    }
                }
            }
        }

        if (in_array($type, ['all', 'categories'], true)) {
            $categories = Category::query()->whereIn('status', ['visible', 'hidden'])->with('translations')->orderBy('name')->get();

            foreach ($categories as $category) {
                foreach ($locales as $code) {
                    $status = $this->categoryStatus($category, $code);

                    if ($status !== self::OK) {
                        $rows[] = ['type' => 'category', 'id' => $category->id, 'name' => $category->name, 'locale' => $code, 'status' => $status];
                    }
                }
            }
        }

        if (in_array($type, ['all', 'pages'], true)) {
            foreach (Page::query()->orderBy('id')->get() as $page) {
                foreach (array_intersect($this->pageMissingLocales($page), $locales) as $code) {
                    $rows[] = ['type' => 'page', 'id' => $page->id, 'name' => $page->name, 'locale' => $code, 'status' => self::MISSING];
                }
            }
        }

        return $rows;
    }

    /**
     * Os idiomas em que algum texto de um bloco do rascunho esta preenchido em
     * portugues e vazio nesse idioma.
     *
     * @return array<int, string>
     */
    public function pageMissingLocales(Page $page): array
    {
        $default = (string) config('locales.default');
        $missing = [];

        foreach ($page->draft_blocks as $block) {
            $definition = $this->registry->get((string) ($block['type'] ?? ''));

            foreach ($definition?->fields() ?? [] as $field) {
                if (! $field->translatable || ! in_array($field->type, ['text', 'textarea', 'richtext', 'link', 'repeater'], true)) {
                    continue;
                }

                if ($this->isEmpty($block['content'][$default][$field->key] ?? null)) {
                    continue;
                }

                foreach ($this->targetLocales() as $locale) {
                    if ($this->isEmpty($block['content'][$locale][$field->key] ?? null)) {
                        $missing[$locale] = true;
                    }
                }
            }
        }

        return array_keys($missing);
    }

    /**
     * Grava o texto de um idioma nos blocos de uma pagina (pelo id de cada
     * bloco). Vai para o rascunho: o site so muda quando se publica.
     *
     * @param  array<int, mixed>  $blocks  [{id, content: {campo: valor}}]
     */
    public function translatePage(Page $page, string $locale, array $blocks): Page
    {
        $this->assertLocale($locale);

        $draft = $page->draft_blocks;
        $index = [];
        foreach ($draft as $position => $block) {
            $index[(string) ($block['id'] ?? '')] = $position;
        }

        foreach ($blocks as $i => $incoming) {
            $id = is_array($incoming) ? (string) ($incoming['id'] ?? '') : '';

            if (! isset($index[$id])) {
                throw ValidationException::withMessages(["blocks.$i.id" => "Não há nenhum bloco com o id \"{$id}\" nesta página (ver page_get)."]);
            }

            $content = is_array($incoming['content'] ?? null) ? $incoming['content'] : [];
            $position = $index[$id];
            $draft[$position]['content'][$locale] = array_merge((array) ($draft[$position]['content'][$locale] ?? []), $content);
        }

        return $this->pages->saveDraft($page, $draft);
    }

    /**
     * @param  array<mixed>  $labels  chave do campo => rotulo
     */
    private function setPersonalizationLabels(Product $product, string $locale, array $labels): void
    {
        /** @var array<int, mixed> $fields */
        $fields = (array) $product->personalization_fields;
        $changed = false;

        foreach ($fields as $i => $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null) || ! array_key_exists($field['key'], $labels)) {
                continue;
            }

            $label = is_scalar($labels[$field['key']]) ? mb_substr(trim((string) $labels[$field['key']]), 0, 60) : '';
            $i18n = is_array($field['label_i18n'] ?? null) ? $field['label_i18n'] : [];

            if ($label === '') {
                unset($i18n[$locale]);
            } else {
                $i18n[$locale] = $label;
            }

            $fields[$i]['label_i18n'] = $i18n;
            $changed = true;
        }

        if ($changed) {
            // Sem tocar no updated_at do produto nem nos eventos dele: o
            // rotulo traduzido nao e uma alteracao ao produto.
            $product->personalization_fields = $fields;
            $product->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $plain  Campos de texto simples (sem HTMLPurifier).
     * @return array<string, string|null>
     */
    private function clean(array $fields, array $allowed, array $plain = []): array
    {
        $allowed = [...$allowed, ...$plain];
        $clean = [];
        $errors = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }

            $value = $fields[$key];

            if ($value !== null && ! is_scalar($value)) {
                $errors[$key] = 'Tem de ser texto.';

                continue;
            }

            $text = trim((string) $value);

            if ($key === 'description' && $text !== '' && ! in_array($key, $plain, true)) {
                $text = trim((string) Purifier::clean($text, 'product'));
                $text = trim(strip_tags($text)) === '' ? '' : $text;
            }

            if (mb_strlen($text) > self::LIMITS[$key]) {
                $errors[$key] = 'Não pode ter mais de '.self::LIMITS[$key].' caracteres.';
            }

            $clean[$key] = $text === '' ? null : $text;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function assertLocale(string $locale): void
    {
        if ($locale === config('locales.default') || ! array_key_exists($locale, (array) config('locales.available'))) {
            throw ValidationException::withMessages(['locale' => 'Idioma inválido: usa '.implode(', ', array_diff(array_keys((array) config('locales.available')), [config('locales.default')])).'. O português edita-se no próprio produto.']);
        }
    }

    private function status(ProductTranslation|CategoryTranslation|null $translation, string $hash): string
    {
        if ($translation === null || trim((string) $translation->name) === '') {
            return self::MISSING;
        }

        return $translation->source_hash === $hash ? self::OK : self::OUTDATED;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_string($value) && trim(strip_tags($value)) === '');
    }
}
