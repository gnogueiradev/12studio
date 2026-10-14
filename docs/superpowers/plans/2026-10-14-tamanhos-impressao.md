# Impressão por tamanho — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A secção "Impressão" do produto passa a guardar o tempo e as gramas **por tamanho**, em vez de escrever o mesmo valor em todas as variantes. Isto acaba com o estrago nos produtos multi-tamanho, como o produto 17 (10cm a 40g/128min e 20cm a 189g/411min).

**Architecture:** Não há migração. O grupo é o `variants.size_label` existente, normalizado com trim e sem distinguir maiúsculas por `App\Support\VariantSizes::key()`. O pedido `PATCH produtos/{product}/variantes/producao` deixa de receber um par só e passa a receber `sizes[]`. O serviço faz um `UPDATE` por tamanho, por ids. No React, `productionBySize()` agrupa as linhas e a tabela mostra um bloco por tamanho. Com um só tamanho a página fica igual à de hoje.

**Tech Stack:** Laravel 13, PHPUnit, Inertia v3 + React 19 TS, Tailwind 4 + shadcn.

**Spec:** `docs/superpowers/specs/2026-10-14-tamanhos-variantes-design.md` (só a Fase 1)

## Global Constraints

- Sem migrações nem escritas de manutenção: a BD de produção tem dados.
- Preços em cêntimos com IVA incluído. Esta fase não mexe em preços, porque `applyCalculatedPrices` continua igual.
- As arquivadas continuam a ser escritas (regra atual: uma variante que volte à montra não traz valores antigos).
- PHP corre só no PowerShell (Herd). Portões: `php artisan test <ficheiro>`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`. Não fazer `composer update`.
- Os comentários de código seguem o estilo do repo: em português e sem acentos no PHP; com acentos no TS.
- Nada de push nem merge. Merge em `main` faz deploy, por isso fica a pedido do dono.

## Review Focus

1. **Etiquetas iguais escritas de outra maneira** (" 20CM " e "20cm"): têm de cair no mesmo tamanho. Teste na Task 1 (unit) e na Task 2 (feature).
2. **Um pedido que só traz alguns tamanhos**: os que não vêm ficam como estão. Teste na Task 2.
3. **Um tamanho que só tem variantes arquivadas** continua a ser um tamanho válido do produto. Teste na Task 2.
4. **Variantes do mesmo tamanho com valores diferentes** (dados antigos): a UI avisa que guardar as iguala. Fica no `mixed` da Task 3, verificado no browser.
5. **Criar no painel uma variante de um tamanho novo com a secção aberta**: o formulário volta a montar com o tamanho novo e não envia uma lista desatualizada. Fica no `key` da Task 3, verificado no browser.

---

### Task 1: `VariantSizes::key()` — a chave de um tamanho

**Files:**
- Create: `app/Support/VariantSizes.php`
- Test: `tests/Unit/VariantSizesTest.php`

**Interfaces:**
- Produces: `App\Support\VariantSizes::key(?string $label): string`. Faz trim e `mb_strtolower`, e devolve `''` quando não há tamanho. As fases 2 a 4 também a vão usar.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Support\VariantSizes;
use PHPUnit\Framework\TestCase;

/**
 * O tamanho e texto livre na variante — "20cm", " 20CM ", "20cm " escritos em
 * alturas diferentes sao o mesmo tamanho para quem os escreveu. A chave e o
 * que os junta, e e a mesma em todos os sitios que agrupam por tamanho.
 */
class VariantSizesTest extends TestCase
{
    public function test_ignora_espacos_nas_pontas_e_maiusculas(): void
    {
        $this->assertSame('20cm', VariantSizes::key(' 20CM '));
        $this->assertSame(VariantSizes::key('20cm'), VariantSizes::key("20Cm\t"));
    }

    public function test_sem_tamanho_e_vazio_e_so_espacos_sao_o_mesmo(): void
    {
        $this->assertSame('', VariantSizes::key(null));
        $this->assertSame('', VariantSizes::key(''));
        $this->assertSame('', VariantSizes::key('   '));
    }

    /**
     * Os espacos DENTRO contam: "20 cm" e "20cm" ficam dois tamanhos. Juntar
     * isso era adivinhar, e o dono ve os dois grupos e corrige.
     */
    public function test_espacos_no_meio_contam(): void
    {
        $this->assertNotSame(VariantSizes::key('20cm'), VariantSizes::key('20 cm'));
    }

    public function test_acentos_descem_a_minusculas(): void
    {
        $this->assertSame('médio', VariantSizes::key('MÉDIO'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (PowerShell): `php artisan test tests/Unit/VariantSizesTest.php`
Expected: FAIL with `Class "App\Support\VariantSizes" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Support;

/**
 * Os tamanhos de um produto, que vivem como texto livre em
 * `variants.size_label`.
 *
 * Nao ha tabela de tamanhos de proposito: o tamanho e o que o dono escreveu na
 * variante, e o grupo "10cm" sao as variantes que dizem 10cm. A chave e o que
 * torna isso fiavel — o mesmo tamanho escrito com outra caixa ou com um espaco
 * a mais continua a ser o mesmo grupo.
 *
 * Comparacao em PHP e nao em SQL: o LOWER() do SQLite so conhece ASCII, e
 * "MÉDIO" ficava longe de "médio".
 */
final class VariantSizes
{
    /** Sem espacos nas pontas, em minusculas. Sem tamanho e ''. */
    public static function key(?string $label): string
    {
        return mb_strtolower(trim((string) $label));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/VariantSizesTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Support/VariantSizes.php tests/Unit/VariantSizesTest.php
git commit -m "Tamanhos: uma chave unica para agrupar variantes pelo tamanho"
```

---

### Task 2: O pedido e o serviço passam a trabalhar por tamanho

**Files:**
- Modify: `app/Http/Requests/Variant/UpdateVariantProductionRequest.php` (inteiro)
- Modify: `app/Services/VariantProductionService.php:27-41` (`updateProduction` e docblock da classe)
- Modify: `app/Http/Controllers/Admin/VariantProductionController.php:12-36` (docblock e `update`)
- Test: `tests/Feature/Admin/VariantProductionTest.php`

**Interfaces:**
- Consumes: `VariantSizes::key(?string): string` (Task 1).
- Produces: o corpo do `PATCH admin.produtos.variantes.producao` passa a ser
  `{ sizes: [{ size_label: string|null, hours: int|null, minutes: int|null, weight_grams: int|null }] }`.
  Os erros vêm como `sizes.{i}.hours`, `sizes.{i}.minutes`, `sizes.{i}.weight_grams` e `sizes.{i}.size_label`.
  `UpdateVariantProductionRequest::sizes(): list<array{size_label: string|null, values: array{printing_time_minutes: int|null, filament_weight_grams: int|null}}>`.
  `VariantProductionService::updateProduction(Product $product, list<array{size_label: string|null, values: array{...}}> $sizes): int`.

- [ ] **Step 1: Rewrite the existing tests to the new shape and add the new ones**

Em `tests/Feature/Admin/VariantProductionTest.php`:

1. Atualizar o docblock da classe:

```php
/**
 * A aba "Producao" do produto: o tempo de impressao e a gramagem sao de cada
 * TAMANHO — as cores e os materiais de um tamanho gastam o mesmo, mas a peca
 * de 20cm nao e a de 10cm — e os precos calculados a partir deles vao para
 * todas as variantes.
 *
 * Os numeros sao os da peca de referencia do PricingCalculatorTest: 50 g a
 * 17,00 EUR/kg, 3 h na Bambu Lab A1 -> 4,00 EUR de revenda, 7,00 EUR ao cliente.
 */
```

2. Acrescentar este helper no fim da classe:

```php
    /**
     * O corpo do pedido: um tamanho por linha. `size(null, ...)` e o grupo das
     * variantes sem tamanho — o de quase todos os produtos.
     *
     * @return array{size_label: string|null, hours: int|null, minutes: int|null, weight_grams: int|null}
     */
    private static function size(?string $label, ?int $hours, ?int $minutes, ?int $grams): array
    {
        return ['size_label' => $label, 'hours' => $hours, 'minutes' => $minutes, 'weight_grams' => $grams];
    }
```

3. Em todos os `patch(route('admin.produtos.variantes.producao', ...), [...])`, trocar o corpo plano por `['sizes' => [self::size(null, H, M, G)]]`, com os mesmos valores. Em concreto:
   - `test_it_saves_the_same_print_time_and_weight_on_every_variant`: mudar o nome para `test_without_sizes_one_pair_reaches_every_variant` e o corpo para `['sizes' => [self::size(null, 1, 30, 40)]]`. O docblock passa a ser `Sem tamanhos ha um grupo so: o par chega a todas, arquivadas incluidas.`
   - `test_it_never_touches_the_variants_of_another_product`: `['sizes' => [self::size(null, 2, 0, 50)]]`
   - `test_an_empty_field_clears_the_value_everywhere`: `['sizes' => [self::size(null, null, null, null)]]`
   - `test_hours_alone_or_minutes_alone_still_count_as_a_time`: `['sizes' => [self::size(null, null, 45, 10)]]`
   - `test_it_validates_like_the_calculator_does`: o corpo passa a `['sizes' => [self::size(null, 1, 75, -5)]]` e a asserção a `->assertSessionHasErrors(['sizes.0.minutes', 'sizes.0.weight_grams']);`
   - `test_only_admins_can_touch_production_data`: `['sizes' => [self::size(null, 1, 0, 10)]]`
   - Nos testes `test_applying_prices_*` que façam `patch` à rota `producao`, aplicar a mesma troca. Os que só fazem `post` a `precos` ficam como estão.

4. Acrescentar estes testes a seguir a `test_without_sizes_one_pair_reaches_every_variant`:

```php
    /**
     * A escultura de 10cm e a de 20cm sao pecas diferentes: cada tamanho
     * guarda o seu tempo e a sua gramagem, em todas as cores desse tamanho.
     */
    public function test_each_size_keeps_its_own_print_time_and_weight(): void
    {
        $smallWhite = Variant::factory()->for($this->product)->create(['size_label' => '10cm']);
        $smallBlack = Variant::factory()->for($this->product)->create(['size_label' => '10cm']);
        $bigWhite = Variant::factory()->for($this->product)->create(['size_label' => '20cm']);
        $bigArchived = Variant::factory()->for($this->product)->create(['size_label' => '20cm', 'active' => false]);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), ['sizes' => [
                self::size('10cm', 2, 8, 40),
                self::size('20cm', 6, 51, 189),
            ]])
            ->assertSessionHasNoErrors();

        foreach ([$smallWhite, $smallBlack] as $variant) {
            $this->assertSame(128, $variant->refresh()->printing_time_minutes);
            $this->assertSame(40, $variant->filament_weight_grams);
        }

        foreach ([$bigWhite, $bigArchived] as $variant) {
            $this->assertSame(411, $variant->refresh()->printing_time_minutes);
            $this->assertSame(189, $variant->filament_weight_grams);
        }
    }

    /** O mesmo tamanho escrito com outra caixa e espacos a mais e o mesmo grupo. */
    public function test_a_size_matches_ignoring_case_and_surrounding_spaces(): void
    {
        $tidy = Variant::factory()->for($this->product)->create(['size_label' => '20cm']);
        $sloppy = Variant::factory()->for($this->product)->create(['size_label' => ' 20CM ']);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), ['sizes' => [
                self::size('20cm', 1, 0, 100),
            ]])
            ->assertSessionHasNoErrors();

        $this->assertSame(100, $tidy->refresh()->filament_weight_grams);
        $this->assertSame(100, $sloppy->refresh()->filament_weight_grams);
    }

    /** Um tamanho que nao vem no pedido fica como estava. */
    public function test_a_size_left_out_of_the_request_is_not_touched(): void
    {
        Variant::factory()->for($this->product)->create(['size_label' => '10cm']);
        $big = Variant::factory()->for($this->product)->create([
            'size_label' => '20cm',
            'printing_time_minutes' => 411,
            'filament_weight_grams' => 189,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), ['sizes' => [
                self::size('10cm', 2, 8, 40),
            ]])
            ->assertSessionHasNoErrors();

        $this->assertSame(411, $big->refresh()->printing_time_minutes);
        $this->assertSame(189, $big->filament_weight_grams);
    }

    /**
     * Um tamanho que o produto nao tem e um engano (ou um formulario velho):
     * recusa-se, e nada se grava — nem os tamanhos certos do mesmo pedido.
     */
    public function test_a_size_the_product_does_not_have_is_refused(): void
    {
        $small = Variant::factory()->for($this->product)->create(['size_label' => '10cm']);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), ['sizes' => [
                self::size('10cm', 2, 8, 40),
                self::size('30cm', 9, 0, 400),
            ]])
            ->assertSessionHasErrors(['sizes.1.size_label']);

        $this->assertNull($small->refresh()->filament_weight_grams);
    }

    /** As variantes sem tamanho tambem so existem se o produto as tiver. */
    public function test_the_no_size_group_is_refused_when_every_variant_has_a_size(): void
    {
        Variant::factory()->for($this->product)->create(['size_label' => '10cm']);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), ['sizes' => [
                self::size(null, 1, 0, 10),
            ]])
            ->assertSessionHasErrors(['sizes.0.size_label']);
    }

    /** Dois blocos para o mesmo tamanho: qual deles ganhava? Recusa-se. */
    public function test_the_same_size_twice_is_refused(): void
    {
        Variant::factory()->for($this->product)->create(['size_label' => '10cm']);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), ['sizes' => [
                self::size('10cm', 1, 0, 10),
                self::size('10CM ', 2, 0, 20),
            ]])
            ->assertSessionHasErrors(['sizes.1.size_label']);
    }

    /** Um tamanho so com variantes arquivadas continua a ser do produto. */
    public function test_a_size_with_only_archived_variants_still_counts(): void
    {
        $archived = Variant::factory()->for($this->product)->create(['size_label' => '30cm', 'active' => false]);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), ['sizes' => [
                self::size('30cm', 9, 0, 400),
            ]])
            ->assertSessionHasNoErrors();

        $this->assertSame(400, $archived->refresh()->filament_weight_grams);
    }

    /**
     * O que isto tudo protege: com o tempo de cada tamanho gravado, os precos
     * calculados de 10cm e 20cm saem diferentes — antes saiam iguais.
     */
    public function test_applying_prices_after_saving_per_size_prices_each_size_apart(): void
    {
        $small = Variant::factory()->for($this->product)->create(['size_label' => '10cm', 'material_id' => $this->material->id]);
        $big = Variant::factory()->for($this->product)->create(['size_label' => '20cm', 'material_id' => $this->material->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), ['sizes' => [
                self::size('10cm', 3, 0, 50),
                self::size('20cm', 7, 0, 190),
            ]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.precos', $this->product))
            ->assertSessionHasNoErrors();

        $this->assertSame(700, $small->refresh()->price_cents);
        $this->assertGreaterThan(700, $big->refresh()->price_cents);
    }
```

5. Acrescentar um teste para o pedido sem `sizes`:

```php
    public function test_a_request_without_sizes_is_refused(): void
    {
        Variant::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'hours' => 1, 'minutes' => 0, 'weight_grams' => 10,
            ])
            ->assertSessionHasErrors(['sizes']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Admin/VariantProductionTest.php`
Expected: FAIL. Os testes de `patch` falham porque o pedido ainda lê `hours`/`minutes`/`weight_grams` no topo (sem erros, mas os valores ficam null), e os de recusa porque não há erros em `sizes.*`.

- [ ] **Step 3: Rewrite the request**

`app/Http/Requests/Variant/UpdateVariantProductionRequest.php`, o ficheiro inteiro:

```php
<?php

namespace App\Http\Requests\Variant;

use App\Http\Requests\AdminFormRequest;
use App\Models\Product;
use App\Support\VariantSizes;
use Illuminate\Validation\Validator;

/**
 * A aba "Producao" do produto: o tempo de impressao e a gramagem de cada
 * TAMANHO, a escrever de uma vez em todas as variantes desse tamanho.
 *
 * Um par por tamanho e nao um por variante: entre as cores e os materiais de
 * um tamanho o tempo de maquina e o plastico gasto nao mudam — a peca e a
 * mesma. Entre tamanhos mudam: a escultura de 20cm gasta quase cinco vezes o
 * plastico da de 10cm. Um produto sem tamanhos tem um grupo so
 * (`size_label` null), e e o caso de quase todos.
 *
 * As horas e os minutos chegam separados pela mesma razao do
 * PricingPreviewRequest — "1,30" tanto se le como uma hora e trinta como 1,3
 * horas — e os tectos sao os do StoreVariantRequest, que guarda os mesmos dois
 * campos uma variante de cada vez.
 *
 * Os tres campos sao nullable: um campo vazio LIMPA o valor nas variantes do
 * tamanho, e e a unica maneira de desfazer um engano sem abrir ficha a ficha.
 * Zero e outra coisa — uma peca de zero gramas nao existe, e o calculo
 * recusa-a.
 */
class UpdateVariantProductionRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sizes' => ['required', 'array', 'min:1', 'max:50'],
            'sizes.*' => ['array:size_label,hours,minutes,weight_grams'],
            'sizes.*.size_label' => ['present', 'nullable', 'string', 'max:60'],
            'sizes.*.hours' => ['nullable', 'integer', 'min:0', 'max:999'],
            'sizes.*.minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'sizes.*.weight_grams' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sizes.*.size_label' => 'tamanho',
            'sizes.*.hours' => 'horas',
            'sizes.*.minutes' => 'minutos',
            'sizes.*.weight_grams' => 'gramagem',
        ];
    }

    /**
     * Cada tamanho tem de ser do produto e aparecer uma vez so. Feito aqui e
     * nao com `distinct`/`in`: a comparacao e a do VariantSizes::key() — sem
     * caixa, sem espacos nas pontas — e o null (sem tamanho) tem de ser
     * verificado como os outros, coisa que uma regra a seguir a `nullable`
     * nunca faz.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('sizes')) {
                    return;
                }

                /** @var Product $product */
                $product = $this->route('product');

                $known = $product->variants()
                    ->pluck('size_label')
                    ->map(fn (?string $label): string => VariantSizes::key($label))
                    ->unique()
                    ->all();

                $seen = [];

                foreach ((array) $this->input('sizes', []) as $index => $size) {
                    $label = is_array($size) ? ($size['size_label'] ?? null) : null;

                    if ($label !== null && ! is_string($label)) {
                        continue; // O `string` das regras ja disse o que ha a dizer.
                    }

                    $key = VariantSizes::key($label);

                    if (! in_array($key, $known, true)) {
                        $validator->errors()->add("sizes.{$index}.size_label", $key === ''
                            ? 'Todas as variantes deste produto têm tamanho.'
                            : "Este produto não tem o tamanho «{$label}».");

                        continue;
                    }

                    if (in_array($key, $seen, true)) {
                        $validator->errors()->add("sizes.{$index}.size_label", 'Este tamanho aparece duas vezes.');
                    }

                    $seen[] = $key;
                }
            },
        ];
    }

    /**
     * Ja traduzido para o que a base de dados guarda: minutos totais e gramas,
     * ou null quando o campo veio vazio, tamanho a tamanho.
     *
     * @return list<array{size_label: string|null, values: array{printing_time_minutes: int|null, filament_weight_grams: int|null}}>
     */
    public function sizes(): array
    {
        /** @var array<int, array{size_label?: string|null, hours?: int|string|null, minutes?: int|string|null, weight_grams?: int|string|null}> $sizes */
        $sizes = (array) $this->validated('sizes');

        return array_values(array_map(fn (array $size): array => [
            'size_label' => $size['size_label'] ?? null,
            'values' => self::production($size),
        ], $sizes));
    }

    /**
     * @param  array{hours?: int|string|null, minutes?: int|string|null, weight_grams?: int|string|null}  $size
     * @return array{printing_time_minutes: int|null, filament_weight_grams: int|null}
     */
    private static function production(array $size): array
    {
        $hours = $size['hours'] ?? null;
        $minutes = $size['minutes'] ?? null;

        // Horas E minutos vazios = sem tempo. Um dos dois preenchido chega:
        // "0 h 45 min" e "2 h" sao ambos tempos.
        $totalMinutes = $hours === null && $minutes === null
            ? null
            : (int) $hours * 60 + (int) $minutes;

        $grams = $size['weight_grams'] ?? null;

        return [
            'printing_time_minutes' => $totalMinutes,
            'filament_weight_grams' => $grams === null ? null : (int) $grams,
        ];
    }
}
```

- [ ] **Step 4: Rewrite `updateProduction` in the service**

Em `app/Services/VariantProductionService.php`:

- Acrescentar `use App\Support\VariantSizes;` aos imports.
- Na primeira frase do docblock da classe, trocar "o que a peca custa a imprimir" por "o que a peca custa a imprimir em cada tamanho". O resto do docblock fica.
- Substituir o método `updateProduction` (linhas 27-41) por este:

```php
    /**
     * O tempo e a gramagem de cada tamanho em TODAS as variantes desse
     * tamanho, arquivadas incluidas: entre cores e materiais a peca e a
     * mesma, entre tamanhos nao. Um tamanho que nao venha fica como esta.
     *
     * Um UPDATE por tamanho, por ids: o tamanho e texto livre e a igualdade e
     * a do VariantSizes::key() (sem caixa, sem espacos nas pontas), que o
     * SQLite nao sabe fazer com acentos. O pedido ja garantiu que cada tamanho
     * e do produto e aparece uma vez.
     *
     * @param  list<array{size_label: string|null, values: array{printing_time_minutes: int|null, filament_weight_grams: int|null}}>  $sizes
     * @return int Quantas variantes ficaram com os valores.
     */
    public function updateProduction(Product $product, array $sizes): int
    {
        $variants = $product->variants()->get(['id', 'size_label']);

        return DB::transaction(function () use ($variants, $sizes): int {
            $count = 0;

            foreach ($sizes as $size) {
                $key = VariantSizes::key($size['size_label']);

                $ids = $variants
                    ->filter(fn (Variant $variant): bool => VariantSizes::key($variant->size_label) === $key)
                    ->modelKeys();

                if ($ids !== []) {
                    $count += Variant::query()->whereKey($ids)->update($size['values']);
                }
            }

            return $count;
        });
    }
```

- [ ] **Step 5: Update the controller**

Em `app/Http/Controllers/Admin/VariantProductionController.php`:

- Trocar a primeira frase do docblock da classe por:
  `A seccao "Impressao" da pagina do produto: o tempo e a gramagem de cada tamanho, a escrever de uma vez nas variantes desse tamanho, e um botao que escreve nelas os precos calculados.`
- Em `update()`, trocar `$request->values()` por `$request->sizes()`. O toast fica igual.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Admin/VariantProductionTest.php tests/Unit/VariantSizesTest.php`
Expected: PASS

- [ ] **Step 7: Search for other callers of the old shape**

Run: `git grep -n "values()" -- app/Http/Controllers/Admin/VariantProductionController.php; git grep -n "variantes.producao\|updateProduction(" -- app tests resources/js`
Expected: os únicos sítios que chamam `updateProduction(` são o controller e o serviço. A rota só aparece em `VariantProductionTest.php` e em `variant-production-table.tsx` (este muda na Task 3). Se aparecer outro chamador, passá-lo para `sizes`.

- [ ] **Step 8: Gates + commit**

Run: `vendor/bin/pint --test` e `vendor/bin/phpstan analyse`
Expected: sem erros. Se o Pint falhar, corrê-lo sem `--test` e rever o diff.

```bash
git add app/Http/Requests/Variant/UpdateVariantProductionRequest.php app/Services/VariantProductionService.php app/Http/Controllers/Admin/VariantProductionController.php tests/Feature/Admin/VariantProductionTest.php
git commit -m "Impressao: o tempo e a gramagem guardam-se por tamanho"
```

---

### Task 3: A secção "Impressão" mostra um bloco por tamanho

**Files:**
- Modify: `resources/js/lib/production.ts` (acrescentar `sizeKey`, `SizeProduction`, `productionBySize`; corrigir o docblock de `productProduction`)
- Modify: `resources/js/components/admin/variant-production-table.tsx` (inteiro)
- Modify: `resources/js/pages/admin/produtos/form.tsx:29,510-516` (import, nota, `key`)

**Interfaces:**
- Consumes: o corpo `sizes[]` e os erros `sizes.{i}.*` da Task 2.
- Produces: `productionBySize(variants: VariantRow[]): SizeProduction[]`, `sizeKey(label: string | null): string`.
  `SizeProduction = { key: string; sizeLabel: string | null; variants: VariantRow[]; printingTimeMinutes: number | null; filamentWeightGrams: number | null; mixed: boolean }`.
  As fases 2 e 3 vão usar isto para agrupar a lista.

- [ ] **Step 1: Add the grouping helper**

Em `resources/js/lib/production.ts`, trocar o docblock de `productProduction` (as linhas 10-19) por:

```ts
/**
 * O tempo e a gramagem de referência de uma lista de variantes: os da
 * primeira. A lista chega ordenada com a principal à cabeça — a mesma regra
 * que a listagem usa para mostrar estes dois números na tabela de produtos.
 *
 * Serve para pré-preencher uma variante nova. A aba "Produção" trabalha por
 * tamanho: ver `productionBySize`.
 */
```

e acrescentar no fim do ficheiro:

```ts
/**
 * A chave de um tamanho — a mesma do VariantSizes::key() no servidor: sem
 * espaços nas pontas, em minúsculas. Sem tamanho é ''.
 */
export function sizeKey(label: string | null): string {
    return (label ?? '').trim().toLowerCase();
}

/** Um tamanho do produto, com as suas variantes e o par que a aba edita. */
export type SizeProduction = ProductProduction & {
    key: string;
    /** Como o dono o escreveu (sem espaços nas pontas). Null = sem tamanho. */
    sizeLabel: string | null;
    variants: VariantRow[];
    /**
     * As variantes deste tamanho não concordam no tempo ou na gramagem —
     * dados de antes de a aba ser por tamanho. Guardar iguala-as.
     */
    mixed: boolean;
};

/**
 * As variantes agrupadas por tamanho, pela ordem em que cada tamanho aparece
 * primeiro — o da principal à cabeça. O par de cada grupo é o da sua primeira
 * variante. Um produto sem tamanhos dá um grupo só.
 */
export function productionBySize(variants: VariantRow[]): SizeProduction[] {
    const groups = new Map<string, SizeProduction>();

    for (const variant of variants) {
        const key = sizeKey(variant.sizeLabel);
        const group = groups.get(key);

        if (group === undefined) {
            groups.set(key, {
                key,
                sizeLabel: key === '' ? null : (variant.sizeLabel ?? '').trim(),
                variants: [variant],
                printingTimeMinutes: variant.printingTimeMinutes,
                filamentWeightGrams: variant.filamentWeightGrams,
                mixed: false,
            });

            continue;
        }

        group.variants.push(variant);
        group.mixed ||=
            variant.printingTimeMinutes !== group.printingTimeMinutes ||
            variant.filamentWeightGrams !== group.filamentWeightGrams;
    }

    return [...groups.values()];
}
```

- [ ] **Step 2: Rewrite the production table**

`resources/js/components/admin/variant-production-table.tsx`, o ficheiro inteiro:

```tsx
import { router, useForm } from '@inertiajs/react';
import { Calculator } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCents } from '@/lib/money';
import { productionBySize, type SizeProduction } from '@/lib/production';
import { cn } from '@/lib/utils';
import { precos, producao } from '@/routes/admin/produtos/variantes';
import type { ProductEditing, VariantRow } from '@/types/catalog';

type Props = {
    editing: ProductEditing;
};

/** Strings, porque um campo vazio é "sem valor" — e é diferente de zero. */
type SizeFormRow = {
    /** O tamanho tal como o servidor o conhece. Null = variantes sem tamanho. */
    size_label: string | null;
    hours: string;
    minutes: string;
    weight_grams: string;
};

type ProductionFormData = {
    sizes: SizeFormRow[];
};

const toRow = (group: SizeProduction): SizeFormRow => ({
    size_label: group.sizeLabel,
    hours:
        group.printingTimeMinutes === null
            ? ''
            : String(Math.floor(group.printingTimeMinutes / 60)),
    minutes:
        group.printingTimeMinutes === null
            ? ''
            : String(group.printingTimeMinutes % 60),
    weight_grams:
        group.filamentWeightGrams === null
            ? ''
            : String(group.filamentWeightGrams),
});

/**
 * A aba "Produção" do produto: o tempo de impressão e a gramagem de cada
 * TAMANHO — um par por tamanho, para todas as variantes dele — e o botão que
 * escreve em todas elas os preços calculados.
 *
 * Um par por tamanho e não um por variante porque, dentro de um tamanho, a
 * peça é a mesma: muda a cor e o material, não o tempo de máquina nem o
 * plástico gasto. Entre tamanhos muda — a escultura de 20cm não é a de 10cm.
 * Um produto sem tamanhos tem um bloco só, e a aba fica como sempre foi.
 *
 * Os preços NÃO saem do que está escrito nos campos: saem do que está
 * gravado. Por isso o botão "Aplicar preços" fica desativado enquanto há
 * alterações por guardar — o preço que fica na variante tem de ser o mesmo
 * que a coluna "Sugerido" mostra, e essa coluna vem do servidor.
 *
 * O formulário nasce dos tamanhos que existem quando a aba monta. Quem chama
 * dá-lhe um `key` com esses tamanhos (ver form.tsx), para uma variante de um
 * tamanho novo remontar a aba em vez de a deixar a mandar a lista velha.
 */
export function VariantProductionTable({ editing }: Props) {
    const groups = useMemo(
        () => productionBySize(editing.variants),
        [editing.variants],
    );
    const { data, setData, patch, processing, errors, isDirty, setDefaults } =
        useForm<ProductionFormData>({ sizes: groups.map(toRow) });
    const [confirming, setConfirming] = useState(false);
    const [applying, setApplying] = useState(false);
    const id = useId();

    // Os erros chegam por caminho ("sizes.1.minutes"); o tipo do useForm só
    // conhece as chaves de topo.
    const errorFor = (index: number, field: keyof SizeFormRow) =>
        (errors as Record<string, string | undefined>)[
            `sizes.${index}.${field}`
        ];

    const setField = (
        index: number,
        field: 'hours' | 'minutes' | 'weight_grams',
        value: string,
    ) =>
        setData(
            'sizes',
            data.sizes.map((row, at) =>
                at === index ? { ...row, [field]: value } : row,
            ),
        );

    const save = () =>
        patch(producao(editing.product.id).url, {
            // O servidor devolve `back()`: o modal fica onde está e a coluna
            // "Sugerido" chega recalculada com a prop `editing`.
            preserveScroll: true,
            preserveState: true,
            // O que acabou de ser guardado passa a ser a base do "por
            // guardar" — sem isto o botão de aplicar ficava trancado para
            // sempre depois do primeiro guardar.
            onSuccess: () => setDefaults(),
        });

    const applyPrices = () => {
        setApplying(true);
        router.post(
            precos(editing.product.id).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    setApplying(false);
                    setConfirming(false);
                },
            },
        );
    };

    const calculable = editing.variants.filter(
        (variant) => variant.suggestedRetailCents !== null,
    ).length;
    const withoutMaterial = editing.variants.filter(
        (variant) => variant.material === null,
    ).length;

    if (editing.variants.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                Este produto ainda não tem variantes — cria a primeira na lista
                para lhe dar tempo de impressão e gramagem.
            </p>
        );
    }

    const bySize = groups.length > 1;

    return (
        <div className="flex flex-col gap-4">
            <p className="text-xs text-muted-foreground">
                {bySize
                    ? 'O que o slicer diz de cada tamanho. É igual em todas as cores do mesmo tamanho'
                    : 'O que o slicer diz da peça. É igual para todas as variantes'}{' '}
                — o que muda entre elas é o material, e é daí que o preço
                sugerido difere. Sem tempo não há cálculo, e sem material o
                plástico seria de graça.
            </p>

            {groups.map((group, index) => {
                const row = data.sizes[index];

                if (row === undefined) {
                    return null;
                }

                return (
                    <fieldset
                        key={group.key}
                        className="flex flex-col gap-3 rounded-xl border border-border/60 p-3"
                    >
                        {bySize && (
                            <legend className="px-1 text-sm font-medium">
                                {group.sizeLabel ?? 'Sem tamanho'}
                                <span className="font-normal text-muted-foreground">
                                    {' · '}
                                    {group.variants.length === 1
                                        ? '1 variante'
                                        : `${group.variants.length} variantes`}
                                </span>
                            </legend>
                        )}

                        <div className="flex flex-wrap items-end gap-3 rounded-lg bg-secondary/20 p-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-${index}-hours`}>
                                    Horas
                                </Label>
                                <Input
                                    id={`${id}-${index}-hours`}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={999}
                                    className="w-20"
                                    value={row.hours}
                                    onChange={(event) =>
                                        setField(
                                            index,
                                            'hours',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-${index}-minutes`}>
                                    Min
                                </Label>
                                <Input
                                    id={`${id}-${index}-minutes`}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={59}
                                    className="w-20"
                                    value={row.minutes}
                                    onChange={(event) =>
                                        setField(
                                            index,
                                            'minutes',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-${index}-grams`}>
                                    Gramas
                                </Label>
                                <Input
                                    id={`${id}-${index}-grams`}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={99999}
                                    className="w-24"
                                    value={row.weight_grams}
                                    onChange={(event) =>
                                        setField(
                                            index,
                                            'weight_grams',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0"
                                />
                            </div>
                            {group.mixed && (
                                <span className="text-xs text-warning">
                                    As variantes deste tamanho têm valores
                                    diferentes — guardar iguala-as.
                                </span>
                            )}
                        </div>

                        <InputError
                            message={
                                errorFor(index, 'size_label') ??
                                errorFor(index, 'hours') ??
                                errorFor(index, 'minutes') ??
                                errorFor(index, 'weight_grams')
                            }
                        />

                        <VariantPriceTable variants={group.variants} />
                    </fieldset>
                );
            })}

            <InputError message={errors.sizes} />

            <div className="flex flex-wrap items-center justify-end gap-2">
                {isDirty ? (
                    <span className="text-xs text-muted-foreground">
                        Guarda primeiro para aplicar preços.
                    </span>
                ) : (
                    withoutMaterial > 0 && (
                        <span className="text-xs text-muted-foreground">
                            {withoutMaterial === 1
                                ? '1 variante sem material fica de fora.'
                                : `${withoutMaterial} variantes sem material ficam de fora.`}
                        </span>
                    )
                )}
                <Button
                    type="button"
                    size="sm"
                    onClick={save}
                    disabled={processing || !isDirty}
                >
                    Guardar
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setConfirming(true)}
                    disabled={
                        processing || applying || isDirty || calculable === 0
                    }
                >
                    <Calculator className="size-4" />
                    Aplicar preços calculados
                </Button>
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={(open) => !open && setConfirming(false)}
                title="Aplicar preços calculados"
                description={
                    <>
                        O PVP e o preço de revenda de{' '}
                        <strong>
                            {calculable === 1
                                ? '1 variante'
                                : `${calculable} variantes`}
                        </strong>{' '}
                        passam a ser os sugeridos, arquivadas incluídas. As que
                        não têm material ficam como estão. Uma promoção
                        mantém-se se continuar abaixo do novo preço.
                    </>
                }
                confirmLabel="Aplicar"
                processing={applying}
                onConfirm={applyPrices}
            />
        </div>
    );
}

/** As variantes de um tamanho, com o preço sugerido ao lado do atual. */
function VariantPriceTable({ variants }: { variants: VariantRow[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border border-border/60">
            <table className="w-full text-sm">
                <thead className="bg-secondary/40 text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" className="px-3 py-2 text-left font-medium">
                            Variante
                        </th>
                        <th scope="col" className="px-3 py-2 text-right font-medium">
                            Sugerido
                        </th>
                        <th scope="col" className="px-3 py-2 text-right font-medium">
                            Atual
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border/60">
                    {variants.map((variant) => (
                        <tr
                            key={variant.id}
                            className={cn(
                                'align-top',
                                !variant.active && 'opacity-60',
                            )}
                        >
                            <td className="min-w-0 px-3 py-2">
                                <span className="flex flex-wrap items-center gap-1.5 font-medium">
                                    {variant.sku}
                                    {variant.isDefault && (
                                        <Badge variant="outline">Principal</Badge>
                                    )}
                                    {!variant.active && (
                                        <Badge variant="secondary">Arquivada</Badge>
                                    )}
                                </span>
                                <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                    {variant.color && (
                                        <>
                                            <ColorSwatch hex={variant.color.hex} />
                                            <span>{variant.color.name}</span>
                                        </>
                                    )}
                                    {variant.material ? (
                                        <span>{variant.material.name}</span>
                                    ) : (
                                        <span className="text-warning">
                                            Sem material
                                        </span>
                                    )}
                                    {variant.sizeLabel && (
                                        <span>{variant.sizeLabel}</span>
                                    )}
                                </span>
                            </td>
                            <PriceCell
                                retail={variant.suggestedRetailCents}
                                wholesale={variant.suggestedWholesaleCents}
                            />
                            <PriceCell
                                retail={variant.priceCents}
                                wholesale={variant.wholesalePriceCents}
                            />
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** PVP em cima, revenda em baixo — o mesmo desenho no sugerido e no atual. */
function PriceCell({
    retail,
    wholesale,
}: {
    retail: number | null;
    wholesale: number | null;
}) {
    return (
        <td className="px-3 py-2 text-right tabular-nums">
            {retail === null ? (
                <span className="text-muted-foreground">—</span>
            ) : (
                <>
                    <span className="block">{formatCents(retail)}</span>
                    {wholesale !== null && (
                        <span className="block text-xs text-muted-foreground">
                            rev. {formatCents(wholesale)}
                        </span>
                    )}
                </>
            )}
        </td>
    );
}
```

Nota: o botão "Guardar" passa a viver na barra de baixo, ao lado de "Aplicar preços calculados". Com vários blocos há um só guardar, e ficar no fim deixa claro que guarda todos.

- [ ] **Step 3: Wire the key and the note in the product page**

Em `resources/js/pages/admin/produtos/form.tsx`:

- Linha 29: `import { productProduction } from '@/lib/production';` passa a `import { productionBySize, productProduction } from '@/lib/production';`
- No bloco das linhas 510-516, trocar por:

```tsx
                            {editing !== null && (
                                <section>
                                    <SectionTitle note="Igual em todas as variantes do mesmo tamanho">
                                        Impressão
                                    </SectionTitle>
                                    <VariantProductionTable
                                        // Um tamanho novo (ou um que desapareça)
                                        // remonta a aba: o formulário nasce dos
                                        // tamanhos que existem.
                                        key={productionBySize(editing.variants)
                                            .map((group) => group.key)
                                            .join('|')}
                                        editing={editing}
                                    />
                                </section>
                            )}
```

- [ ] **Step 4: Type + lint gates**

Run: `npm run types:check` e `npm run lint:check`
Expected: sem erros. Se o lint se queixar de formatação, correr `npm run lint` e rever o diff. Se o tsc não aceitar o cast `errors as Record<string, string | undefined>`, usar `(errors as unknown as Record<string, string | undefined>)`.

- [ ] **Step 5: Verify in the browser (BD local)**

1. Arrancar o dev server com `preview_start` (`.claude/launch.json`; se não existir, criá-lo com o comando de dev do projeto). **BD local, nunca a de produção.**
2. Na BD local, fazer um produto com variantes 10cm e 20cm em duas cores e um material com preço/kg. Usar o próprio backoffice: criar o produto pela matriz com os tamanhos Pequeno/Grande, ou criar variantes à mão com "Tamanho / designação" a 10cm e 20cm.
3. Abrir o produto. A secção "Impressão" mostra dois blocos com a legenda "10cm · 2 variantes" e "20cm · 2 variantes".
4. Pôr 2h08/40g e 6h51/189g e clicar Guardar. O toast diz "Tempo e gramagem guardados nas 4 variantes." Os blocos ficam com os valores, e "Aplicar preços calculados" fica disponível.
5. Aplicar preços. O "Sugerido" e o "Atual" de 10cm ficam diferentes dos de 20cm.
6. Escrever 75 nos minutos de um bloco e guardar. O erro aparece por baixo **desse** bloco.
7. Abrir "Nova variante", criar uma com o tamanho "30cm" e fechar. Aparece um terceiro bloco "30cm · 1 variante", sem erro ao guardar (Review Focus 5).
8. Num produto sem tamanhos, a secção mostra um bloco só, sem legenda, como antes.
9. Ver na consola do browser (`read_console_messages`) que não há erros, e tirar uma captura de ecrã como prova.

- [ ] **Step 6: Commit**

```bash
git add resources/js/lib/production.ts resources/js/components/admin/variant-production-table.tsx resources/js/pages/admin/produtos/form.tsx
git commit -m "Impressao: um bloco de tempo e gramagem por tamanho"
```

---

### Task 4: Fecho do ramo

- [ ] **Step 1: Suite completa por partes (PowerShell)**

O `composer test` passa do próprio timeout. Correr as peças à mão:
`php artisan test tests/Unit`, `php artisan test tests/Feature/Admin`, `php artisan test tests/Feature/Mcp`, `php artisan test tests/Feature/Etsy` e o resto de `tests/Feature` por pastas.
Expected: tudo verde.

- [ ] **Step 2: Os cinco portões**

`vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run types:check` e `npm run lint:check`
Expected: tudo verde.

- [ ] **Step 3: Commit the spec and the plan, if they are not in yet**

```bash
git add docs/superpowers/specs/2026-10-14-tamanhos-variantes-design.md docs/superpowers/plans/2026-10-14-tamanhos-impressao.md
git commit -m "Docs: desenho dos tamanhos nas variantes e plano da fase 1"
```

- [ ] **Step 4: Parar.** Sem push nem merge. Dizer ao dono que o ramo `feat/tamanhos-impressao` está pronto, com o resultado dos portões e a captura de ecrã.
