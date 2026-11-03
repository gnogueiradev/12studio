# Stripe: links de pagamento — plano

> Spec: `docs/superpowers/specs/2026-10-28-stripe-links-pagamento-design.md`
> Ramo: `feat/stripe-links-pagamento`. Nada de merge no `main` nem push sem o
> dono: o merge no `main` é o deploy.

**Goal:** a encomenda registada no backoffice tem um link `/pagar/{token}` que
cobra por Stripe Checkout, e o webhook marca-a paga. Os produtos e as variantes
ficam espelhados no Stripe como Products e Prices.

**Tech Stack:** Laravel 13.34 (cliente HTTP, sem SDK), Inertia + React,
PHPUnit com `Http::fake`.

**Restrições globais:**

- PHP só pelo PowerShell (Herd).
- Antes do tsc e do ESLint, correr `php artisan wayfinder:generate --with-form`.
- Nada de `composer update`.
- As migrações são aditivas.

## Tarefas

- [x] **1. Configuração e cliente**
  - Criar `config/stripe.php`.
  - Criar `App\Stripe\StripeClient`, com `get`, `post` (form, `Idempotency-Key`
    opcional), `isEnabled()` e só as leituras repetem.
  - Criar `StripeException`.
  - Testes: recusa com o Stripe desligado, envia a chave, o erro do Stripe sobe
    na exceção.
- [x] **2. Migração e espelho dos produtos**
  - Migração `2026_10_28_000010_add_stripe_columns_to_products_and_variants`.
  - Criar `App\Stripe\StripeCatalog::sync(Product)`.
  - Criar o job `SyncStripeProduct`.
  - Criar `App\Stripe\StripeHooks`, chamado pelo `StripeCatalogObserver` nos
    modelos `Product` e `Variant`. Fica no modelo e não nos serviços, porque os
    preços também mudam pela calculadora, pelo MCP e pela paleta.
  - Criar o comando `stripe:sync-products`.
  - Testes: cria o Product e os Prices, um preço mudado gera Price novo e
    arquiva o antigo, uma variante arquivada arquiva o Price, o produto
    arquivado fica `active=false`, o hook não lança erros e não agenda nada com
    o Stripe desligado.
- [x] **3. Sessão de pagamento**
  - Criar `App\Stripe\StripeCheckout::start(Order)`, que:
    - expira a sessão anterior, ou responde "em processamento";
    - escolhe entre o Price da variante e `price_data`;
    - trata os portes, o ajuste (linha ou cupão) e o `custom_text`;
    - confere o total;
    - guarda `stripe_session_id`.
  - Testes para cada caso.
- [x] **4. Página pública `/pagar/{token}`**
  - Criar o `PaymentLinkController` com `show` e `pay`.
  - Criar a página Inertia `pagar.tsx`.
  - Testes:
    - 404 com o Stripe desligado ou com um token errado;
    - o GET não chama o Stripe;
    - a página não leva dados pessoais;
    - os estados paga, cancelada, em processamento e "obrigado";
    - o POST redireciona com 303.
- [x] **5. Webhook**
  - Criar `StripeWebhookSignature`.
  - Criar o `StripeWebhookController`, com o ledger `webhook_events` numa
    transação.
  - Criar `StripeEvents`, que trata os quatro eventos.
  - Acrescentar ao `OrderService` um `notePayment()` público (nota no histórico
    sem mudar o estado).
  - Criar o rate limiter `stripe-webhook`.
  - Testes: assinatura, idempotência, cada evento, valor diferente do total,
    reembolso total e parcial.
- [x] **6. Backoffice**
  - `OrderPresenter::detail` passa a levar `paymentLink`.
  - A ficha da encomenda ganha o bloco "Link de pagamento" com o botão
    "Copiar".
  - Teste do presenter.
- [ ] **7. Portões**
  - Pint, PHPStan, tsc, ESLint e os testes (Stripe e encomendas, depois a suite
    toda em segundo plano).
  - Commit local.

## Revisão independente (2026-10-28)

Corrigido depois da revisão:

- **Reembolso de uma encomenda cancelada:** o `transitionOrder` recusava o
  reembolso e o webhook falhava para sempre. Agora o pagamento fica
  reembolsado, a encomenda continua cancelada e fica uma nota.
- **Segundo pagamento numa encomenda já paga** (marcada à mão enquanto o
  cliente pagava pelo link): já não é ignorado em silêncio. Fica uma nota de
  aviso no histórico e uma linha no log.
- **Dois "Pagar" ao mesmo tempo:** um `Cache::lock` por encomenda à volta de
  expirar a sessão antiga e criar a nova.
- **O pedido ao Stripe para saber o método de pagamento** já não corre com a
  transação aberta: passou para `DB::afterCommit`. Prendia o SQLite.
- **A sincronização de um produto sem mudanças** já não faz pedidos. Um Price
  arquivado fica com `stripe_price_cents` a null, e a variante reativada ganha
  um Price novo.
- **Casos-limite:**
  - o resumo fica dentro dos 1200 caracteres;
  - um desconto maior do que as linhas é recusado, porque o cupão não toca nos
    portes;
  - uma encomenda reembolsada já não aparece como "paga".
