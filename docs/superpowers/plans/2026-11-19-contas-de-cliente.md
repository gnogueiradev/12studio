# Contas de cliente e ofertas para voltar a comprar — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clientes entram na montra com um link enviado por email, veem as
encomendas, compram outra vez com um botão e encontram o checkout preenchido. A
loja manda sozinha um código pessoal para a próxima compra a quem comprou no
checkout.

**Architecture:** É uma guarda de sessão nova, `customer`, sobre a mesma tabela
`users`. A `web` continua a ser só da equipa.
- **Entrada:** tokens de entrada de uso único (só o hash na BD), consumidos por
  POST.
- **Ligação das compras:** cada compra paga liga a encomenda a um cliente no
  webhook, dentro da transação que já cria a encomenda.
- **Ofertas:** um comando agendado escolhe as encomendas elegíveis pelo registo
  do checkout (`checkouts.marketing_opt_out` não nulo) e emite códigos pessoais
  no sistema de códigos que já existe.

**Tech Stack:** Laravel 13.34, Inertia 3 + React/TS, SQLite (WAL), PHPUnit 12,
Fortify, filas, agendador (`routes/console.php`).

**Spec:** `docs/superpowers/specs/2026-11-19-contas-de-cliente-design.md`

## Global Constraints

- **Migrações só aditivas.** A BD de produção tem dados: nada de `change()`,
  `dropColumn` nem `migrate:fresh`.
- **Sem `composer update`.** O Laravel está preso na 13.34.
- **PHP, artisan, pint e phpstan só no PowerShell.** Um processo pesado de cada
  vez.
- **Commits:** a mensagem vai num ficheiro do scratchpad e entra com
  `git commit -F`, em português. Nunca `git add -A`, nunca `.omc/`.
  Termina com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Sem cores fixas do Tailwind**, só os tokens do tema. Quando o contraste WCAG
  e a cor da marca chocam, ganha o contraste.
- **Textos da montra** em `lang/{pt,en,es,fr}/store.php`, num grupo novo
  `account`. O `StoreTranslationsTest` tem de passar.
- **Emails em português**, em markdown, com `<x-mail::message>`.
- **Nada de dados de clientes no Discord** (regra de `fa15b66`).
- **O link de entrada vale 20 minutos**, é de uso único e entra com
  "lembrar-me" de 60 dias.
- **Ofertas, valores por omissão:**
  - desligadas;
  - 10 %;
  - saem 21 dias depois do `shipped_at`;
  - código válido 45 dias;
  - lembrete 7 dias antes de expirar.
- **Contas da equipa** (`User::isStaff()`) nunca entram por link, nunca se
  ligam a compras e nunca recebem ofertas.
- **Emails comparam-se em minúsculas,** com `PromoCode::normalizeEmail()`
  (lower + trim).
- **A porta da loja** (`ShopGate::allows`) avalia-se sempre com
  `Auth::guard('web')->user()`, nunca com a guarda por omissão.

## Review Focus

1. **Scanners de email.** O Outlook e o Gmail abrem os links dos emails antes da
   pessoa. Um GET ao link de entrada nunca consome o token: mostra uma página
   com um botão "Entrar", e só o POST entra. (Tarefa 4)
2. **Sair da conta de cliente não tira a equipa do backoffice no mesmo browser.**
   O `logout` da guarda `customer` não invalida a sessão inteira. (Tarefa 4)
3. **O mesmo email com maiúsculas diferentes.** `Ana@Exemplo.pt` no checkout e
   `ana@exemplo.pt` na conta são a mesma pessoa, na ligação das encomendas e no
   link. (Tarefas 3 e 4)
4. **Ligar as ofertas depois de semanas desligadas.** Não pode sair uma enxurrada
   de emails. Só são elegíveis as encomendas enviadas há 21 a 51 dias (janela de
   30 dias). (Tarefa 8)
5. **"Comprar outra vez" com peças que já não se vendem.** Peças arquivadas,
   esgotadas ou sem preço ficam de fora com o nome na mensagem, e as outras
   entram ao preço de hoje. (Tarefa 6)

---

### Task 1: Esquema, guarda `customer` e emails em minúsculas

**Files:**
- Create: `database/migrations/2026_11_19_000010_add_marketing_opt_out_to_users_table.php` — `users.marketing_opt_out_at` timestamp nullable
- Create: `database/migrations/2026_11_19_000020_add_marketing_opt_out_to_checkouts_table.php` — `checkouts.marketing_opt_out` boolean nullable (null = checkout de antes desta funcionalidade)
- Create: `database/migrations/2026_11_19_000030_create_customer_login_tokens_table.php` — `id`, `email` string(255) index, `token_hash` char(64) unique, `expires_at`, `used_at` nullable, timestamps
- Create: `database/migrations/2026_11_19_000040_create_repeat_offers_table.php` — `id`, `order_id` FK unique, `promo_code_id` FK, `email` string(255) index, `sent_at`, `reminded_at` nullable, timestamps
- Create: `app/Models/CustomerLoginToken.php` (Prunable: `used_at` ou `expires_at` com mais de 1 dia), `app/Models/RepeatOffer.php`
- Create: `app/Customers/CustomerDirectory.php`
- Modify: `config/auth.php` — guarda `'customer' => ['driver' => 'session', 'provider' => 'users', 'remember' => 60 * 24 * 60]`
- Modify: `app/Models/User.php` — cast `marketing_opt_out_at` datetime; `@property`
- Modify: `app/Models/Checkout.php` — fillable + cast `marketing_opt_out` boolean
- Modify: `app/Services/CustomerService.php:122` — guarda o email com `PromoCode::normalizeEmail()`; corrige o comentário da l.28-30 (o cliente entra por link, não por "recuperar password")
- Modify: `routes/console.php` — junta `CustomerLoginToken::class` ao `model:prune`
- Test: `tests/Feature/Customers/CustomerDirectoryTest.php`

**Interfaces:**
- Produces: `CustomerDirectory::findByEmail(string $email): ?User`. Compara `lower(email)` com o email normalizado. Com mais de um resultado, devolve `null` e chama `report()` com uma exceção sem o email.
- Produces: `CustomerDirectory::forPurchase(string $email, array $customer, array $address): ?User`. A equipa dá `null`. Um cliente existente volta sem alterações. Se não houver, nasce um cliente com:
  - `name`, `phone` e `nif` de `$customer` (chaves do snapshot: `name`, `phone`, `nif`);
  - email normalizado;
  - password `Hash::make(Str::password(32))`;
  - `email_verified_at` nulo;
  - uma `Address` `is_default` com `$address` (chaves do snapshot: `name`, `line1`, `line2`, `postalCode`, `city`, `country`).
- Produces: `CustomerDirectory::saveDetails(User $user, array $customer, array $address): void`. Sobrescreve nome, telefone, NIF e a morada por omissão (cria-a se não houver).
- Produces: as tabelas e os modelos acima, e a guarda `customer`.

- [ ] **Step 1: Write the failing tests** em `CustomerDirectoryTest`:
  - `test_find_by_email_ignores_case`: um utilizador `Ana@Exemplo.pt` é encontrado com `findByEmail(' ana@exemplo.PT ')`.
  - `test_find_by_email_refuses_an_ambiguous_match`: dois utilizadores `ana@x.pt` e `ANA@x.pt`, criados com `forceFill`, dão `null`.
  - `test_for_purchase_creates_a_customer_with_the_purchase_details`: confirma `email === 'ana@exemplo.pt'`, `email_verified_at === null`, `isStaff() === false`, uma morada `is_default` com `line1` e `postal_code`.
  - `test_for_purchase_never_changes_an_existing_customer`: o `name`, o `phone` e a morada ficam como estavam.
  - `test_for_purchase_never_links_to_staff`: um admin com o email dá `null`.
  - `test_the_customer_guard_remembers_for_sixty_days`: `Auth::guard('customer')->getRememberDuration() === 86400`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Customers/CustomerDirectoryTest.php`. Expected: FAIL (a classe não existe).
- [ ] **Step 3: Implement** as migrações, os modelos, a configuração e o `CustomerDirectory` acima. Corre `php artisan migrate`.
- [ ] **Step 4: Run** o mesmo teste e `tests/Feature/Admin` (os testes de clientes do backoffice com emails). Expected: PASS. Se um teste do backoffice contava com o email em maiúsculas, atualiza a asserção para o email normalizado.
- [ ] **Step 5: Commit** — "Contas: esquema, guarda customer e emails em minúsculas".

### Task 2: O login da equipa deixa de aceitar clientes

**Files:**
- Modify: `app/Providers/FortifyServiceProvider.php:56` — `if ($user === null || $user->isDisabled() || ! $user->isStaff()) return null;`
- Modify: `app/Models/User.php` — `sendPasswordResetNotification($token)` só envia quando `isStaff()`; para os outros não faz nada, e a resposta do Fortify não muda
- Modify: `app/Actions/Fortify/ResetUserPassword.php` — recusa um `$user` que não seja da equipa (`ValidationException` em `email`)
- Modify: `app/Http/Middleware/EnsureAccountUsable.php` — um utilizador da guarda `web` que não seja da equipa (entrou por passkey, ou trazia uma sessão de antes) sai como o desativado
- Test: `tests/Feature/Auth/StaffOnlyLoginTest.php`

**Interfaces:**
- Consumes: `User::isStaff()`, `User::isDisabled()`.
- Produces: nada que as outras tarefas usem. Fecha a porta que a Tarefa 3 abre, ao fazer de cada comprador um utilizador.

- [ ] **Step 1: Write the failing tests**:
  - `test_a_customer_with_a_password_cannot_log_in`: um cliente com uma password conhecida faz POST a `/login` e `assertGuest('web')`.
  - `test_a_customer_gets_no_reset_email`: `Notification::fake()`; POST a `/forgot-password` com o email do cliente; `Notification::assertNothingSent()`; a resposta é a mesma de um email inexistente (o mesmo status e a mesma mensagem de sessão).
  - `test_staff_still_logs_in_and_resets`: um admin entra; `ResetPassword` enviado a um admin.
  - `test_a_customer_web_session_is_ended`: `actingAs($customer, 'web')` e um GET a uma rota do backoffice redirecionam para `login` com `assertGuest('web')`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Auth/StaffOnlyLoginTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** as quatro alterações acima.
- [ ] **Step 4: Run** `tests/Feature/Auth` inteiro. Expected: PASS.
- [ ] **Step 5: Commit** — "Login da equipa: so contas da equipa entram e recuperam password".

### Task 3: Cada compra paga liga-se a um cliente

**Files:**
- Modify: `app/Http/Requests/Shop/StartCheckoutRequest.php` — regra `'marketing_opt_out' => ['boolean']`; accessor `marketingOptOut(): bool`
- Modify: `app/Shop/CheckoutService.php:42` — `start(Cart $cart, array $customer, array $address, string $locale, int $expectedTotalCents, ?User $account = null, bool $marketingOptOut = false): string`; grava `user_id` (`$account?->id`) e `marketing_opt_out` no `Checkout::create`
- Modify: `app/Http/Controllers/Store/CheckoutController.php:111` — passa `Auth::guard('customer')->user()` e `$request->marketingOptOut()`
- Modify: `app/Shop/CheckoutCompletion.php:99-156` (`create`) — antes do `createFromStore`, resolve o cliente; põe `user_id` nos `$data`; aplica a recusa e os dados guardados (regras abaixo)
- Modify: `app/Services/OrderService.php:318` (`createFromStore`) — aceita `$data['user_id'] ?? null`
- Test: `tests/Feature/Customers/PurchaseLinksCustomerTest.php` (usa `FakesStripe` e copia os helpers `cartWith`/`pay`/`stripeSession`/`event`/`send` do `CheckoutWebhookTest`)

**Interfaces:**
- Consumes: `CustomerDirectory::forPurchase()` e `saveDetails()` (Tarefa 1).
- Produces: `orders.user_id` preenchido nas compras pagas; `checkouts.marketing_opt_out` gravado (`true`/`false`, nunca `null` a partir daqui); `users.marketing_opt_out_at`.

Regras dentro de `create()`, ainda com o snapshot cheio:
- **Checkout com `user_id` (havia sessão):**
  - o cliente é esse;
  - `saveDetails($user, customer, shipping_address)`;
  - `marketing_opt_out_at` passa a `now()` se a caixa estava marcada, e a `null` se não estava.
- **Checkout sem `user_id`:**
  - o cliente é `forPurchase(email, customer, shipping_address)`;
  - a caixa marcada só põe `marketing_opt_out_at` (se for nulo), e nunca o limpa.
- **Equipa:** se `forPurchase` der `null`, a encomenda fica sem `user_id`.

- [ ] **Step 1: Write the failing tests**:
  - `test_a_guest_purchase_creates_a_customer_and_links_the_order`: `onlyOrder()->user_id === User::where('email','ana@exemplo.pt')->sole()->id`.
  - `test_a_guest_purchase_links_to_an_existing_customer_regardless_of_case`.
  - `test_a_guest_purchase_never_changes_an_existing_customer`: o nome e a morada ficam.
  - `test_a_guest_can_opt_out_but_never_opt_back_in`: a primeira compra com a caixa marcada põe `marketing_opt_out_at`; uma segunda sem a caixa não o limpa.
  - `test_a_signed_in_purchase_saves_the_details_and_the_choice`: com `actingAs($customer, 'customer')`, a morada passa a ser a do checkout e a caixa desmarcada limpa `marketing_opt_out_at`.
  - `test_the_checkout_records_the_choice`: `Checkout::sole()->marketing_opt_out === false` sem a caixa e `true` com ela.
  - `test_a_purchase_with_a_staff_email_links_nobody`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Customers/PurchaseLinksCustomerTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** as alterações acima.
- [ ] **Step 4: Run** o teste novo e `tests/Feature/Shop`. Expected: PASS.
- [ ] **Step 5: Commit** — "Loja: cada compra paga liga-se a um cliente".

### Task 4: Entrar com um link no email

**Files:**
- Create: `app/Customers/CustomerLoginLinks.php`
- Create: `app/Mail/CustomerLoginLinkMail.php` (queued, `afterCommit`), `resources/views/mail/customers/login-link.blade.php`
- Create: `app/Http/Controllers/Store/CustomerLoginController.php` — `show` (formulário), `send`, `confirm` (GET com o token: mostra o botão), `enter` (POST com o token), `logout`
- Create: `resources/js/pages/store/account/login.tsx`, `resources/js/pages/store/account/confirm.tsx`
- Modify: `routes/store.php` — no `$storefront`, num grupo `shop.open`, **antes** do catch-all `{slug}`:
  - `entrar` GET/POST (`store.login`, `store.login.send`, este com `throttle:customer-login`);
  - `entrar/{token}` GET/POST (`store.login.confirm`, `store.login.enter`), com `->where('token','[A-Za-z0-9]{64}')`;
  - `conta/sair` POST (`store.logout`).
- Modify: `app/Providers/AppServiceProvider.php` — `RateLimiter::for('customer-login', …)`: 3 por 10 minutos por email normalizado e 10 por hora por IP
- Modify: `app/Http/Middleware/EnsureShopOpen.php:23` e `app/Http/Middleware/ShareStorefront.php:77-80` — `Auth::guard('web')->user()`
- Modify: `app/Http/Middleware/ShareStorefront.php` — acrescenta `customer: {name: string} | null` ao `storefront`; `resources/js/types/store.ts` (`Storefront.customer`)
- Modify: `app/Alerts/SystemAlerts.php:262-271` (`requestLabel`) — tira a query string e troca qualquer segmento com 32 ou mais caracteres alfanuméricos por `…`
- Modify: `app/Listeners/SendWelcomePromoCode.php` — salta `isStaff()` e quem já tem uma encomenda paga com aquele email
- Modify: `lang/{pt,en,es,fr}/store.php` — grupo `account` (entrar, link enviado, confirmar, sair)
- Test: `tests/Feature/Customers/CustomerLoginTest.php`, `tests/Feature/Alerts/SystemAlertsTest.php` (caso novo)

**Interfaces:**
- Consumes: `CustomerDirectory::findByEmail()` (Tarefa 1), `CustomerLoginToken`, a guarda `customer`.
- Produces: `CustomerLoginLinks::send(string $email): void`. Normaliza o email. Não faz nada se o dono do email for da equipa ou estiver desativado. Cria o token (`Str::random(64)`, guarda `hash('sha256')`, expira em 20 min) e põe na fila `CustomerLoginLinkMail` com o URL `store.login.confirm` no idioma atual.
- Produces: `CustomerLoginLinks::enter(string $token): ?User`. Marca `used_at` de forma atómica (`update … where used_at is null and expires_at > now`; 0 linhas dá `null`) e encontra ou cria o cliente. A equipa e as contas desativadas dão `null`. Se `email_verified_at` for nulo:
  - preenche-o;
  - liga as encomendas com esse email (`lower`) e `user_id` nulo;
  - dispara `event(new Registered($user))`.
- Produces: rotas `store.login` e `store.logout`; prop `storefront.customer`.

O controlador:
- **`enter`:**
  - em sucesso faz `Auth::guard('customer')->login($user, remember: true)` e `$request->session()->regenerate()`, e redireciona para `/conta`;
  - em falha volta a `/entrar` com a mensagem "O link já foi usado ou expirou".
- **`send`:** responde sempre o mesmo flash.
- **`logout`:** faz `Auth::guard('customer')->logout()` e `$request->session()->regenerateToken()`, **sem** `invalidate()`.
- **Respostas da entrada:** todas com `X-Robots-Tag: noindex, nofollow` e `Referrer-Policy: no-referrer`, como o `PurchaseController:54-58`.

- [ ] **Step 1: Write the failing tests**:
  - `test_the_form_answers_the_same_for_any_email`: um email inexistente, um cliente e um admin dão o mesmo redirect e o mesmo flash; só o cliente e o inexistente põem um `CustomerLoginLinkMail` na fila (`Mail::fake`).
  - `test_opening_the_link_does_not_sign_in`: um GET a `/entrar/{token}` dá 200, `assertGuest('customer')` e o token continua com `used_at` nulo.
  - `test_posting_the_link_signs_in_once`: o POST entra (`assertAuthenticated('customer')`, com o cookie remember presente); um segundo POST com o mesmo token falha.
  - `test_an_expired_link_fails`: `travel(21)->minutes()`.
  - `test_the_first_entry_creates_the_customer_and_links_old_orders_by_email`: duas encomendas antigas com `Ana@Exemplo.pt` e `user_id` nulo, e uma ligada a outro cliente. Depois de entrar com `ana@exemplo.pt`, as duas primeiras ficam com o `user_id` dela e a terceira fica como estava.
  - `test_the_first_entry_sends_the_welcome_code_only_without_paid_orders`: `Queue::fake([IssueWelcomePromoCode::class])`; sem encomenda paga, empurra; com encomenda paga, não empurra; na segunda entrada nunca empurra.
  - `test_staff_never_enters_by_link`.
  - `test_customer_logout_keeps_the_staff_session`: `actingAs($admin, 'web')` e `actingAs($customer, 'customer')`; depois do POST `/conta/sair`, `assertAuthenticatedAs($admin, 'web')` e `assertGuest('customer')`.
  - `test_the_login_is_behind_the_shop_gate`: em `off` dá 404 e em `staff` dá 404 para um anónimo.
  - Em `SystemAlertsTest`: `test_the_request_label_hides_tokens`: um pedido `GET /entrar/{64 chars}?x=1` mostra `GET /entrar/…`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Customers/CustomerLoginTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** as classes, as rotas, as páginas (formulário com email e botão; página de confirmação com o email mascarado e o botão "Entrar") e as traduções.
- [ ] **Step 4: Run** o teste novo, `tests/Feature/Alerts`, `tests/Feature/Shop` e `tests/Feature/PromoCodes`. Expected: PASS.
- [ ] **Step 5: Commit** — "Contas: entrar com um link no email".

### Task 5: Guardar os dados a partir da página da compra

**Files:**
- Modify: `app/Http/Controllers/Store/PurchaseController.php` — prop `purchase.canSaveAccount: bool`: verdadeira quando o estado é `paid` ou `awaiting_payment`, não há sessão `customer` e o email da encomenda não é de alguém da equipa. Acrescenta a ação `saveAccount(Request $request, string $token): RedirectResponse`, que chama `CustomerLoginLinks::send($order->email)` e devolve um flash.
- Modify: `routes/store.php` — no `$conversations` (fora da porta, como `/compra/{token}`): `Route::post('compra/{token}/conta', …)->middleware('throttle:customer-login')->name('store.purchase.account')`
- Modify: `resources/js/pages/store/purchase.tsx` — bloco "Guarda os teus dados para a próxima" depois do resumo, quando `withOrder && purchase.canSaveAccount`; o email não aparece no ecrã
- Modify: `resources/js/types/shop.ts` (`PurchaseData.canSaveAccount`), `lang/*/store.php` (`purchase.save_account*`)
- Test: `tests/Feature/Shop/PurchasePageTest.php`

**Interfaces:**
- Consumes: `CustomerLoginLinks::send()` (Tarefa 4).
- Produces: nada.

- [ ] **Step 1: Write the failing tests**:
  - `test_a_paid_purchase_offers_to_save_the_account`: `canSaveAccount === true`.
  - `test_saving_sends_the_link_to_the_order_email`: `Mail::assertQueued(CustomerLoginLinkMail::class, fn ($m) => $m->hasTo($order->email))`.
  - `test_no_offer_when_signed_in_or_confirming`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Shop/PurchasePageTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** o mesmo teste. Expected: PASS.
- [ ] **Step 5: Commit** — "Compra: guardar os dados com um link".

### Task 6: A conta — encomendas, dados, ofertas e comprar outra vez

**Files:**
- Create: `app/Http/Controllers/Store/CustomerAccountController.php`:
  - `show`: lista das encomendas (número, data, total, estado, seguimento) mais os dados e as ofertas;
  - `order(string $number)`: só se `user_id` for o da conta; caso contrário, 404;
  - `updateDetails`;
  - `updateOffers`;
  - `reorder(string $number)`.
- Create: `app/Http/Requests/Shop/UpdateCustomerDetailsRequest.php` — as regras do `StartCheckoutRequest` para nome, telefone, NIF e morada; sem email
- Create: `app/Shop/Reorder.php`
- Create: `resources/js/pages/store/account/index.tsx`, `resources/js/pages/store/account/order.tsx`, `resources/js/components/store/account-button.tsx`
- Modify: `routes/store.php` — no `$storefront`, grupo `['shop.open', 'auth:customer']` antes do `{slug}`:
  - `conta` (`store.account`);
  - `conta/encomendas/{number}` (`store.account.order`);
  - `conta/dados` PATCH;
  - `conta/ofertas` PATCH;
  - `conta/encomendas/{number}/comprar-outra-vez` POST.
- Modify: `bootstrap/app.php` — `redirectGuestsTo`: a guarda `customer` vai para `/entrar` no idioma atual
- Modify: `resources/js/components/store/store-header.tsx:124` — `<AccountButton>` ao lado do `CartButton`, com o mesmo `storefront.shop`. Leva a `/conta` com sessão e a `/entrar` sem ela.
- Modify: `lang/*/store.php` (`account.*`)
- Test: `tests/Feature/Customers/CustomerAccountTest.php`, `tests/Feature/Customers/ReorderTest.php`

**Interfaces:**
- Consumes: a guarda `customer`, `CustomerDirectory::saveDetails()` (Tarefa 1), `CartService::add/fromRequest`, `Buyable`.
- Produces: `Reorder::into(?Cart $cart, Order $order): array{cart: ?Cart, skipped: list<string>}`.
  - Para cada `OrderItem` com `variant_id`, chama `CartService::add($cart, $variant, $item->qty, $item->personalization ?? [])`.
  - Uma `ValidationException` ou uma variante em falta junta `product_name` ao `skipped`.
  - A extração dos helpers privados do `CartController` (`cookie()`, `saved()`) para uma classe partilhada `app/Shop/CartCookie.php` fica a quem constrói; o controlador da conta precisa do mesmo cookie.

`updateOffers` recebe `{enabled: bool}` e põe `marketing_opt_out_at` a `null` ou `now()`. As páginas da conta respondem com `noindex` e `no-referrer`.

Uma conta desativada depois de entrar sai na página seguinte. Faz-se com um
middleware `customer.usable`, à semelhança do `EnsureAccountUsable` mas para a
guarda `customer`, com `logout` sem `invalidate()`. Teste:
`test_a_disabled_customer_is_signed_out`.

- [ ] **Step 1: Write the failing tests**:
  - `test_the_account_needs_a_customer_session`: um anónimo vai para `/entrar` e um admin só com a guarda `web` também.
  - `test_the_account_lists_only_linked_orders`.
  - `test_another_customers_order_is_404`.
  - `test_details_update_the_default_address_and_never_the_email`.
  - `test_the_offers_switch_sets_and_clears_the_opt_out`.
  - Em `ReorderTest`:
    - `test_reorder_adds_the_same_variants_and_personalization`;
    - `test_reorder_skips_archived_sold_out_and_unpriced_variants_by_name`;
    - `test_reorder_uses_todays_price`: o carrinho cota com o preço atual da variante, não com o da encomenda.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Customers`. Expected: FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** `tests/Feature/Customers`, `tests/Feature/Shop`, e `npm run types` e `npm run lint` no PowerShell. Expected: PASS.
- [ ] **Step 5: Commit** — "Contas: encomendas, dados, ofertas e comprar outra vez".

### Task 7: O checkout com sessão e a caixa de recusar

**Files:**
- Modify: `app/Http/Controllers/Store/CheckoutController.php:34` (`show`) e `:63-89` (`lastCustomer`):
  - com sessão `customer`, o prefill vem da conta (nome, email, telefone, NIF e a morada por omissão);
  - acrescenta à prop `account: {email: string} | null` e a `marketing_opt_out: bool` (a recusa da conta, ou `false`).
- Modify: `app/Http/Controllers/Store/CheckoutController.php:111` (`pay`) — com sessão, o email usado é o da conta, seja o que for que vier no pedido
- Modify: `resources/js/pages/store/checkout.tsx`:
  - com `account`, o campo do email fica só de leitura e aparece a ligação "Não és tu? Sair";
  - acrescenta a caixa `marketing_opt_out` antes da caixa dos termos.
- Modify: `resources/js/types/shop.ts` (`CheckoutFormData.marketing_opt_out: boolean`), `lang/*/store.php` (`checkout.marketing_opt_out`: "Não quero receber ofertas da 12studio por email")
- Test: `tests/Feature/Shop/CheckoutTest.php`

**Interfaces:**
- Consumes: `CheckoutService::start(..., ?User $account, bool $marketingOptOut)` (Tarefa 3).
- Produces: nada.

- [ ] **Step 1: Write the failing tests**:
  - `test_a_signed_in_checkout_is_prefilled_from_the_account`.
  - `test_a_signed_in_checkout_pays_with_the_account_email`: um POST com outro email dá `Checkout::sole()->customer['email'] === $customer->email`.
  - `test_the_opt_out_box_starts_ticked_for_an_opted_out_account`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Shop/CheckoutTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** `tests/Feature/Shop`, e `npm run types` e `npm run lint`. Expected: PASS.
- [ ] **Step 5: Commit** — "Checkout: dados da conta e caixa de recusar ofertas".

### Task 8: Ofertas para voltar a comprar

**Files:**
- Modify: `app/Models/PromoCode.php` — `public const CHANNEL_REPEAT = 'repeat';`
- Modify: `app/Services/StoreSettings.php`:
  - `KEY_OFFERS = 'store.offers'`;
  - `offers(): array{enabled: bool, percent: int, days_after_shipping: int, valid_days: int, reminder_days: int}`, com as omissões `false, 10, 21, 45, 7`;
  - entra no `toForm()` e no `fromForm()`, no padrão do `announcement`.
- Modify: `app/Http/Requests/Setting/UpdateStoreSettingsRequest.php` — `offers.enabled` boolean; `offers.percent` 1–50; `offers.days_after_shipping` 1–120; `offers.valid_days` 7–365; `offers.reminder_days` 1–30 e menor que `valid_days`
- Modify: `resources/js/pages/admin/definicoes/montra.tsx` — `<Panel title="Ofertas para voltar a comprar">` com os cinco campos e um texto curto sobre a lei (só compras com a caixa)
- Create: `app/Customers/RepeatOffers.php`, `app/Console/Commands/SendRepeatOffersCommand.php` (`offers:send`)
- Create: `app/Mail/RepeatOfferMail.php`, `app/Mail/RepeatOfferReminderMail.php`, `resources/views/mail/promo/repeat.blade.php`, `resources/views/mail/promo/repeat-reminder.blade.php`
- Create: `app/Http/Controllers/Store/UnsubscribeController.php` — `show` (GET: página com o botão) e `store` (POST: recusa)
- Modify: `routes/store.php` — no `$conversations` (fora da porta): `ofertas/sair/{user}` GET/POST, com o middleware `signed` (`store.offers.unsubscribe`, `store.offers.unsubscribe.store`)
- Modify: `bootstrap/app.php` — exceção ao CSRF para o POST de `ofertas/sair/*` (o `List-Unsubscribe-Post` do RFC 8058 chega sem token)
- Modify: `routes/console.php` — `offers:send` de hora a hora com `withoutOverlapping` e `$alertOnFailure`
- Test: `tests/Feature/Customers/RepeatOffersTest.php`, `tests/Feature/Customers/UnsubscribeTest.php`

**Interfaces:**
- Consumes: `StoreSettings::offers()`, `ShopGate::allows(null)`, `RepeatOffer`, `PromoCode`, `users.marketing_opt_out_at`, `checkouts.marketing_opt_out`.
- Produces: `RepeatOffers::due(): Collection<int, Order>`, `RepeatOffers::send(Order $order): void`, `RepeatOffers::remindersDue(): Collection<int, RepeatOffer>`, `RepeatOffers::remind(RepeatOffer $offer): void`.

Uma encomenda é elegível para `due()` quando **tudo** isto é verdade:
- tem um `checkouts` com `order_id` = encomenda e `marketing_opt_out = false`;
- `payment_status = 'paid'`;
- `status` não é `cancelled` nem `refunded`;
- `shipped_at` está entre `now() - (days + 30)` e `now() - days`;
- não há uma `repeat_offers` para a encomenda;
- `user_id` não é nulo e o utilizador não é da equipa nem tem `marketing_opt_out_at`;
- não há outra encomenda paga desse email (`lower`) criada depois desta;
- não há um `promo_codes` do canal `repeat` para esse email com `expires_at > now()` e sem resgate (`promo_code_redemptions`).

O comando:
- **Sai logo** se `! offers()['enabled']` ou se `! ShopGate::allows(null)`.
- **Cada `send`** corre numa transação e cria:
  - um `PromoCode` com:
    - código `'VOLTA-'.Str::upper(Str::random(8))`;
    - `TYPE_PERCENT` e `value = percent`;
    - `min_subtotal_cents = 0` e `max_uses = 1`;
    - `single_use_per_email = true`;
    - `channel = CHANNEL_REPEAT` e `email` normalizado;
    - `expires_at = now() + valid_days`;
  - um `RepeatOffer` com `sent_at`;
  - e põe `RepeatOfferMail` na fila com `afterCommit`.
- **`remindersDue()`:** `reminded_at` nulo, código por resgatar, `expires_at` entre agora e `now() + reminder_days`, utilizador sem recusa.
- **Os dois emails:**
  - levam `List-Unsubscribe: <URL assinado>` e `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, pelo `headers()` do Mailable;
  - levam o link no rodapé;
  - não levam dados da encomenda além do número.

- [ ] **Step 1: Write the failing tests** em `RepeatOffersTest` (usa `travelTo`; encomendas pagas criadas pelo webhook ou por factory mais um `Checkout` com `marketing_opt_out`):
  - `test_nothing_is_sent_while_disabled_or_the_shop_is_not_on`.
  - `test_an_order_shipped_21_days_ago_gets_a_personal_ten_percent_code`: confirma `channel === 'repeat'`, `type === 'percent'`, `value === 10`, `email` igual ao da encomenda, `expires_at` igual a hoje mais 45 dias e `Mail::assertQueued(RepeatOfferMail::class)`.
  - `test_each_order_gets_one_offer_even_if_the_command_runs_twice`.
  - `test_no_offer_for`, com um caso por motivo:
    - um checkout com `marketing_opt_out = true`;
    - um checkout com `marketing_opt_out = null` (de antes);
    - uma encomenda do backoffice sem checkout;
    - um cliente com `marketing_opt_out_at`;
    - uma compra paga mais recente;
    - um código `repeat` ativo por usar;
    - uma encomenda cancelada;
    - um envio há 60 dias, fora da janela.
  - `test_the_reminder_goes_seven_days_before_expiry_and_only_if_unused`.
  - `test_the_offer_settings_are_saved_and_change_the_offer`: um PATCH a `admin.montra.update` com `offers.percent = 15` faz o código seguinte sair com `value === 15`.
  - Em `UnsubscribeTest`:
    - `test_the_one_click_post_opts_out_without_csrf_or_session`;
    - `test_the_get_shows_a_button_and_changes_nothing`;
    - `test_unsigned_or_tampered_links_are_403`;
    - `test_unsubscribe_works_with_the_shop_off`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Customers/RepeatOffersTest.php tests/Feature/Customers/UnsubscribeTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement**.
- [ ] **Step 4: Run** os testes novos, `tests/Feature/PromoCodes`, `tests/Feature/Settings`, e `npm run types` e `npm run lint`. Expected: PASS.
- [ ] **Step 5: Commit** — "Ofertas: codigo pessoal para a proxima compra".

### Task 9: Portões finais e textos

**Files:**
- Modify: `docs/paginas-legais-rascunho.md` — na privacidade, as contas (o que fica guardado, a entrada por link) e as ofertas por email (a base legal, como recusar na compra, na conta e em cada email); nos termos, os códigos para a próxima compra
- Modify: `C:\Users\gonca\.claude\projects\E--Projects-12studio\memory\` — uma memória `contas-de-cliente.md` com o estado e as decisões, e a linha no `MEMORY.md`

- [ ] **Step 1: Run** no PowerShell, um de cada vez: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=2G`, `npm run lint`, `npm run format:check` (ou o script de formatação do `package.json`), `npm run types`, `php artisan test --compact`. Expected: todos com exit 0; a suite com 0 falhas.
- [ ] **Step 2: Atualizar** o rascunho legal e a memória.
- [ ] **Step 3: Commit** — "Contas: textos legais e acertos finais" (só o que mudou; o rascunho legal continua fora do git até o dono o preencher).
