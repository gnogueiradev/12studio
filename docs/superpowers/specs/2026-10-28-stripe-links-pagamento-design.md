# Stripe: links de pagamento para encomendas do backoffice

**Data:** 2026-10-28
**Ramo:** `feat/stripe-links-pagamento` (a partir do `main`)

## O problema

As vendas fazem-se no Facebook e no Instagram. O dono regista a encomenda no
backoffice e o cliente paga por fora, a mão: o dono tem de confirmar o
pagamento e marcá-lo. O objetivo é enviar ao cliente um link que cobra
exatamente aquela encomenda e marca-a como paga sozinho.

Fica de fora: carrinho e checkout na montra. Só se paga uma encomenda que o
dono criou.

## Decisões tomadas com o dono

1. **Um link por encomenda, para um só pagamento.** Depois de paga, a página do
   link diz que já está paga e não deixa pagar outra vez.
2. **Os produtos vão para o Stripe.** Um **Product** do Stripe por cada produto
   do 12studio, e um **Price** do Stripe por cada variante. Assim os relatórios
   do Stripe mostram o que se vende.
3. **O preço cobrado é o da encomenda e não o do catálogo.** Se a linha tem o
   preço normal da variante, o link usa o Price da variante. Se o preço foi
   mudado na encomenda, a linha leva um preço só dela (`price_data`), ligado na
   mesma ao Product do Stripe.
4. **O código encaixa no que já existe:** `orders.stripe_session_id` (único),
   `orders.stripe_payment_intent_id` e a tabela `webhook_events`, que guarda o id
   de cada aviso para não o processar duas vezes.

## Decisões técnicas (minhas, a confirmar na revisão)

- **Sem SDK do Stripe.** Usa-se o cliente HTTP do Laravel, como na Etsy. O
  Laravel está preso na 13.34 e um `composer require` mexia no lock. Os testes
  usam `Http::fake`.
- **O link é nosso e não do Stripe:** `/pagar/{guest_access_token}`. Cada
  encomenda já nasce com esse token aleatório de 64 caracteres, que ainda nada
  usa, por isso não há migração. O link não expira. A Checkout Session do
  Stripe expira em 24 horas e é criada no momento de pagar.
- **Abrir o link (GET) só mostra o resumo:** número, linhas, total e estado. A
  sessão do Stripe só nasce no botão "Pagar" (POST). O Instagram e o Facebook
  abrem os links para a pré-visualização, e um GET que criasse sessões gastava
  uma a cada pré-visualização.
- **Uma só sessão viva por encomenda.** Antes de criar uma sessão nova, a
  anterior é expirada no Stripe, se ainda estiver aberta. Se a anterior estiver
  completa mas por pagar (uma referência Multibanco à espera), não se cria outra
  e a página diz "pagamento em processamento". Assim um cliente com duas abas
  não paga duas vezes.
- **Métodos de pagamento:** os que estiverem ligados no Dashboard do Stripe
  (cartão, MB WAY, Multibanco…). O código não os fixa e trata tanto os imediatos
  como os assíncronos.
- **No Stripe, o cliente vê o nome do produto mas não o da variante.** Duas
  linhas "Vaso" a 25 € e a 15 € não dizem qual é qual. Por isso a sessão leva no
  `custom_text` o número da encomenda e as linhas com tamanho e cor.
- **Portes e ajustes:**
  - os portes da encomenda vão como `shipping_options` com valor fixo;
  - um ajuste positivo vai como uma linha à parte;
  - um ajuste negativo (desconto) vai como um cupão de uso único criado nessa
    altura.

  Antes de criar a sessão, confirma-se que linhas + portes + ajuste dão o
  `total_cents` da encomenda. Se não derem, recusa-se e não se cobra.

## Como fica

### Sincronizar os produtos

- Migração aditiva com três colunas que nascem vazias:
  - `products.stripe_product_id`;
  - `variants.stripe_price_id`;
  - `variants.stripe_price_cents`, o valor que esse Price tem. Os Prices do
    Stripe não se editam, por isso guarda-se o valor para saber quando é
    preciso um Price novo.
- `SyncStripeProduct`, uma tarefa na fila que:
  - cria ou atualiza o Product com o nome, a descrição sem HTML, a primeira
    imagem (se o URL for https) e `active` = não arquivado nem apagado;
  - para cada variante ativa cujo preço mudou, cria um Price novo e arquiva o
    anterior;
  - arquiva os Prices das variantes arquivadas.
- Um observer nos modelos `Product` e `Variant` chama o `StripeHooks` quando
  muda o que o Stripe mostra:
  - no produto: o nome, a descrição, o estado e o apagar;
  - na variante: o preço e o ativo.

  Fica no modelo e não nos serviços, porque os preços também mudam pela
  calculadora, pelo MCP e pela paleta. O hook agenda a tarefa depois do commit e
  nunca lança erros: o Stripe em baixo não estraga a gravação de um produto.
- `php artisan stripe:sync-products` faz a primeira carga de todos os produtos e
  serve para ressincronizar.

### Link de pagamento

- No backoffice, a ficha da encomenda mostra "Link de pagamento" com o URL e um
  botão "Copiar" quando o Stripe está ligado, a encomenda está por pagar e não
  está cancelada.
- `GET /pagar/{token}` mostra uma página pública simples, em português e sem
  dados pessoais (sem morada, email nem telefone). Tem o botão "Pagar" ou um
  destes estados: paga, cancelada, em processamento.
- `POST /pagar/{token}` faz isto, com throttle:
  1. expira a sessão anterior, se estiver aberta;
  2. cria a sessão nova com `client_reference_id` = id da encomenda e a mesma
     metadata na sessão e no PaymentIntent;
  3. guarda `stripe_session_id`;
  4. redireciona com 303 para o Stripe.
- `success_url` volta a `/pagar/{token}?pago=1`. Se o webhook ainda não chegou,
  mostra "Obrigado, estamos a confirmar o pagamento".

### Webhook

`POST /webhooks/stripe` fica em `routes/webhooks.php`, sem CSRF nem sessão e com
throttle.

- A assinatura `Stripe-Signature: t=…,v1=…` é um HMAC-SHA256 em hex de
  `"{t}.{corpo}"` com o segredo `whsec_…`, e tem 5 minutos de tolerância.
- Com o Stripe desligado ou sem segredo, a resposta é 404. Com a assinatura
  errada, é 401 e uma linha de log por minuto.
- O id do evento entra em `webhook_events` na mesma transação em que é tratado.
  Se já lá estiver, responde 200 e não faz nada. Se o tratamento falhar, a
  transação desfaz-se e o Stripe repete.
- Eventos tratados:
  - `checkout.session.completed` com `payment_status=paid` e
    `checkout.session.async_payment_succeeded`: a encomenda passa a **paga**
    pelo `OrderService::setPaymentStatus`, que a leva para produção ou para
    expedição como hoje. Guarda-se também o `payment_intent`, e o
    `payment_method` (cartão, MB WAY ou Multibanco) vem da charge. Se o valor
    pago for diferente do total, fica na mesma paga, mas com uma nota no
    histórico.
  - `checkout.session.completed` com `unpaid`: guarda-se o `payment_intent` e
    fica uma nota "à espera do pagamento".
  - `checkout.session.async_payment_failed`: só uma nota no histórico. A
    encomenda **não** é cancelada, porque o cliente pode tentar outra vez.
  - `charge.refunded`: reembolso total passa a `refunded`, parcial a
    `partially_refunded`.
- Para descobrir a encomenda, usa-se o `client_reference_id`, que tem de bater
  com a metadata. No reembolso usa-se `stripe_payment_intent_id`.

### Configuração

`config/stripe.php`:

- `STRIPE_ENABLED`, desligado por omissão;
- `STRIPE_SECRET`, que é `sk_test_…` ou `sk_live_…`;
- `STRIPE_WEBHOOK_SECRET`;
- `STRIPE_API_VERSION`, opcional;
- o timeout e a tolerância.

Tudo fica no `.env`, como a identidade da app Etsy.

## Testes

Feature tests com `Http::fake` e eventos assinados a sério com um segredo de
teste. Cobrem:

- a assinatura, a idempotência e cada evento;
- que o total tem de bater;
- que a sessão anterior é expirada;
- que um GET não cria sessão;
- que a página não mostra dados pessoais;
- que a tarefa cria um Price novo e arquiva o antigo quando o preço muda;
- que os hooks não lançam erros com o Stripe em baixo.

## Fora de âmbito

- Checkout na montra.
- Reembolsos a partir do backoffice: fazem-se no Dashboard do Stripe e chegam
  pelo webhook.
- Faturação.
- Um Product do Stripe por variante.

## Deploy (só com o dono)

- A migração é aditiva.
- No `.env` de produção: `STRIPE_ENABLED=true`, as chaves e o segredo do
  webhook.
- No Dashboard do Stripe:
  - criar o endpoint `https://…/webhooks/stripe` com os quatro eventos;
  - ligar os métodos de pagamento.
- Depois, `php artisan stripe:sync-products`.
- Testar primeiro com as chaves `sk_test_`.
