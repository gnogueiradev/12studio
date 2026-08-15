# 12studio — Backoffice Central e Loja Online de Impressão 3D (Laravel + Inertia React)

## Contexto

O dono é um maker em Portugal que imprime peças 3D e quer vendê-las numa loja moderna construída à medida neste repositório (vazio — greenfield). Após três rondas de revisão, o âmbito assentou: **o sistema de gestão central da 12studio** — custos e margens reais por peça, produção por encomenda com capacidade limitada, personalizações com acréscimo, estados de produção por item, e registo de vendas de outros canais (Vinted, Instagram, manual) no mesmo backoffice.

**Stack decidida pelo dono: Laravel + React via Inertia, deploy com Jenkins, seguindo as convenções do projeto de referência `E:\Projects\qrcode` (FluxQR)** — analisado em detalhe; este plano replica os seus padrões.

**Decisões validadas:**
- Peças **físicas** impressas em 3D, enviadas por correio (CTT); mercado **Portugal**, EUR, PT-PT (sem i18n no v1 — strings PT diretas; o padrão `lib/i18n.ts` do qrcode pode ser adotado depois para EN)
- Pagamentos: **Stripe Checkout hosted** (cartões síncronos + Multibanco assíncrono com reservas de stock)
- **Sem atividade aberta nas Finanças** — Stripe test mode até à Fase 5; faturação certificada preparada no modelo, integração automática (Moloni API) depois do lançamento
- **Cortado do v1**: avaliações, cupões, visualizador 3D, dashboard avançado, múltiplas moradas, EN, `productionJob` (impressoras), custo real vs estimado
- **Sem multi-tenancy** — o qrcode tem Workspaces; a 12studio é uma loja única, esse padrão não é replicado

---

## Visão de negócio

**Custos:** infraestrutura própria já existente (Docker + Jenkins + Redis na rede `Projects`, Nginx Proxy Manager; BD é SQLite local — sem servidor de BD) → custo marginal ~0€. Ao lançar: domínio + comissões Stripe. Sem mensalidades de plataforma.

**Checklist legal do lançamento (tarefas do dono, Fase 5):**
1. Abrir atividade nas Finanças (ex. CAE 47910) — necessário para o KYC do Stripe live
2. Software de faturação certificado (Moloni tem plano grátis) — emissão manual ao início, API depois
3. Registo no Livro de Reclamações Eletrónico
4. Termos com direito de livre resolução de 14 dias — personalizados **isentos** (nota visível no produto), catálogo não

---

## Stack técnica (espelho do qrcode)

| Área | Escolha | Nota |
|---|---|---|
| Backend | **Laravel 12** (PHP 8.4), a partir do `laravel/react-starter-kit` | Igual ao qrcode |
| Frontend | **Inertia v2 + React 19 + TypeScript**, SSR ativado | SSR importa para SEO da montra (o qrcode já o corre via supervisor) |
| UI | **Tailwind CSS 4** (CSS-first, sem config) + **shadcn/ui new-york** + lucide | `components.json` igual; backoffice quase de graça |
| Build | Vite 7 + `laravel-vite-plugin` + React Compiler + **Wayfinder** (`--with-form`) | Gerados `routes/`+`actions/` committed e ESLint-ignored; **não usar Ziggy** |
| Auth | **Fortify headless** + coluna booleana `users.is_admin` + middleware `EnsureAdmin` | Sem spatie, sem Policies — verificação manual nos services/controllers, como no qrcode. Clientes = users normais (Fase 5) |
| BD | **SQLite em todo o lado** (dev, testes E produção — decisão do dono) | Paridade total de ambientes; adequado ao volume de uma loja pequena (escritor único + WAL). Produção: `journal_mode=WAL`, `busy_timeout=5000`, `synchronous=NORMAL`, `foreign_key_constraints=true` em `config/database.php`; ficheiro **num volume persistente** (nunca dentro da imagem Docker); backup diário via scheduler (`VACUUM INTO` + retenção). Convenções de migração do qrcode: classes anónimas, `foreignId()->constrained()`, índices nomeados, `down()` implementado. Sessions/cache/filas ficam no **Redis** para poupar escritas ao SQLite |
| Filas | **Redis** + supervisor (`queue-worker.conf`), `after_commit => true` | Fila `default` chega no v1 (emails); nomes em `config/queue.php` validados por teste-guarda |
| Scheduler | `routes/console.php` + `schedule:work` via supervisor, tudo `->withoutOverlapping()` | Sweep de reservas expiradas vive aqui (substitui o "Vercel cron" da versão anterior do plano) |
| Pagamentos | `stripe/stripe-php` direto num `StripeCheckoutService` | **Sem Cashier** (é orientado a subscrições; o qrcode também não o usa). Webhook com verificação de assinatura sobre raw body |
| Email | Mailables queued (`afterCommit()` no construtor, escalares promovidos), views Blade com layout partilhado | `MAIL_MAILER=log` local, `array` em testes — padrão qrcode |
| Uploads | Disco `public` + `storage:link` no deploy (`$file->store('products','public')`, accessor `image_url`) | Sem limites de serverless — imagens e futuros GLB no filesystem |
| Validação | **Form Requests** por domínio (`App\Http\Requests\Product\StoreProductRequest`), `authorize() => true` (auth via middleware), `after()` p/ regras cruzadas | Padrão qrcode |
| Lógica | **Controllers finos → Services** (`app/Services/`, construtor com property promotion, autowired, sem interfaces — exceto `Invoicing`) | O trio `QrCodeController` + `QrCodeService` + `StoreQrCodeRequest` + `pages/qr-codes/*` é o template de CRUD a clonar |
| Dinheiro | **Cêntimos inteiros** (colunas int), helper `app/Support/Money.php`; montra com IVA incluído | Nunca floats; `vat_rate` snapshot por item |
| Carrinho | **Cookie** `cart` (JSON, cifrado automaticamente pelo `EncryptCookies` do Laravel) `{variantId, qty, personalization?}` | Personalização inline só texto curto; guarda de ~4 KB; revalidado na BD no checkout; nunca confiar em preços do cookie |
| Config | `config/shop.php` (+ subsistemas) — **`env()` só dentro de `config/`** | Regra imposta pelo teste-guarda `ConfigCacheSafetyTest` (replicar do qrcode) — o deploy corre `artisan optimize` |
| Enums | `public const STATUSES = [...]` nos models (não PHP enums) | Convenção do qrcode |
| Estilo | Pint preset laravel; ESLint flat + prettier (tabWidth 4, singleQuote, plugin tailwind); `tsc --noEmit` | Scripts composer `test`/`lint`/`ci:check` copiados |
| Deploy | **Jenkinsfile 6 stages** + Dockerfile multi-stage `webdevops/php-nginx:8.4` + docker-compose (só o `app`; o Redis é a instância partilhada da rede `Projects`) + supervisor confs | Copiar/adaptar do qrcode: tag `:previous` para rollback, health-gate `/up`, assets construídos antes do swap |

---

## Modelo de dados

Cêntimos inteiros; IDs auto-increment (`$table->id()`, convenção qrcode); `timestamps()` em tudo; `$fillable` (nunca `$guarded`); relações tipadas; predicados de domínio nos models (`isInStock()`, `isPaid()`).

### Materiais, cores e catálogo
- **materials** — name ("PLA", "PETG", "TPU"), price_per_kg_cents, active, sort_order
- **colors** — material_id FK, name, hex_color, image?, price_per_kg_cents? (override), is_active. Cor pertence a um material → impossível vender combinações inexistentes; montra mostra swatches reais agrupados por material
- **categories** — flat; slug ASCII
- **tags** + **product_tag** — segundo eixo de organização ao lado das categorias (um produto tem UMA categoria e várias tags). Excepção consciente à eliminação lógica: uma tag sem produtos apaga-se mesmo — nenhuma encomenda a referencia
- **products** — slug (**editável**; vazio gera-se do nome), name, description (**HTML** sanitizado com HTMLPurifier, perfil `product` — o editor TipTap do backoffice escreve-o), category_id, status (`draft`/`active`/`archived`), featured, vat_rate (default 23), **fulfillment_mode** (`in_stock` | `made_to_order` | `custom`), **production_time_days?**, **allow_backorder**, **max_open_production_qty?** (capacidade — limite *soft*, ver riscos), **personalization_fields json?** (`[{key,label,type:'text'|'number',required,maxLength}]`), **personalization_surcharge_cents?** (fixo; regras avançadas ficam para depois), seo_title?, seo_description?
  - Montra: `in_stock` → "Envio em 1–2 dias úteis"; `made_to_order` → "Produzido por encomenda — envio em X–Y dias úteis"
- **variants** — product_id, sku único, color_id?, size_label?, price_cents (IVA incl.), compare_at_cents?, **wholesale_price_cents?** (revenda — só backoffice, aplicável numa encomenda manual), **stock**, **reserved_stock**, low_stock_threshold, is_default, active
  - O formulário fala **preço normal + preço promocional**; a BD guarda `price_cents` = preço EFETIVO e `compare_at_cents` = preço riscado, invertidos em promoção. A tradução vive só no `VariantService::normalizePrices()` (escrita) e em `Variant::normalPriceCents()`/`salePriceCents()` (leitura) — tudo a jusante (carrinho, order_items, Stripe) continua a ler `price_cents` como o valor cobrado
  - **Custos:** filament_weight_grams?, **printing_time_minutes?**, **printer_profile_id?** (null = a predefinida), **extra_cost_cents?** (ímanes, feltro, caixa)
- **printer_profiles** — name único, **hourly_rate_cents** (energia + desgaste + manutenção + depreciação num número só), notes?, is_default, active, sort_order. Unicidade da predefinida por **índice único parcial** (`unique WHERE is_default = 1`), escrita só via `PrinterProfileService::setDefault()` transacional
  - **Envio/dimensões:** product_weight_grams?, package_weight_grams?, length_mm?, width_mm?, height_mm? (v1 só armazena/mostra; portes por peso e transportadoras ficam para depois)
  - `availableStock = stock - reserved_stock` (accessor, nunca persistido)
- **product_images** — product_id, variant_id?, color_id?, path, alt, sort_order, **is_primary**
  - Unicidade da principal: **índice único parcial** (`unique WHERE is_primary = 1` — o SQLite suporta; o qrcode já tem migrações com este padrão) + `ImageService::setPrimary()` transacional (desmarca todas → marca uma) como única via de escrita

### Cálculo de custo, revenda e preço final (calculado, não persistido — `app/Services/PricingCalculator.php`)

**O preço não sai da gramagem.** Duas peças de 32 g podem demorar 30 minutos ou 4 horas: o material custa o mesmo e a produção não. O **tempo de impressão é input obrigatório** do cálculo.

```
material   = weightGrams × pricePerKgCents × 10                  ← micros; o ÷1000 do kg
                                                                   dissolve-se na constante
máquina    = printTimeMinutes ÷ 60 × hourlyRateCents             ← do printer_profile
manuseam.  = custo fixo por peça (0,15 €)                        ← sem tabela por peso
risco      = (material + máquina) × failure_reserve_bp           ← extras NÃO pagam risco
custo      = material + máquina + manuseamento + risco + extras

revenda    = ceil(custo × 1,70, 0,50 €)                          ← chão de 1,50 €
cliente    = arredondamento comercial(revenda × 1,75)            ← 0,50/1/5 € por faixa
             com chão de revenda × 1,60 (rede de segurança)

lucro produtor    = revenda − custo
margem produtor   = lucro ÷ revenda × 100    ← margem sobre venda (definição oficial)
lucro revendedor  = cliente − revenda
markup revendedor = lucro ÷ revenda × 100    (o admin mostra ambos, rotulados)
```

**Aritmética em micro-euros** (`app/Support/Micros.php`): inteiros de 1/1 000 000 €. A regra do projeto é "cêntimos, nunca floats", mas as parcelas intermédias (0,765 € de filamento, 0,06325 € de reserva) não cabem num cêntimo e arredondá-las desviava o preço final. Caso de referência oficial, fixado em teste: **17 €/kg, 45 g, 2h30 a 0,20 €/h → custo 1,47825 €, revenda 3,00 €, cliente 5,50 €**.

**Um multiplicador único, e não uma tabela progressiva.** A margem do produtor foi 2,00× até 2 € de custo, 1,90× até 5 €, e por aí fora. Saiu em agosto de 2026: fazia o preço saltar de forma descontínua ao atravessar um limiar (um custo de 2,00 € dava 4,00 € de revenda, um de 2,02 € dava 3,84 — a peça mais cara vendia-se mais barato) e, somada a um custo de máquina de 0,50 €/h, dava preços que não competiam. Quem protege as peças pequenas passou a ser só o **chão de 1,50 €**, que abaixo de ~0,88 € de custo é quem decide o preço.

**Tempo em horas e minutos separados, nunca num decimal**: "1,30" tanto se lê como uma hora e trinta como 1,3 horas, e a diferença são 12 minutos de máquina em cada peça. A BD guarda o total em minutos.

**Modos**: `per_unit` (o utilizador descreve uma peça; a quantidade só multiplica os totais) e `batch` (peso e tempo da mesa inteira; o custo divide-se pela quantidade). Em lote o custo fixo por peça **não se usa** — numa mesa o trabalho que se faz uma vez dilui-se por todas as peças, e um valor por peça não sabe exprimir essa diluição. Em vez disso: montagem por impressão + trabalho por unidade.

Parâmetros globais em `config/pricing.php`, sobrepostos pelas chaves `pricing.*` da tabela `settings` e editáveis em `/admin/definicoes`: reserva de falha, preço mínimo de revenda, multiplicador de revenda, multiplicador do cliente, multiplicador mínimo, manuseamento por peça, manuseamento em lote. **Fora do config de propósito**: o degrau de 0,50 € da revenda e as faixas de arredondamento do retalho — são regras comerciais fixas.

Superfícies: `/admin/calculadora` (simulação livre, estado no URL) e o formulário de variante (com "Aplicar preços"). As duas usam o **mesmo motor no servidor** — a fórmula nunca é espelhada em TypeScript, porque não há runner de testes JS e os números caem em cima das fronteiras de arredondamento. **O modal de produto ficou de fora**: a gramagem e o tempo são dados de produção e editam-se variante a variante; lá em cima escrevem-se só os três preços (revenda, venda, promoção) que servem de molde à matriz.

### Encomendas
- **orders** — **order_number** (formato `2026-0042`; gerado dentro da transação via tabela `order_sequences` {year PK, last_number} com **`UPDATE ... SET last_number = last_number + 1 ... RETURNING`** — statement único e atómico, porque o SQLite não tem `SELECT ... FOR UPDATE`; nunca read-modify-write nem contagem de linhas), user_id? (nullable — guest; contas só na Fase 5), **customer_name** (notNull — pesquisa, quadro de produção, emails, etiquetas), email, phone?, **nif?**, status, **payment_method** (`card` | `multibanco` | `mbway` | `cash` | `bank_transfer` | `vinted` | `other`), **payment_status** (`pending` | `paid` | `partially_refunded` | `refunded` | `failed`) — **o indicador financeiro é `payment_status`**, não o status da encomenda, **sales_channel** (`website` | `vinted` | `instagram` | `manual`), **external_order_reference?**, **created_by_user_id?** (admin, em manuais), stripe_session_id? único (null em manuais), stripe_payment_intent_id?, subtotal_cents, shipping_cents, total_cents, currency, **shipping_address json snapshot**, billing_address?, shipping_method_name?, tracking_number?, tracking_url?, timestamps de estado, admin_note?, stock_issue, **guest_access_token**, campos de faturação agnósticos (invoice_provider?, invoice_external_id?, invoice_number?, invoice_url?, invoiced_at?)
- **order_items** — order_id, variant_id? (nullable — sobrevive a arquivamentos), product_name, variant_label ("PLA Preto / 20 cm"), sku, unit_price_cents (o cobrado), **catalog_unit_price_cents?** + **price_override_reason?** (manuais com preço editado), **personalization_surcharge_cents** (snapshot separado; **modo V1: por unidade** — `line_total = (unit + surcharge) × qty`; campo `mode per_unit|per_line` fica para depois), qty, line_total_cents, vat_rate, image_url, **personalization json?** (snapshot `{"name":"Júlia"}`), **fulfillment_mode snapshot**, **production_status** (`not_required` | `awaiting_production` | `printing` | `quality_check` | `ready`)
- **order_status_histories** — order_id, from_status?, to_status, **changed_by_user_id?**, note?, created_at — só estados da encomenda
- **order_item_status_histories** — order_item_id, from_status?, to_status, **changed_by_user_id?**, note?, created_at — só produção por item (tabelas separadas e tipadas)
- **stock_movements** — variant_id, delta, reason (`sale` | `restock_cancel` | `manual_adjust` | `manual_order` | `initial`), order_id?, **created_by_user_id?**, note
- **stock_reservations** — order_id, variant_id, qty, **expires_at**, released_at? — só para Multibanco pendente

### Suporte
- **checkouts** — snapshot congelado (itens, preços, surcharges, personalizações), stripe_session_id único, status
- **shipping_rates** — name, rate_cents, free_above_cents?, estimated_days_min/max, active
- **webhook_events** — id do evento Stripe como PK string (idempotência)
- **settings** — key/value json
- **users** (Fortify) — `is_admin` boolean; admin criado pelo `DatabaseSeeder` (falha em produção se `SEED_ADMIN_PASSWORD` vazio — padrão qrcode); **addresses** (1 por cliente) — ativos na Fase 5

### Pipeline de estados

**Encomenda** (pagamento/envio/terminais): `pending_payment → paid → in_production → ready_to_ship → shipped → delivered` + `cancelled`, `refunded`.
**Produção por item**: `not_required` (in_stock) | `awaiting_production → printing → quality_check → ready`.

- A encomenda **deriva o progresso dos itens**: ao pagar, itens made_to_order/custom entram `awaiting_production` (encomenda → `in_production`); quando **todos** ficam `ready`/`not_required` → auto-avanço para `ready_to_ship`; só itens in_stock → `paid → ready_to_ship` direto
- Transições forward-only com saltos, **exclusivamente** via `OrderService::transitionOrder()` e `OrderService::setItemProductionStatus()` — único sítio que escreve históricos, dispara o email de envio (`→ shipped`) e repõe stock (`→ cancelled`). Nunca validado só na interface
- Admin: quadro de produção com colunas por estado, ao nível do item

### Invariantes entre `payment_status` e `orders.status`

Validadas dentro de `transitionOrder()`/`setItemProductionStatus()`:

- `payment_status = pending` → status permanece `pending_payment`, salvo avanço manual explícito do admin
- `payment_status = paid` → avanço automático: só `in_stock` → `ready_to_ship`; algum made_to_order/custom → `in_production`
- `payment_status = failed` → `cancelled` + libertação de reservas
- `payment_status = refunded` → `refunded`
- **Nenhuma encomenda avança automaticamente** para `in_production`/`ready_to_ship`/`shipped` sem `payment_status = paid`
- Avanço manual com pagamento pendente exige confirmação explícita e fica no histórico com `changed_by_user_id` e nota

### Ciclo de stock (aplica a `in_stock`; made_to_order/custom usam capacidade)

1. **Checkout**: `in_stock` → valida `availableStock >= qty`; made_to_order/custom com capacidade → valida soma de qty em produção ativa + qty ≤ max
2. **Webhook `checkout.session.completed`**:
   - `payment_status='paid'` (cartão): `UPDATE variants SET stock = stock - ? WHERE id = ? AND stock - reserved_stock >= ?` + `stock_movements`
   - `payment_status='unpaid'` (Multibanco): reserva `UPDATE ... SET reserved_stock = reserved_stock + ? WHERE stock - reserved_stock >= ?` + `stock_reservations` com expires_at
3. **`async_payment_succeeded`**: converte reserva → stock; **`async_payment_failed`**/expiração: liberta
4. **Sweep**: scheduler (`routes/console.php`, `->everyFifteenMinutes()->withoutOverlapping()`) liberta reservas com `expires_at` ultrapassado — não confiar só nos eventos Stripe
5. Falha de decremento com pagamento feito → encomenda criada com `stock_issue = true` + alerta (o cliente pagou; problema operacional, não de integridade)

### Encomendas manuais (Vinted / Instagram / presencial)

Formulário "Nova encomenda" no admin: canal + referência externa + cliente (customer_name obrigatório, email/morada livres) + **método e estado de pagamento** + itens (variante, qty, preço editável com catalog_unit_price + motivo, personalização). Desconta stock (`stock_movements` reason `manual_order`, com `created_by_user_id`), entra no mesmo quadro de produção. **É isto que torna o backoffice o centro de todos os canais.**

Exemplo (Instagram, por pagar):
```
Canal: instagram · Método: bank_transfer · Pagamento: pending
Estado da encomenda: pending_payment · Itens: awaiting_production
```

**Produção não arranca automaticamente com pagamento pendente**; admin pode avançar manualmente (registado com autor e nota). Exceção prática: `vinted` pode entrar como `paid` (a plataforma garante o pagamento).

### Regra global de eliminação

**Entidades com histórico comercial nunca são apagadas fisicamente**: produtos, variantes, materiais, cores e taxas de envio usam `active`/`archived` (soft state). Hard delete só para entidades sem qualquer encomenda/movimento associado.

---

## Estrutura da app (convenções qrcode)

```
app/
  Http/Controllers/           HomeController, ProductController, CartController,
                              CheckoutController, OrderTrackingController,
                              Webhooks/StripeWebhookController,
                              Admin/{Dashboard,Product,Variant,MaterialColor,Category,
                                     Order,ManualOrder,ShippingRate,Stock,Setting}Controller
  Http/Middleware/            EnsureAdmin (alias 'admin')
  Http/Requests/              Product/, Order/, Checkout/, Admin/  (subpasta por domínio)
  Mail/                       OrderConfirmationMail, OrderPendingMultibancoMail,
                              OrderShippedMail, AdminAlertMail   (queued, afterCommit)
  Models/                     flat: Product, Variant, Material, Color, Order, OrderItem, …
  Services/                   CartService, CheckoutService, StripeCheckoutService,
                              OrderService, StockService, ImageService,
                              PricingCalculator, PricingSettings, PricingPreview,
                              Invoicing/{InvoicingProvider (interface), NullProvider}
  Support/                    Money.php, Rate.php, Micros.php,
                              PricingInput.php, PricingResult.php
config/shop.php               (+ env() APENAS aqui — guardado por teste)
routes/web.php                montra pública → grupo auth (/conta, Fase 5) →
                              grupo admin (prefix /admin, middleware 'admin') aninhado
routes/console.php            scheduler: sweep de reservas + backup diário do SQLite
                              (VACUUM INTO storage/backups, retenção) — tudo withoutOverlapping
resources/js/
  pages/                      kebab-case espelhando rotas:
                              index.tsx, produtos/{index,show}.tsx, carrinho.tsx,
                              checkout/{sucesso,cancelado}.tsx, encomenda/show.tsx,
                              conta/** (Fase 5), admin/{dashboard, produtos/**, encomendas/**,
                              materiais.tsx, categorias.tsx, envios.tsx, definicoes.tsx}
  components/ + components/ui/ (shadcn)   layouts/{store-layout,admin-layout}.tsx
  types/                      barrel index.ts + por domínio
  routes/ actions/            GERADOS pelo Wayfinder (committed, eslint-ignored)
docker/                       queue-worker.conf, scheduler.conf, inertia-ssr.conf,
                              nginx.conf, opcache.ini
Jenkinsfile · Dockerfile · docker-compose.yml   (adaptados do qrcode, IMAGE_NAME=12studio)
```

**Rotas web** (ordem qrcode): montra pública no topo (`/`, `/produtos`, `/produtos/{slug}`, `/carrinho`, legais) → checkout com throttle → `/encomenda/{number}?token=` (guest) → grupo `auth` (/conta, Fase 5) → grupo `admin` aninhado → webhook Stripe **fora do grupo `web`-CSRF** (`validateCsrfTokens(except: ['webhooks/stripe'])` em `bootstrap/app.php`).

**Páginas Inertia**: `type Props = {...}` local, `useForm` para todas as mutações, layout a envolver a página com `breadcrumbs`, `router.get(url, params, {preserveState: true})` para filtros. Admin com o `AppLayout` de sidebar do starter kit; montra com `StoreLayout` próprio (header, cart drawer, footer legal).

---

## Fluxo de pagamento

1. `CheckoutController@store` → `CheckoutService`: lê cookie do carrinho → **revalida na BD** (preços, active, availableStock/capacidade, personalizações vs `personalization_fields`, surcharge) → cria linha `checkouts` com snapshot → `StripeCheckoutService` cria a Session (`mode: payment`, line_items do snapshot com personalização no description, `shipping_address_collection: PT`, `phone_number_collection`, custom_field NIF opcional, shipping_options das `shipping_rates` ativas com free-above aplicado, `expires_at` +30 min, `metadata.checkout_id`) → `redirect()->away($session->url)`
2. `StripeWebhookController` (raw body `$request->getContent()` + `Webhook::constructEvent` com o signing secret):
   - `checkout.session.completed` → insert `webhook_events` (violação de PK = duplicado → 200) → `DB::transaction`: encomenda + itens (production_status inicial, payment_method detetado da session) + order_number via `order_sequences` + stock/reserva conforme `payment_status` → após commit, Mailables queued (`afterCommit`) — confirmação ou "aguardamos pagamento Multibanco"; alerta admin
   - `async_payment_succeeded` → `payment_status=paid` + converter reservas + transição + email
   - `async_payment_failed` → `failed` → cancelar + libertar reservas; `checkout.session.expired` → marcar checkout expirado
   - `charge.refunded` → `refunded`
   - Verificar `amount_total` vs snapshot; mismatch → flag + alerta, nunca bloquear. Responder 200 quando durável; 500 só em falha transitória (Stripe faz retry)
3. `/checkout/sucesso?session_id=` tolera a corrida com o webhook (polling ~20 s a um endpoint de estado)

**Idempotência tripla:** PK do evento + unique `orders.stripe_session_id` + guardas do `transitionOrder()`.

---

## Fases de implementação

| Fase | Âmbito | Esforço |
|---|---|---|
| **1. Base** | Scaffold do `laravel/react-starter-kit` (Laravel 12, Inertia React 19 TS, Tailwind 4, shadcn, Wayfinder, SSR), scripts composer/npm do qrcode (`test`, `lint`, `ci:check`), Pint + ESLint + Prettier + tsconfig iguais, **migrações completas de todo o schema** (SQLite dev + MySQL prod — evita churn), Fortify só com login (registo desativado até à Fase 5), seed do admin (`is_admin`, falha se password vazia em produção), middleware `EnsureAdmin` + testes, layouts montra/admin, CRUD de categorias e produtos base (controller fino + service + Form Request + páginas Inertia), **testes-guarda** (`ConfigCacheSafetyTest`, `QueueRoutingTest`), pragmas SQLite de produção (WAL, busy_timeout) + **backup diário agendado**, Dockerfile + docker-compose (volume persistente p/ o `.sqlite`) + supervisor confs + **Jenkinsfile** adaptados do qrcode | M–L |
| **2. Produtos** | Materiais & cores (CRUD, swatches), variantes (cor × tamanho, gerador de combinações), fotos (upload disco public, `ImageService::setPrimary` transacional, galeria por cor), **custos & margens completos** (PricingCalculator + testes, incl. o caso de referência 45 g/2h30), dimensões/pesos, personalizações (campos + surcharge), fulfillment_mode + capacidade + prazos, montra: home, /produtos c/ filtros, página de produto (swatches, prazo, personalização c/ preço) | L |
| **3. Venda** | CartService (cookie) + drawer + /carrinho, portes (CRUD + free-above), CheckoutService c/ revalidação e surcharges, Stripe test (cartão + Multibanco), webhook completo c/ CSRF except, **reservas + sweep no scheduler + capacidade**, encomendas guest, páginas sucesso/cancelado, tracking por token | XL |
| **4. Operação** | Quadro de produção **por item** no admin, detalhe de encomenda (timeline dos 2 históricos, notas, tracking CTT, **personalização renderizada com labels — nunca JSON bruto**), auto-avanço para ready_to_ship, **encomendas manuais** (canal, referência, pagamento, preço c/ override + motivo), Mailables (confirmação, pendente MB, enviado, alerta), ajustes manuais de stock, alertas de stock baixo | M–L |
| **5. Lançamento** | Registo de clientes (Fortify registration + verificação), /conta (histórico, 1 morada, prefill), páginas legais + nota de devolução em personalizados, SEO (meta, OG c/ imagem principal, sitemap — padrão qrcode), domínio + Nginx Proxy Manager. **Gate do dono: atividade aberta, Moloni manual, Stripe live KYC** | M |
| **Depois** | Cupões, avaliações, dashboard avançado, visualizador 3D, EN (padrão i18n do qrcode), recuperação de carrinhos, faturação automática (`InvoicingProvider` → Moloni), refunds no admin, `productionJob`, custo real vs estimado, regras de personalização avançadas, portes por peso | — |

Vendável no fim da **Fase 3**; lançável no fim da **Fase 5**.

### Desvio: operação antecipada (encomendas, clientes, produção)

Por decisão do dono, o backoffice passou a ser o centro de todos os canais **antes** da Fase 2 e da Fase 3, para poder registar já as vendas de Vinted/Instagram. Foi antecipado da **Fase 4** e trazido da **Fase 5**:

- **Variantes mínimas** (Fase 2 parcial) — CRUD de SKU, tamanho, preço e stock dentro da página do produto. **Sem materiais, cores, fotos, custos nem dimensões**: esses continuam a ser Fase 2, e `variants.color_id` fica a null até lá.
- **`StockService`** — updates condicionais (`WHERE stock - reserved_stock >= ?`) e `stock_movements` completos. **Reservas (`reserved_stock`, `stock_reservations`) e o sweep do scheduler continuam a ser Fase 3** — dependem do Multibanco.
- **Encomendas** — `OrderService` completo (numeração via `order_sequences` com `UPDATE … RETURNING`, criação manual, transições forward-only, invariantes `payment_status` × `status`, auto-avanço), lista com filtros, detalhe com timeline dos dois históricos, quadro de produção por item.
- **Clientes no backoffice** (Fase 5 parcial) — CRUD de `users` (`is_admin = false`) + a morada única. O cliente criado aqui recebe uma password aleatória e **não faz login**; o registo público e a área `/conta` continuam a ser Fase 5.
- **Mailables** — `OrderConfirmationMail` (opcional na criação manual) e `OrderShippedMail` (na transição `→ shipped`). Faltam os de Multibanco pendente e o alerta ao admin, que dependem da Fase 3.

Numa segunda ronda, ainda antes da Fase 2 propriamente dita, foi antecipado o resto do que o produto precisa para ser vendável:

- **Materiais & cores** (Fase 2 parcial) — CRUD completo com swatches e preço/kg (override por cor), e o seletor de cor na variante. `variants.color_id` deixou de ficar a null. **Sem gerador de combinações cor × tamanho e sem galeria por cor** — continuam a ser Fase 2.
- **Fotografias** (Fase 2 parcial) — `ImageService` (upload no disco `public`, `setPrimary` transacional contra o índice único parcial, reordenar, apagar) e a galeria na página de edição do produto. **Sem redimensionamento nem miniaturas**: os originais (até 5 MB) são servidos como estão — tem de mudar antes da montra pública.
- **Custo, revenda e preço final** — o `PricingCalculator` completo, `/admin/calculadora`, perfis de impressora (`/admin/impressoras`) e a pré-visualização dentro do formulário de variante. O tempo de impressão passou a ser input do cálculo; as colunas da fórmula antiga (`labor_minutes`, `energy_cost_cents`, `failure_rate_percent`) saíram do esquema e `packaging_cost_cents` virou `extra_cost_cents`.
- **Tags, slug editável e descrição formatada** — ver o schema acima.
- **Definições em runtime** — `SettingService` + `/admin/definicoes`: a moeda e os parâmetros de preço (chaves `pricing.*`, por cima do `config/pricing.php`). Duas rotas e dois formulários independentes, para uma gravação de moeda não rebentar num custo de manuseamento que ninguém tocou. O serviço tolera a tabela `settings` não existir (é lido em todos os pedidos pelo `HandleInertiaRequests`) e cai no config.

O que **não** foi antecipado e continua exatamente como planeado: carrinho, checkout, Stripe, webhook, reservas, sweep, capacidade de produção, montra pública de produtos, custos e margens.

---

## Verificação

- **Contínuo:** `composer test` (config:clear → pint --test → artisan test), `npm run lint && npm run types:check && npm run format:check`; suites `Unit`/`Feature` como no qrcode (NonFunctional opcional mais tarde); testes em SQLite `:memory:`; PHPUnit class-style (convenção dominante do qrcode)
- **Fase 1:** ver critérios abaixo
- **Fase 2:** seed (2 materiais × 3 cores, produtos dos 3 fulfillment modes); custo/margem conferem (unit tests em `Money`, `Micros`, `Rate` e `PricingCalculator` — **incl. o caso de referência 17 €/kg + 45 g + 2h30 → 1,47825 € / 3,00 € / 5,50 €**, a sensibilidade ao tempo, o lote, margem vs markup); combinação inexistente não aparece; personalização required bloqueia; `setPrimary` transacional testado
- **Fase 3:** `stripe listen --forward-to 12studio.test/webhooks/stripe`; cartão `4242…` → paid + stock decrementado; Multibanco → `pending_payment` + reserva (stock intacto, available reduzido) → sucesso simulado → conversão; falha → libertação; **replay de evento** → zero duplicados (PK `webhook_events`); corrida: 2 checkouts c/ availableStock=1 → um passa; capacidade: made_to_order max=2 com 2 em produção → rejeitado; sweep do scheduler liberta reserva expirada (teste com `travel()`); assinatura inválida → 403; feature tests com `Inertia\Testing\AssertableInertia`
- **Fase 4:** encomenda mista (in_stock + made_to_order) mostra estados independentes e só avança quando o segundo fica ready; manual Vinted desconta stock e aparece no quadro; email em `→ shipped` (Mail::fake + afterCommit); invariantes payment_status × status testadas exaustivamente no `OrderService`
- **Fase 5:** registo/verificação/login; encomendas em /conta; sitemap e meta OG; smoke E2E manual browse → carrinho → checkout → sucesso

---

## Riscos e armadilhas

1. **Stripe live exige atividade aberta** (KYC) — test mode até à Fase 5
2. **Multibanco assíncrono** — reservas com expiração; nunca assumir `completed` ⇒ pago (verificar `payment_status` da session)
3. **Eventos Stripe podem atrasar/perder-se** — sweep no scheduler é a rede de segurança
4. **Corridas de stock** — updates condicionais `WHERE stock - reserved_stock >= ?`; pago-mas-esgotado → `stock_issue` + alerta, nunca pagamento perdido
5. **Capacidade é limite soft** — verificação agregada tem corrida teórica; exceder 1 unidade em produção é gerível
6. **Webhook: CSRF except + raw body** — `validateCsrfTokens(except:)` em `bootstrap/app.php`; assinatura sobre `$request->getContent()` antes de qualquer parse
7. **SQLite em produção — regras de sobrevivência**: (a) o ficheiro `.sqlite` vive num **volume/bind-mount persistente** (como o `storage/` do qrcode) — nunca dentro da imagem, senão cada deploy apaga a loja; (b) WAL + `busy_timeout` obrigatórios (escritor único — escritas concorrentes esperam em vez de falhar); (c) transações de escrita curtas — nada de trabalho externo (Stripe, email) dentro de `DB::transaction`; (d) **backup diário agendado** (`VACUUM INTO` para `storage/backups` + cópia para fora da máquina) — sem replicação, o backup é a única rede; (e) `lockForUpdate()` é no-op — usar statements atómicos (`UPDATE ... RETURNING`, updates condicionais) para tudo o que é contador/sequência
8. **`env()` só em `config/`** — o deploy corre `artisan optimize` (opcache `validate_timestamps=0`); replicar `ConfigCacheSafetyTest`
9. **Migrações backwards-compatible** — o rollback do Jenkins repõe a imagem `:previous` **sem reverter migrações**; nunca dropar/renomear colunas no mesmo deploy que as deixa de usar
10. **Filas partilham o Redis** — `cache:clear` no deploy, nunca FLUSHALL; `queue:restart` depois do optimize; `after_commit => true`
11. **Cookie de carrinho** — cifrado pelo Laravel; limite ~4 KB; nunca confiar em preços do cookie
12. **IVA incluído** na montra; `vat_rate` + surcharge snapshots por item para a faturação futura
13. **Resolução de 14 dias** — personalizados isentos (nota no produto), catálogo não
14. **Snapshots, não FKs, no histórico** (itens, moradas, personalização, surcharge, fulfillment_mode)
15. **order_number** via `order_sequences` com `lockForUpdate()` na transação — nunca contagens
16. **Encomendas manuais sem Stripe** — `stripe_session_id` nullable; validação de stock no submit
17. **Wayfinder**: nunca duas rotas no mesmo URI com verbos separados (duplica chaves geradas — usar `Route::match`); regenerar no build Docker
18. **SSR**: componentes que tocam `window` guardam com `typeof window === 'undefined'`

## Próximo passo após aprovação: executar APENAS a Fase 1

Por decisão do dono, a implementação avança fase a fase — **não construir tudo de uma vez**. A Fase 1 termina quando todos estes critérios objetivos passarem:

1. `composer test` verde (Pint + suites Unit/Feature) e `npm run lint && npm run types:check && npm run format:check` limpos; `npm run build` (e `build:ssr`) sem erros
2. Schema **completo** deste plano migrado sem erros em SQLite; migrações escritas com as convenções do qrcode (classes anónimas, índices nomeados, `down()` implementado)
3. Login de admin via Fortify funciona; seed cria o admin a partir de config (falha em produção se password vazia); registo público desativado
4. `/admin` redireciona sem sessão e devolve 403 a user sem `is_admin` (testes de feature a provar ambos)
5. CRUD de categorias e produtos base operacional (controller fino + service + Form Request + páginas Inertia com `useForm`), testado com `AssertableInertia`
6. Layouts da montra (header/footer) e do admin (sidebar shadcn) renderizam; homepage lista produtos `active`
7. Testes-guarda `ConfigCacheSafetyTest` e `QueueRoutingTest` presentes e verdes; Dockerfile + docker-compose + supervisor confs + Jenkinsfile presentes e `docker build` local bem-sucedido — **o pipeline Jenkins em si só é verificável se o ambiente tiver acesso ao Jenkins; caso contrário, marcar esse ponto como "não verificável no ambiente" no relatório, nunca fingir**

**Relatório obrigatório no fim da Fase 1** (antes de parar para revisão): apresentar a estrutura criada; listar as migrações executadas; indicar as variáveis de ambiente necessárias; mostrar os testes e comandos executados com o respetivo output; identificar qualquer desvio ao plano; confirmar individualmente cada um dos 7 critérios. Não avançar para variantes, custos, Stripe, carrinho, encomendas ou produção. No fim, parar e aguardar revisão do dono antes da Fase 2.

## Ficheiros críticos

- `database/migrations/*` — schema completo (materiais, cores, custos, canal, capacidade, production_status, reservas, sequências)
- `app/Services/OrderService.php` — `transitionOrder()` + `setItemProductionStatus()` + invariantes payment×status + auto-avanço + manuais
- `app/Services/StockService.php` — decrementos condicionais, reservas, sweep, movimentos
- `app/Services/CheckoutService.php` + `StripeCheckoutService.php` — revalidação, capacidade, surcharges, snapshot, session
- `app/Http/Controllers/Webhooks/StripeWebhookController.php` — idempotência, transação, emails afterCommit
- `app/Support/Micros.php` + `app/Services/PricingCalculator.php` — aritmética em micro-euros e a fórmula de custo → revenda → preço final
- `Jenkinsfile` / `Dockerfile` / `docker-compose.yml` / `docker/*.conf` — adaptados do qrcode
