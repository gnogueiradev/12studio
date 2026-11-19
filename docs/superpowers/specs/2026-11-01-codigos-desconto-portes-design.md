# Códigos de desconto, portes grátis e link de pagamento

**Data:** 2026-11-01
**Ramo:** `gn/promo-codes-stripe-a62c3d`, em cima de `feat/stripe-links-pagamento`

## Decisões do dono

- O **carrinho da loja fica para um projeto à parte**, em cima da montra. Não há
  carrinho nem checkout em nenhum ramo.
- O **registo de clientes continua desligado**. O código de boas-vindas fica pronto
  e testado, e só dispara quando o registo for ligado.
- Este ramo traz o Stripe que ainda não está no `main`.

## Regras

- `total = subtotal − desconto + portes + ajuste`, só em `OrderTotalsCalculator`.
- Portes grátis pelo subtotal **antes** do desconto. Na encomenda manual, os portes
  são uma sugestão: vendas em mão e no Vinted não podem levar portes à força.
- Um código escolhe-se ao criar a encomenda. O `channel` só diz de onde veio o
  código; não limita onde se pode usar.
- O uso regista-se em `OrderService::setPaymentStatus` quando a encomenda fica paga,
  por isso conta também os pagamentos marcados à mão. Um reembolso não o devolve.
- Um código esgotado depois de a encomenda estar paga não lança erro: a encomenda
  fica paga, sem uso registado, com aviso no log e nota no histórico. Uma exceção
  fazia o Stripe repetir o webhook durante dias.
- Um código não se apaga, desativa-se. O de boas-vindas fica preso ao email.

## Stripe

- Sem SDK. O cupão é one-off, porque o Stripe só aceita um por sessão: o código e um
  ajuste negativo vão juntos.
- **Uma só sessão viva por encomenda.** Gerar de novo devolve a mesma se a encomenda
  não mudou e faltarem pelo menos 12 horas. Caso contrário, expira-a e cria outra.
- A sessão aberta **fecha-se sozinha** (`ExpireStripeSession`, na fila) quando a
  encomenda é paga por outra via, cancelada ou muda de total. Sem isto, um link
  enviado por DM continuava a cobrar durante 24 horas.
- As chaves de idempotência são `order-{id}-{updated_at}-{expires_at}-{session|coupon}`.
  O Stripe recusa a mesma chave com outros parâmetros, por isso a validade arredonda
  a uma janela de 5 minutos. A sessão grava-se sem tocar no `updated_at`.
- Abaixo de 0,50 € não se cria nada no Stripe.

## Base de dados

Migrações aditivas: `promo_codes`, `promo_code_redemptions` e três colunas em
`orders`. `promo_code_id` não tem FK, porque em SQLite o `constrained()` reconstruía a
tabela `orders` inteira. Conferido com `migrate --pretend`.

- `redemptions.unique_email` existe porque o índice único dependia de uma coluna de
  outra tabela.
- O índice parcial `promo_codes_welcome_email_unique` garante um só código de
  boas-vindas por email, para sempre.

## Logs

Canais `api` e `queue`, com nível próprio, porque a produção força `LOG_LEVEL=warning`.
Emails sempre mascarados. As mensagens de erro de SMTP são limpas antes de subirem,
porque costumam trazer o destinatário.

## Fora de âmbito

Carrinho e checkout na loja, abrir o registo de clientes, códigos no MCP e na Etsy.
