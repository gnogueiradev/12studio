# Loja online: carrinho e compra sem conta — plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** o cliente compra sozinho na montra (carrinho, dados, Stripe) e a encomenda nasce no backoffice quando o Stripe confirma o pagamento.

**Architecture:** o carrinho vive na BD atrás de um cookie com um token; um só calculador (`CartQuoter`) dá as linhas e os totais ao carrinho, ao checkout e ao retrato. "Pagar" grava um `checkout` com o retrato congelado e abre uma sessão no Stripe a partir de uma descrição neutra (`Charge`), que também serve o link de pagamento. O webhook chega à encomenda pelo `checkout` e cria-a nessa altura.

**Tech Stack:** Laravel 13.34 (cliente HTTP da casa, sem SDK do Stripe), Inertia 3 + React + TypeScript, SQLite, PHPUnit 12 com `FakesStripe`.

**Spec:** `docs/superpowers/specs/2026-11-18-loja-carrinho-checkout-design.md`

**Ramo:** `worktree-loja-carrinho`. Nada de merge no `main` nem push sem o dono pedir: o merge no `main` é o deploy.

## Global Constraints

- PHP, composer, artisan, pint e phpstan só pelo PowerShell (Herd). `git` e `grep` podem ir pelo Bash.
- Nada de `composer update`. O Laravel fica na 13.34.
- Só migrações aditivas. Em tabelas que já existem (`checkouts`), colunas novas sem `constrained()`. Conferir com `php artisan migrate --pretend`.
- Os preços vêm sempre da BD, nunca do pedido.
- Um GET nunca cria nada: nem carrinho, nem `checkout`, nem sessão no Stripe.
- Sem cores fixas do Tailwind no TSX (`text-white`, `bg-black`, `text-red-500`…): só os tokens da marca. O `DesignTokensTest` chumba-as.
- Todo o texto da loja passa pelo `useT()` e existe nos quatro `lang/{pt,en,es,fr}/store.php`.
- Antes do `tsc` e do ESLint: `php artisan wayfinder:generate --with-form`.
- URIs em português e um URI por ação fora de um `Route::resource` (o Wayfinder duplica chaves quando dois verbos partilham o URI).
- Portes: `config('shop.shipping')`, 450 cêntimos, grátis a partir de 2500 de subtotal antes do desconto.
- Mínimo do Stripe: 50 cêntimos.
- Uma coisa pesada de cada vez nesta máquina (suite, PHPStan, Pint, build). A suite completa demora mais de 13 minutos: corre em background para um ficheiro em `storage\logs\`.
- Mensagens de commit num ficheiro do scratchpad e `git commit -F <ficheiro>`.

**Portões**, no fim de cada tarefa que toque no que eles cobrem:

```
vendor\bin\pint --parallel --test
vendor\bin\phpstan analyse --no-progress
php artisan test <ficheiros de teste da tarefa>
php artisan test --testsuite=Unit
npm run lint:check ; npm run format:check ; npm run types:check
```

## Review Focus

1. **O Stripe avisa duas vezes, ou dois avisos chegam juntos** (`completed` e `async_payment_succeeded`): nasce uma só encomenda. Teste na Tarefa 9.
2. **O carrinho muda depois de o cliente clicar em "Pagar"** (outro separador): a encomenda segue o retrato, não o carrinho. Teste na Tarefa 9.
3. **Um preço ou o stock mudam entre o cliente ver o total e clicar em "Pagar"**: não se cobra um total que ele não viu; volta ao checkout com o total novo. Teste na Tarefa 8.
4. **Personalização hostil ou errada** (campo obrigatório vazio, texto acima do limite, chave que o produto não tem, HTML): recusada ou limpa no servidor. Teste na Tarefa 5.
5. **O cliente volta do Stripe antes do webhook, ou alguém abre o link de outra compra**: "a confirmar" sem erro; token desconhecido dá 404; a página nunca mostra email, telefone nem morada. Teste na Tarefa 10.

---

## Mapa de ficheiros

| Ficheiro | Responsabilidade |
|---|---|
| `app/Shop/ShopGate.php` | A compra está ativa para este visitante? |
| `app/Http/Middleware/EnsureShopOpen.php` | Alias `shop.open`: 404 quando não está |
| `app/Shop/Buyable.php` | A regra única do que se pode comprar |
| `app/Models/Cart.php`, `CartItem.php` | O carrinho |
| `app/Shop/CartService.php` | Encontrar, criar, mexer no carrinho |
| `app/Shop/CartQuoter.php`, `Quote.php`, `QuoteLine.php` | Linhas e totais, lidos da BD |
| `app/Shop/CartPresenter.php` | O carrinho como a montra o mostra |
| `app/Http/Controllers/Store/CartController.php` | Rotas do carrinho |
| `app/Stripe/Charge.php`, `ChargeLine.php` | O que há a cobrar, de uma encomenda ou de um `checkout` |
| `app/Shop/CheckoutService.php` | "Pagar": retrato + sessão do Stripe |
| `app/Http/Controllers/Store/CheckoutController.php` | `/finalizar` |
| `app/Shop/CheckoutCompletion.php` | Do `checkout` à encomenda |
| `app/Http/Controllers/Store/PurchaseController.php` | `/compra/{token}` |
| `resources/js/types/shop.ts` | Tipos do carrinho, checkout e compra |
| `resources/js/components/store/buy-box.tsx` | Quantidade, personalização, "Adicionar" |
| `resources/js/components/store/cart-drawer.tsx`, `cart-lines.tsx`, `cart-totals.tsx`, `cart-button.tsx` | Painel, linhas, totais, ícone |
| `resources/js/pages/store/cart.tsx`, `checkout.tsx`, `purchase.tsx` | As três páginas |

Modificados: `app/Services/StoreSettings.php`, `app/Http/Requests/Setting/UpdateStoreSettingsRequest.php`, `resources/js/pages/admin/definicoes/montra.tsx`, `bootstrap/app.php`, `routes/store.php`, `routes/console.php`, `app/Providers/AppServiceProvider.php`, `app/PageBuilder/ReservedSlugs.php`, `app/Http/Middleware/ShareStorefront.php`, `app/Store/ProductDetail.php`, `app/Http/Controllers/Store/ProductController.php`, `resources/js/blocks/product-main.tsx`, `resources/js/components/store/store-header.tsx`, `resources/js/types/store.ts`, `app/Stripe/StripeCheckout.php`, `app/Stripe/StripeEvents.php`, `app/Services/OrderService.php`, `app/Models/Checkout.php`, `config/shop.php`, `lang/*/store.php`.

---

### Task 1: O ramo, com os códigos de desconto dentro

**Files:**
- Modify: `resources/js/pages/admin/encomendas/show.tsx` (o único conflito)

**Interfaces:**
- Produces: o ramo `worktree-loja-carrinho` com `App\Services\OrderTotalsCalculator`, `App\Services\PromoCodeValidator`, `App\Support\PromoCodeValidation`, `App\Stripe\StripeCheckout::payload()` e `config('shop.shipping')`.

- [ ] **Step 1:** `git status --short --branch` na pasta de trabalho: ramo `worktree-loja-carrinho`, limpo. O ramo já existe (foi criado com a pasta); não se cria outro.
- [ ] **Step 2:** `git merge --no-ff gn/promo-codes-stripe-a62c3d`. Conflito esperado só em `resources/js/pages/admin/encomendas/show.tsx` (3 blocos).
- [ ] **Step 3:** Resolver ficando com as duas coisas: a estrutura do `main` (redesenho das encomendas e os achados do frontend) e, do ramo, o código de desconto na ficha, o desconto nos totais e o link de pagamento. Nenhum bloco se resolve escolhendo um lado inteiro.
- [ ] **Step 4:** `php artisan migrate --pretend` mostra só `promo_codes`, `promo_code_redemptions` e as três colunas em `orders`.
- [ ] **Step 5:** `php artisan wayfinder:generate --with-form` e os portões todos. A suite completa em background: `php artisan test *> storage\logs\loja-t1.txt`. Esperado: zero falhas.
- [ ] **Step 6:** Commit do merge.

### Task 2: O interruptor "Compras na montra"

**Files:**
- Create: `app/Shop/ShopGate.php`, `app/Http/Middleware/EnsureShopOpen.php`, `tests/Feature/Shop/ShopGateTest.php`
- Modify: `app/Services/StoreSettings.php`, `app/Http/Requests/Setting/UpdateStoreSettingsRequest.php`, `resources/js/pages/admin/definicoes/montra.tsx`, `bootstrap/app.php`

**Interfaces:**
- Produces:
  - `StoreSettings::KEY_SHOP = 'store.shop'`; `StoreSettings::SHOP_MODES = ['off', 'staff', 'on']`; `StoreSettings::shopMode(): string` (por omissão `'off'`). `toForm()` e `fromForm()` ganham `shop`.
  - `ShopGate::ready(): bool` — `StripeClient::isEnabled()` e `StripeWebhookSignature::isConfigured()`.
  - `ShopGate::allows(?User $user): bool` — `ready()` e (modo `on`, ou modo `staff` com `$user?->isAdmin()`).
  - Alias de middleware `shop.open` (`EnsureShopOpen`): `abort(404)` quando `allows()` é falso.

- [ ] **Step 1: Testes que falham** (`ShopGateTest`, com `FakesStripe` e `RefreshDatabase`):

```php
public function test_it_is_off_by_default(): void
// fakeStripe(); assertFalse(allows(null)); assertFalse(allows($admin));

public function test_staff_mode_only_lets_admins_in(): void
// modo 'staff': allows($admin) true; allows(null) false; allows($cliente) false;
// allows($contaDeProducao) false.

public function test_on_lets_everyone_in(): void
// modo 'on': allows(null) true.

public function test_without_stripe_or_without_the_webhook_secret_it_behaves_as_off(): void
// modo 'on' + config(['stripe.enabled' => false])      → allows(null) false
// modo 'on' + config(['stripe.webhook_secret' => null]) → allows(null) false

public function test_the_owner_saves_the_mode_in_store_settings(): void
// PATCH admin.montra.update com shop=staff → shopMode() === 'staff';
// shop=talvez → erro de validação em 'shop'.
```

- [ ] **Step 2:** Correr: `php artisan test tests/Feature/Shop/ShopGateTest.php`. Falha: a classe não existe.
- [ ] **Step 3:** Implementar o que as Interfaces dizem. Regra do pedido: `'shop' => ['required', Rule::in(StoreSettings::SHOP_MODES)]`.
- [ ] **Step 4:** Em `montra.tsx`, por baixo de "Loja aberta", um seletor "Compras na montra" com "Desligadas", "Só para a equipa" e "Ligadas". A página recebe `shopReady` (de `ShopGate::ready()`); quando é falso, um aviso por baixo: "O Stripe ou o segredo do webhook não estão configurados: as compras ficam desligadas seja qual for a escolha."
- [ ] **Step 5:** Testes a passar, portões, commit.

### Task 3: O que se pode comprar

**Files:**
- Create: `app/Shop/Buyable.php`, `tests/Feature/Shop/BuyableTest.php`

**Interfaces:**
- Produces:

```php
final readonly class Buyable
{
    public const NOT_FOR_SALE = 'not_for_sale'; // fora da montra, variante escondida
    public const CUSTOM = 'custom';
    public const SOLD_OUT = 'sold_out';
    public const OVER_STOCK = 'over_stock';     // há stock, mas menos do que o pedido

    public bool $ok;
    public ?string $mode;    // 'in_stock' | 'made_to_order' quando ok
    public ?int $maxQty;     // teto por stock; null = só o limite geral
    public ?string $reason;

    public static function check(Variant $variant, int $qty): self;
}
```

  O limite geral é `config('shop.cart.max_qty')` (20). `check` exige `$variant->product` carregado ou carrega-o.

- [ ] **Step 1: Testes que falham**, um por linha da tabela do spec:

```php
public function test_custom_products_are_never_bought(): void          // ok false, reason CUSTOM
public function test_made_to_order_is_bought_with_production(): void  // ok, mode made_to_order, maxQty null
public function test_in_stock_with_enough_stock_is_bought_from_the_shelf(): void
// stock 5, qty 3 → ok, mode in_stock, maxQty 5
public function test_short_stock_with_backorder_makes_the_whole_line_to_order(): void
// stock 2, allow_backorder, qty 3 → ok, mode made_to_order, maxQty null
public function test_short_stock_without_backorder_stops_at_what_there_is(): void
// stock 2, qty 3 → ok false, reason OVER_STOCK, maxQty 2
public function test_no_stock_without_backorder_is_sold_out(): void    // reason SOLD_OUT
public function test_reserved_stock_does_not_count(): void
// stock 5, reserved_stock 4, qty 2 → OVER_STOCK, maxQty 1
public function test_draft_archived_deleted_and_hidden_variants_are_not_for_sale(): void
// produto draft, archived, apagado; variante active=false; cor desativada → NOT_FOR_SALE
public function test_quantity_is_between_one_and_the_limit(): void
// qty 0 e qty 21 → ok false
```

- [ ] **Step 2:** Correr e ver falhar.
- [ ] **Step 3:** Implementar. "À venda" é o que `Product::scopeVisibleInStore` e `Variant::scopeVisibleInStore` já dizem: reutilizar as mesmas condições, não as reescrever.
- [ ] **Step 4:** Testes a passar, portões, commit.

### Task 4: O carrinho na base de dados e as contas

**Files:**
- Create: `database/migrations/2026_11_18_000010_create_carts_tables.php`, `app/Models/Cart.php`, `app/Models/CartItem.php`, `database/factories/CartFactory.php`, `database/factories/CartItemFactory.php`, `app/Shop/CartService.php`, `app/Shop/CartQuoter.php`, `app/Shop/Quote.php`, `app/Shop/QuoteLine.php`, `tests/Feature/Shop/CartQuoterTest.php`, `tests/Feature/Shop/CartServiceTest.php`
- Modify: `config/shop.php`, `routes/console.php`

**Interfaces:**
- Consumes: `Buyable::check`, `OrderTotalsCalculator::calculate`, `PromoCodeValidator::validate`.
- Produces:
  - `config('shop.cart')`: `['cookie' => '12studio_carrinho', 'days' => 60, 'max_lines' => 30, 'max_qty' => 20]`.
  - Tabela `carts`: `id`, `token` (64, único), `promo_code` (40, nulo), timestamps.
  - Tabela `cart_items`: `id`, `cart_id` (FK, apaga em cascata), `variant_id` (FK, apaga em cascata), `qty` (smallint), `personalization` (json, nulo), `personalization_hash` (32), timestamps; único em (`cart_id`, `variant_id`, `personalization_hash`).
  - `Cart` usa `Prunable`: parado há mais de `shop.cart.days` dias. Entra na lista do `model:prune` de `routes/console.php`.
  - `CartService`:

```php
public function find(?string $token): ?Cart;                 // nunca cria
public function add(?Cart $cart, Variant $variant, int $qty, array $personalization): Cart; // cria se null
public function setQty(CartItem $item, int $qty): void;
public function remove(CartItem $item): void;
public function setPromoCode(Cart $cart, ?string $code): void;
public function clear(Cart $cart): void;                     // apaga as linhas e o código
/** Limpa a personalização contra os campos do produto; lança ValidationException. */
public function personalizationFor(Product $product, array $input): array;
```

  Todas as escritas fazem `touch()` ao carrinho. `add` com a mesma variante e a mesma personalização soma à linha existente; lança `ValidationException` quando passa `max_lines`, `max_qty` ou o `maxQty` do `Buyable`.
  - `CartQuoter::quote(Cart $cart, ?string $email = null): Quote`.
  - `Quote` (readonly): `lines: list<QuoteLine>`, `subtotalCents`, `discountCents`, `shippingCents`, `totalCents`, `freeShippingLeftCents`, `promoCode: ?string`, `promoStatus: ?string` (`'applied'|'pending_email'|'refused'`), `promoMessage: ?string`, `promoCodeId: ?int`, `problems: bool`, `belowMinimum: bool` (há linhas e o total fica abaixo de 50), `canCheckout(): bool`.
  - `QuoteLine` (readonly): `itemId`, `variant: Variant`, `productName`, `variantLabel: ?string`, `sku: ?string`, `qty`, `maxQty: ?int`, `unitCents`, `surchargeCents`, `lineCents`, `vatRate`, `personalization: ?array`, `mode: ?string`, `imageUrl: ?string`, `leadDays: ?array`, `problem: ?string` (uma razão do `Buyable`).

- [ ] **Step 1: Testes que falham** (`CartQuoterTest`):

```php
public function test_prices_come_from_the_database_every_time(): void
// linha de 1500 ×2 → subtotal 3000; mudar price_cents para 1800 → subtotal 3600.

public function test_the_surcharge_is_per_unit_and_only_when_personalised(): void
// produto com sobretaxa 300, variante 1500, qty 2, personalização preenchida
// → unitCents 1500, surchargeCents 300, lineCents 3600. Sem personalização → 3000.

public function test_shipping_is_450_below_2500_and_free_from_there(): void
// subtotal 2400 → shipping 450, total 2850, freeShippingLeftCents 100;
// subtotal 2500 → shipping 0, freeShippingLeftCents 0.

public function test_free_shipping_looks_at_the_subtotal_before_the_discount(): void
// subtotal 2600, código de 500 → shipping 0, total 2100.

public function test_a_code_that_needs_an_email_waits_for_it(): void
// código pessoal, sem email → promoStatus 'pending_email', discountCents 0;
// com o email certo → 'applied'; com outro email → 'refused' com a mensagem do validador.

public function test_a_refused_code_does_not_block_the_checkout(): void
// código expirado → promoStatus 'refused', canCheckout() true, discountCents 0.

public function test_a_line_that_can_no_longer_be_bought_blocks_the_checkout(): void
// stock passa a 0 → line.problem SOLD_OUT, problems true, canCheckout() false,
// e a linha não entra no subtotal.

public function test_an_empty_cart_cannot_check_out(): void

public function test_a_total_below_50_cents_cannot_check_out(): void
// subtotal 2600, código de 2580 → total 20 → belowMinimum true, canCheckout() false.
```

  `CartServiceTest`: `add` cria o carrinho com token de 64 caracteres; a mesma variante e personalização soma; personalização diferente faz outra linha; a 31.ª linha e a quantidade 21 são recusadas; `clear` apaga linhas e código; `personalizationFor` (ver Review Focus 4, exercitado por HTTP na Tarefa 5).

- [ ] **Step 2:** Correr e ver falhar.
- [ ] **Step 3:** Migração, modelos, fábricas, `CartService`, `CartQuoter`. O nome do produto e o rótulo da variante seguem o que `OrderService::resolveLines` usa (`$product->name`, `$variant->size_label`), em português. `leadDays` segue `ProductDetail::leadDays` (torná-lo público ou extrair, sem duplicar). `imageUrl` é a fotografia principal do produto.
- [ ] **Step 4:** `php artisan migrate --pretend`: só as duas tabelas novas.
- [ ] **Step 5:** Testes a passar, portões, commit.

### Task 5: As rotas do carrinho

**Files:**
- Create: `app/Http/Controllers/Store/CartController.php`, `app/Shop/CartPresenter.php`, `app/Http/Requests/Shop/AddToCartRequest.php`, `app/Http/Requests/Shop/UpdateCartItemRequest.php`, `app/Http/Requests/Shop/ApplyPromoCodeRequest.php`, `resources/js/pages/store/cart.tsx` (só o esqueleto, para a rota renderizar), `tests/Feature/Shop/CartTest.php`
- Modify: `routes/store.php`, `app/Providers/AppServiceProvider.php`, `app/PageBuilder/ReservedSlugs.php`, `app/Http/Middleware/ShareStorefront.php`

**Interfaces:**
- Consumes: `CartService`, `CartQuoter`, `ShopGate`, alias `shop.open`.
- Produces:
  - Rotas, dentro do closure `$storefront` (ficam com os nomes `store.*` e `i18n.store.*`), num grupo com `shop.open`:

| Verbo | URI | Nome | Throttle |
|---|---|---|---|
| GET | `carrinho` | `store.cart` | |
| POST | `carrinho/itens` | `store.cart.add` | `cart` |
| PATCH | `carrinho/itens/{item}/quantidade` | `store.cart.update` | `cart` |
| DELETE | `carrinho/itens/{item}/remover` | `store.cart.remove` | `cart` |
| POST | `carrinho/codigo` | `store.cart.promo` | `cart` |
| DELETE | `carrinho/codigo/remover` | `store.cart.promo.remove` | `cart` |

  - `RateLimiter::for('cart')`: 60 por minuto por IP.
  - `carrinho`, `finalizar` e `compra` em `ReservedSlugs`.
  - `CartPresenter::make(?Cart $cart, ?string $email = null): array` com a forma de `CartData` (Tarefa 6). Sem carrinho devolve o carrinho vazio.
  - As escritas respondem `back()` com `flash('cart', CartPresenter::make(...))`, o padrão que `flash.conversation` já usa.
  - `storefront.shop: bool` (`ShopGate::allows`) e `storefront.cartCount: int` (soma das quantidades; 0 sem cookie, sem consultar a BD).
  - O cookie `config('shop.cart.cookie')` leva o token, 60 dias, `httpOnly`, `sameSite=lax`. Só o POST de adicionar o cria.
  - `{item}` resolve-se **dentro do carrinho do cookie**: o item de outro carrinho dá 404.

- [ ] **Step 1: Testes que falham** (`CartTest`, com `fakeStripe()` e o modo `on` no `setUp`):

```php
public function test_with_the_shop_off_the_cart_does_not_exist(): void
// modo 'off': GET /carrinho → 404; POST /carrinho/itens → 404.

public function test_opening_pages_never_creates_a_cart(): void
// GET /carrinho e GET /produtos/vaso → Cart::count() === 0 e sem cookie do carrinho.

public function test_adding_creates_the_cart_and_sets_the_cookie(): void
// POST {variant_id, qty: 2} → 1 carrinho, 1 linha com qty 2, cookie presente,
// flash 'cart' com subtotalCents e count 2.

public function test_the_price_in_the_request_is_ignored(): void
// POST com price_cents: 1 → a linha vale o preço da BD.

public function test_what_cannot_be_bought_is_refused(): void
// produto custom, variante sem stock, variante de produto em rascunho → erro em 'qty'
// ou 'variant_id', Cart::count() === 0.

public function test_personalisation_is_validated_against_the_product(): void
// campo obrigatório vazio → erro; texto com mais do que maxLength → erro;
// chave que o produto não tem → descartada; '<b>Ana</b>' → guardado sem tags.

public function test_quantity_changes_and_removal_only_touch_my_cart(): void
// PATCH e DELETE num item de outro carrinho → 404.

public function test_quantity_cannot_pass_the_stock(): void
// stock 2 sem espera, PATCH qty 3 → erro, a linha fica em 2.

public function test_a_code_is_kept_and_quoted(): void
// POST /carrinho/codigo VERAO → carts.promo_code 'VERAO', flash com discountCents;
// DELETE remove-o.

public function test_the_header_count_follows_the_cart(): void
// com 3 peças: GET /produtos → storefront.cartCount 3 e storefront.shop true.

public function test_the_cart_works_under_a_language_prefix(): void
// POST /en/carrinho/itens e GET /en/carrinho.

public function test_staff_mode_shows_the_cart_only_to_admins(): void
// modo 'staff': visitante 404; admin 200.
```

- [ ] **Step 2:** Correr e ver falhar.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Testes a passar, portões (com `wayfinder:generate`), commit.

### Task 6: O carrinho no ecrã

**Files:**
- Create: `resources/js/types/shop.ts`, `resources/js/components/store/buy-box.tsx`, `cart-drawer.tsx`, `cart-lines.tsx`, `cart-totals.tsx`, `cart-button.tsx`
- Modify: `resources/js/blocks/product-main.tsx`, `resources/js/components/store/store-header.tsx`, `resources/js/types/store.ts`, `resources/js/pages/store/cart.tsx`, `app/Store/ProductDetail.php`, `app/Http/Controllers/Store/ProductController.php`, `lang/{pt,en,es,fr}/store.php`, `tests/Feature/Store/ProductPageTest.php`

**Interfaces:**
- Consumes: rotas e `flash.cart` da Tarefa 5; `Buyable::check`.
- Produces:
  - Em `ProductDetail::make`, cada variante ganha `buy: {ok: boolean, mode: 'in_stock'|'made_to_order'|null, maxQty: number|null}`, de `Buyable::check($variant, 1)`.
  - `resources/js/types/shop.ts`:

```ts
export type CartLine = {
    id: number; variantId: number; name: string; label: string | null;
    href: string; image: string | null; qty: number; maxQty: number | null;
    unitCents: number; surchargeCents: number; lineCents: number;
    personalization: { label: string; value: string }[];
    mode: 'in_stock' | 'made_to_order' | null;
    leadDays: [number, number] | null;
    problem: 'not_for_sale' | 'custom' | 'sold_out' | 'over_stock' | null;
};
export type CartData = {
    lines: CartLine[]; count: number;
    subtotalCents: number; discountCents: number; shippingCents: number;
    totalCents: number; freeShippingLeftCents: number;
    promo: { code: string; status: 'applied' | 'pending_email' | 'refused'; message: string | null } | null;
    /** O código deixa o total abaixo de 0,50 €: não se usa na loja. */
    belowMinimum: boolean;
    canCheckout: boolean;
};
```

  - `Storefront` ganha `shop: boolean` e `cartCount: number`.
  - Grupo `cart` novo nos quatro ficheiros de tradução.

- [ ] **Step 1: Teste que falha** em `ProductPageTest`:

```php
public function test_each_variant_says_whether_it_can_be_bought(): void
// variante com stock → buy.ok true, mode 'in_stock';
// produto custom → buy.ok false; e storefront.shop segue o ShopGate.
```

- [ ] **Step 2:** Correr, ver falhar, implementar o lado PHP, ver passar.
- [ ] **Step 3: `buy-box.tsx`.** Com `storefront.shop` e `selected.buy.ok`: quantidade (1 até `maxQty`), os campos de personalização do produto (rótulo, obrigatório, limite, sobretaxa à vista) e o botão "Adicionar ao carrinho", que faz POST para `store.cart.add` com `preserveScroll`. Os erros do servidor aparecem junto do campo. Sem `shop` ou sem `buy.ok`, o `product-main.tsx` mostra exatamente os botões de hoje. "Fazer uma pergunta" fica nos dois casos. A barra fixa do telemóvel segue a mesma regra.
- [ ] **Step 4: `cart-drawer.tsx`.** Um `Sheet` com a classe `store-theme` que abre quando chega `flash.cart`: as linhas (`cart-lines.tsx`), os totais (`cart-totals.tsx`) e os botões "Ver carrinho" e "Finalizar compra". `cart-totals.tsx` mostra "Faltam X para portes grátis" com uma barra quando `freeShippingLeftCents > 0`, e "Portes grátis" quando é 0.
- [ ] **Step 5: `/carrinho`.** As linhas com fotografia, variante, personalização, preço, quantidade editável e remover; uma linha com `problem` fica destacada com a razão e o "Finalizar" desativado enquanto `canCheckout` for falso; campo do código com os três estados (`pending_email`: "Confirmamos este código no passo seguinte, com o teu email"); com `belowMinimum`, o aviso "Com este código o total fica abaixo de 0,50 € e não o conseguimos cobrar aqui. Fala connosco." e o link para o contacto; totais; carrinho vazio com link para o catálogo.
- [ ] **Step 6: `cart-button.tsx`** no `store-header.tsx`, ao lado do `LanguageSwitcher`, só com `storefront.shop`: ícone, o número de peças e um nome acessível ("Carrinho, 3 peças"). Liga a `/carrinho`.
- [ ] **Step 7:** Portões de frontend e `php artisan test --testsuite=Unit` (o `DesignTokensTest`). Contraste WCAG AA no número do ícone e na barra dos portes.
- [ ] **Step 8:** Ver no browser, numa cópia da BD (como está em `ver-a-montra-localmente`: cópia do SQLite no scratchpad, semear, `artisan serve --no-reload`, `npm run build` antes de recarregar): adicionar, somar, mudar quantidade, remover, código, em ecrã largo e a 375 px. Commit.

### Task 7: O Stripe cobra uma descrição, não uma encomenda

**Files:**
- Create: `app/Stripe/Charge.php`, `app/Stripe/ChargeLine.php`, `tests/Feature/Stripe/ChargeTest.php`
- Modify: `app/Stripe/StripeCheckout.php`

**Interfaces:**
- Produces:

```php
final readonly class ChargeLine
{
    public function __construct(
        public string $name,            // "Vaso (G)"
        public int $qty,
        public int $lineTotalCents,
        public ?Variant $variant = null,
    ) {}
}

final readonly class Charge
{
    /** @param list<ChargeLine> $lines  @param array<string, string> $metadata */
    public function __construct(
        public string $reference,       // client_reference_id
        public array $metadata,
        public string $label,           // "encomenda 2026-0042" | "compra 17", para os erros
        public string $summary,         // o texto por baixo do botão de pagar
        public string $currency,
        public ?string $email,
        public array $lines,
        public int $shippingCents,
        public ?string $shippingName,
        public int $extraCents,         // ajuste positivo, como linha
        public ?string $extraName,
        public int $couponCents,
        public ?string $couponName,
        public int $totalCents,
        public string $successUrl,
        public string $cancelUrl,
        public ?int $expiresAt = null,  // timestamp
    ) {}

    public static function fromOrder(Order $order, string $returnUrl): self;
}
```

  - `StripeCheckout::sessionPayload(Charge $charge, bool $asLink = false, ?string $couponKey = null): array` — o corpo do `payload()` de hoje, a ler do `Charge`.
  - `StripeCheckout::payload(Order $order, string $returnUrl, bool $asLink = false, ?string $couponKey = null): array` fica, como `sessionPayload(Charge::fromOrder(...), ...)`. O `StripePaymentLinkService` não muda.

- [ ] **Step 1: Rede de segurança.** `php artisan test tests/Feature/Stripe tests/Feature/PromoCodes` antes de tocar em nada. Tem de passar, e tem de continuar a passar no fim **sem editar nenhum destes testes**: é isso que prova que o link de pagamento não mudou.
- [ ] **Step 2: Testes que falham** (`ChargeTest`):

```php
public function test_a_charge_from_an_order_carries_what_the_payload_needs(): void
// encomenda com 2 linhas, portes 400, desconto 500, ajuste -200 →
// reference = id; metadata order_id e order_number; shippingCents 400;
// couponCents 700; extraCents 0; totalCents = order->total_cents;
// successUrl = returnUrl.'?pago=1'.

public function test_a_charge_that_does_not_add_up_is_never_sent(): void
// Charge com linhas a somar 3000, portes 450, totalCents 9999 → StripeException,
// zero pedidos ao Stripe.

public function test_below_the_stripe_minimum_nothing_is_sent(): void   // totalCents 49

public function test_expires_at_reaches_the_session(): void
// expiresAt = now()+3600 → o pedido leva expires_at.
```

- [ ] **Step 3:** Implementar e correr os testes novos e os do Step 1.
- [ ] **Step 4:** Portões, commit.

### Task 8: `/finalizar` e o "Pagar"

**Files:**
- Create: `database/migrations/2026_11_18_000020_add_store_columns_to_checkouts_table.php`, `database/factories/CheckoutFactory.php`, `app/Shop/CheckoutService.php`, `app/Http/Controllers/Store/CheckoutController.php`, `app/Http/Requests/Shop/StartCheckoutRequest.php`, `resources/js/pages/store/checkout.tsx`, `tests/Feature/Shop/CheckoutTest.php`
- Modify: `app/Models/Checkout.php`, `app/Stripe/Charge.php`, `app/Stripe/StripeCheckout.php`, `routes/store.php`, `app/Providers/AppServiceProvider.php`, `config/shop.php`, `lang/*/store.php`, `resources/js/types/shop.ts`

**Interfaces:**
- Consumes: `CartQuoter::quote`, `Charge`, `StripeCheckout::sessionPayload`, `LocalizedUrl::path`.
- Produces:
  - Colunas novas em `checkouts` (todas sem FK): `token` (64, nulo, único), `cart_id` (nulo), `order_id` (nulo), `customer` (json, nulo), `shipping_address` (json, nulo), `shipping_cents` (0), `discount_cents` (0), `promo_code_id` (nulo), `promo_code` (40, nulo), `total_cents` (0), `locale` (5, nulo). `Checkout` ganha o `Fillable`, os casts e `order(): BelongsTo`.
  - `config('shop.checkout.session_minutes')`: 60.
  - `Checkout` usa `Prunable`: sem encomenda e criado há mais de 30 dias. São dados pessoais de quem não chegou a comprar. Entra na lista do `model:prune` de `routes/console.php`. Um `checkout` com encomenda nunca é apagado.
  - `items_snapshot`: uma entrada por linha com `variant_id`, `product_name`, `variant_label`, `sku`, `qty`, `unit_price_cents`, `personalization_surcharge_cents`, `line_total_cents`, `vat_rate`, `personalization`, `fulfillment_mode`, `image_url`.
  - `customer`: `name`, `email`, `phone`, `nif`. `shipping_address`: a forma de `OrderService::addressSnapshot` (`name`, `line1`, `line2`, `postalCode`, `city`, `country: 'PT'`, `phone`).
  - `CheckoutService::start(Cart $cart, array $customer, array $address, string $locale, int $expectedTotalCents): string` — o endereço do Stripe. Lança `ValidationException` (chave `cart`) se o orçamento não deixa finalizar ou se o total recalculado não é `$expectedTotalCents`.
  - `Charge::fromCheckout(Checkout $checkout, string $successUrl, string $cancelUrl): self` — `reference` = `'checkout-'.$id`; `metadata` = `['checkout_id' => (string) $id]`; `label` = `"compra {$id}"`.
  - `StripeCheckout::startForCheckout(Checkout $checkout, string $successUrl, string $cancelUrl): string` — cria a sessão, grava `stripe_session_id` e `expires_at`, devolve o URL.
  - Rotas, no mesmo grupo `shop.open`: `GET finalizar` (`store.checkout`) e `POST finalizar/pagar` (`store.checkout.pay`, throttle `checkout`: 10 por minuto por IP).
  - `success_url` = `/compra/{token}`; `cancel_url` = `/finalizar`; os dois com o prefixo do idioma.

- [ ] **Step 1: Testes que falham** (`CheckoutTest`):

```php
public function test_opening_the_checkout_creates_nothing(): void
// GET /finalizar com carrinho → 200, Checkout::count() 0, zero pedidos ao Stripe.

public function test_an_empty_or_blocked_cart_goes_back_to_the_cart(): void
// sem carrinho, ou com uma linha com problema → redirect para /carrinho.

public function test_paying_freezes_the_purchase_and_opens_a_stripe_session(): void
// POST válido → 1 checkout 'open' com token de 64, items_snapshot com os preços da BD,
// customer e shipping_address, total_cents = total do orçamento, locale 'pt';
// 1 sessão no fake com client_reference_id 'checkout-{id}', metadata.checkout_id,
// amount_total = total_cents, expires_at a 60 minutos; a resposta leva ao URL da sessão;
// Order::count() === 0.

public function test_customer_data_is_validated(): void
// nome, email e telemóvel obrigatórios; código postal fora de NNNN-NNN recusado;
// NIF opcional mas, se vier, com 9 dígitos e dígito de controlo certo; termos por aceitar recusado;
// '9500-123' (Açores) e '9000-001' (Madeira) aceites.

public function test_a_personal_code_is_confirmed_with_the_email(): void
// código pessoal de ana@example.com: POST com esse email → discount_cents no checkout
// e cupão na sessão; com outro email → erro em 'promo_code', nada criado.

public function test_a_total_the_customer_did_not_see_is_not_charged(): void
// GET mostra 2850; o preço sobe; POST com expected_total_cents 2850 → volta a /finalizar
// com erro em 'cart', Checkout::count() 0, zero sessões.

public function test_stock_gone_since_the_cart_stops_the_payment(): void

public function test_paying_twice_leaves_one_live_session(): void
// dois POST seguidos do mesmo carrinho → a primeira sessão fica 'expired' no fake
// e o primeiro checkout 'expired'.

public function test_when_stripe_fails_the_customer_can_try_again(): void
// o fake recusa a sessão → volta a /finalizar com erro genérico, checkout 'expired',
// o carrinho intacto.

public function test_with_the_shop_off_the_checkout_does_not_exist(): void

public function test_old_checkouts_without_an_order_are_pruned(): void
// sem encomenda e com 31 dias → apagado pelo model:prune; com 29 dias fica;
// com encomenda e 400 dias fica.
```

- [ ] **Step 2:** Correr e ver falhar.
- [ ] **Step 3:** Migração (`migrate --pretend`: só `alter table checkouts add column …` e o índice), modelo, fábrica, serviço, controlador, pedido. Um `Cache::lock("shop-checkout:{$cart->id}", StripeCheckout::LOCK_SECONDS)` à volta do `start`. O erro do Stripe vai para `report()`; o cliente lê "Não foi possível abrir o pagamento. Tenta outra vez daqui a pouco."
- [ ] **Step 4: `checkout.tsx`.** Um só ecrã, duas colunas em ecrã largo: o formulário (nome, email, telemóvel, NIF opcional, morada, código postal, localidade; país fixo "Portugal") e o resumo (linhas, código, subtotal, desconto, portes, total). `autocomplete` certo em cada campo. A caixa "Li e aceito os termos e condições" com links para `/termos` e `/envios-e-devolucoes`. O botão "Pagar X €" manda `expected_total_cents`. Nos idiomas que não o português, um aviso: só enviamos para Portugal.
- [ ] **Step 5:** Testes a passar, portões, ver no browser até à página do Stripe de faz de conta, commit.

### Task 9: Do pagamento à encomenda

**Files:**
- Create: `app/Shop/CheckoutCompletion.php`, `tests/Feature/Shop/CheckoutWebhookTest.php`
- Modify: `app/Services/OrderService.php`, `app/Stripe/StripeEvents.php`

**Interfaces:**
- Consumes: `Checkout` e o retrato da Tarefa 8; `OrderService::setPaymentStatus`, `notePayment`; `StockService::decrement`; `OrderAlerts::created`, `soldWithoutStock`; `OrderConfirmationMail`; `CartService::clear`.
- Produces:
  - `OrderService::createFromStore(array $data, ?Closure $record = null): Order` — irmão do `createFromMarketplace`. `$data`: `customer_name`, `email`, `phone`, `nif`, `shipping_address`, `subtotal_cents`, `shipping_cents`, `discount_cents`, `promo_code_id`, `promo_code`, `total_cents`, `lines` (as entradas do `items_snapshot`), `note`. Nasce `pending_payment` / `pending`, `sales_channel` `website`, sem `user_id` nem autor. Linhas `in_stock` descontam stock com a razão `sale`; se faltar, tira o que houver, marca `stock_issue` e avisa, como o `createFromMarketplace`. **Não** marca paga: isso é de quem chama.
  - `CheckoutCompletion::orderFor(Checkout $checkout, bool $create): ?Order` — devolve a encomenda do `checkout`; se não existe e `$create`, cria-a (o `$record` grava `order_id` e `status = 'completed'` na mesma transação), esvazia o carrinho de origem e manda o `OrderConfirmationMail`. Com um tranco `Cache::lock("shop-complete:{$checkout->id}")`.
  - Em `StripeEvents`: `orderOf(array $session, bool $create = true): ?Order` passa a reconhecer também `metadata.checkout_id` com `client_reference_id === 'checkout-'.$id`, via `CheckoutCompletion::orderFor`. `sessionFailed` chama com `$create = false`. A nota de pago de uma compra da loja é "Pago na loja online."; a do link fica como está.

- [ ] **Step 1: Testes que falham** (`CheckoutWebhookTest`; os avisos montam-se e assinam-se como em `StripeWebhookTest`):

```php
public function test_a_paid_session_creates_the_order_paid(): void
// 1 encomenda: website, payment_status 'paid', sem user_id nem created_by,
// email, phone, nif e morada do checkout, subtotal/portes/desconto/total do retrato,
// promo_code; linhas com unit_price_cents, personalization_surcharge_cents,
// line_total_cents e personalization; checkout 'completed' com order_id;
// OrderConfirmationMail na fila para o email do cliente; stock descontado.

public function test_the_order_follows_the_frozen_purchase_not_the_cart(): void
// depois do "Pagar": mudar o preço da variante, a quantidade no carrinho e remover
// uma linha → a encomenda tem as linhas e os valores do retrato.

public function test_the_cart_is_emptied(): void

public function test_the_same_event_twice_creates_one_order(): void
public function test_two_different_events_for_one_session_create_one_order(): void
// 'completed' (por pagar) e depois 'async_payment_succeeded' → Order::count() 1.

public function test_multibanco_waits_and_then_pays(): void
// 'completed' com payment_status 'unpaid' → encomenda 'pending', nota no histórico;
// 'async_payment_succeeded' → 'paid'.

public function test_a_failed_multibanco_leaves_the_order_unpaid_and_payable_by_link(): void
// 'async_payment_failed' → continua 'pending', stripe_session_id nulo,
// e StripeCheckout::payable($order) é verdadeiro.

public function test_a_failed_payment_without_an_order_creates_none(): void

public function test_stock_gone_meanwhile_still_creates_the_order_with_a_flag(): void
// stock a 0 antes do aviso → encomenda paga, stock_issue true.

public function test_a_made_to_order_line_goes_to_production_without_touching_stock(): void
// linha com fulfillment_mode 'made_to_order' de um produto in_stock com espera →
// production_status 'awaiting_production', stock igual.

public function test_the_code_is_redeemed_when_paid(): void
// uses_count sobe 1 e há uma PromoCodeRedemption com o email.

public function test_an_unknown_checkout_touches_nothing(): void

public function test_the_payment_link_path_still_works(): void
// uma sessão com metadata.order_id continua a marcar a encomenda paga.
```

- [ ] **Step 2:** Correr e ver falhar.
- [ ] **Step 3:** Implementar. Abrir a ficha da encomenda criada no backoffice (`admin.encomendas.show`) num teste ou no browser: uma linha com sobretaxa tem de mostrar o total certo, porque é a primeira vez que `personalization_surcharge_cents` não é 0.
- [ ] **Step 4:** `php artisan test tests/Feature/Shop tests/Feature/Stripe tests/Feature/PromoCodes`, portões, commit.

### Task 10: `/compra/{token}`

**Files:**
- Create: `app/Http/Controllers/Store/PurchaseController.php`, `resources/js/pages/store/purchase.tsx`, `tests/Feature/Shop/PurchasePageTest.php`
- Modify: `routes/store.php`, `lang/*/store.php`, `resources/js/types/shop.ts`

**Interfaces:**
- Consumes: `Checkout` com `order`.
- Produces:
  - `GET compra/{token}` (`store.purchase`, token `[A-Za-z0-9]{64}`), no grupo das conversas: `store.locale` e `store.shared`, **sem** `store.open` nem `shop.open`. Quem pagou vê a sua compra mesmo com a loja desligada depois.
  - Prop `purchase`: `{state: 'confirming'|'paid'|'awaiting_payment'|'expired', number: string|null, lines: {name, label, qty, lineCents}[], subtotalCents, discountCents, shippingCents, totalCents, leadDays: [number, number]|null}`.
  - `confirming`: checkout `open`, sem encomenda. `paid`: encomenda paga. `awaiting_payment`: encomenda por pagar (Multibanco). `expired`: checkout `expired`, ou `open` sem encomenda e com `expires_at` passado há mais de 10 minutos (a folga é para um aviso do Stripe atrasado).

- [ ] **Step 1: Testes que falham**:

```php
public function test_before_the_webhook_it_says_confirming(): void       // state 'confirming', number null
public function test_after_payment_it_shows_the_order(): void            // 'paid', número, linhas, total
public function test_multibanco_says_awaiting_payment(): void
public function test_a_session_long_expired_without_an_order_says_expired(): void
// expires_at há 11 minutos, sem encomenda → 'expired'; há 5 minutos → 'confirming'.
public function test_an_unknown_token_is_not_found(): void
public function test_it_never_shows_personal_data(): void
// o JSON da página não contém o email, o telefone, o NIF nem a morada.
public function test_it_still_opens_with_the_shop_off(): void
public function test_opening_it_calls_no_one(): void                     // zero pedidos ao Stripe
```

- [ ] **Step 2:** Correr e ver falhar. Implementar.
- [ ] **Step 3: `purchase.tsx`.** Em `confirming`, "A confirmar o pagamento…" com `usePoll` de 3 em 3 segundos (o `conversation.tsx` já o usa), que para quando o estado muda; ao fim de 2 minutos sem confirmação, uma frase a dizer que o email de confirmação chega assim que o pagamento for confirmado. Em `paid`, o número, o resumo e o prazo. Em `awaiting_payment`, que a referência foi gerada e a encomenda avança quando for paga. Em `expired`, um link de volta ao carrinho.
- [ ] **Step 4:** Testes a passar, portões, commit.

### Task 11: A volta completa

- [ ] **Step 1:** Todos os portões, e a suite completa em background: `php artisan test *> storage\logs\loja-final.txt`. Zero falhas.
- [ ] **Step 2:** `php artisan migrate --pretend` numa cópia da BD de trabalho: só as tabelas e colunas deste plano e as do ramo dos códigos.
- [ ] **Step 3:** No browser, numa cópia da BD com a loja em "Só para a equipa": como visitante a montra está igual a hoje e `/carrinho` dá 404; como admin, comprar uma peça de stock e uma por encomenda com personalização e um código, até à página de regresso. Repetir em "Ligada" sem sessão, e a 375 px. Com o modo "Desligadas", comparar a página do produto com a do `main`: tem de ser igual.
- [ ] **Step 4:** Procurar nos ficheiros mexidos `TODO`, `FIXME`, `test.skip`, `markTestSkipped` e `->only(`: nada.
- [ ] **Step 5:** Percorrer as cinco linhas do Review Focus e as do "falhanço" do spec, e apontar o teste que cobre cada uma.
- [ ] **Step 6:** Dizer ao dono o que falta fora do código antes de ligar (está no spec): páginas legais publicadas, `STRIPE_*` e o webhook em produção, `stripe:sync-products`, métodos de pagamento na conta. Não fazer merge nem push.
