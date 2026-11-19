# Loja online: carrinho e compra sem conta

**Data:** 2026-11-18
**Estado:** por aprovar pelo dono
**Ramo de trabalho:** `worktree-loja-carrinho`, a partir do `main`, com o `gn/promo-codes-stripe-a62c3d` integrado nele

## Porquê

O dono quer ver se consegue vender mais. Hoje a montra mostra o catálogo mas não
vende: o cliente abre uma conversa ("Tenho interesse"), o dono regista a encomenda
à mão no backoffice e manda um link `/pagar/…`. Cada venda depende de uma troca de
mensagens.

Este projeto deixa o cliente comprar sozinho: escolhe, põe no carrinho, paga.

Em outubro o dono tinha decidido não ter checkout na montra (as vendas faziam-se
no Facebook e no Instagram). Essa decisão foi revista a 2026-11-18. O link de
pagamento continua a existir para essas vendas.

## Objetivos

- Comprar uma ou várias peças na montra, sem conta, com o mínimo de passos.
- A encomenda entra no backoffice e na produção como qualquer outra, com
  `sales_channel = website`, já paga.
- Os portes grátis acima de 25 € ficam à vista no carrinho, para puxar pelo valor
  de cada compra.
- A compra liga-se e desliga-se nas definições, sem deploy, e o dono consegue
  experimentá-la em produção antes de os clientes a verem. Desligada, a montra
  fica exatamente como está hoje.

## Fora de âmbito

- **Contas de cliente** (registo, login público, área `/conta`, moradas guardadas,
  código de boas-vindas). É o projeto seguinte, com desenho próprio.
- Envios para fora de Portugal. Essas vendas continuam na Etsy.
- Levantamento em mão como opção de envio.
- Recuperação de carrinhos abandonados (emails de lembrete).
- Faturação certificada. O NIF recolhe-se; a fatura continua a ser feita por fora.
- Compra rápida a partir dos cartões do catálogo. Compra-se na página do produto.
- Códigos de desconto no MCP e na Etsy.

## O que seria um falhanço, mesmo a funcionar

- Carrinhos abandonados a aparecer como encomendas por pagar no backoffice, ou a
  disparar avisos de "nova encomenda" no Discord.
- Um cliente pagar e a encomenda não aparecer.
- Cobrar um valor diferente do que o cliente viu no resumo antes de pagar.
- Um robô ou a pré-visualização de um link criar carrinhos, encomendas ou sessões
  no Stripe.
- O deploy pôr a compra à frente dos clientes antes de o dono a ter visto.

## Restrições

- **A base de dados tem dados em produção** (SQLite). Só migrações aditivas, sem
  `constrained()` em tabelas já povoadas (em SQLite reconstrói a tabela), conferidas
  com `migrate --pretend`.
- **Merge no `main` é deploy.** Só com o dono a pedir. Daí o interruptor.
- **Laravel preso na 13.34** e sem SDK do Stripe: o `StripeClient` da casa, por HTTP.
- **Os preços vêm sempre da BD**, nunca do browser.
- **Um GET nunca cria nada**: nem carrinho, nem checkout, nem sessão no Stripe.
- Contraste WCAG acima da fidelidade à cor da marca.
- Testes com `FakesStripe`, que tem de recusar o que o Stripe recusa.
- Os cinco portões de qualidade passam antes de se dizer que está feito.

## Caminhos considerados

**A. Carrinho e compra sem conta; contas a seguir.** Escolhido pelo dono a
2026-11-18. A primeira entrega já vende, e o carrinho aproveita os portes grátis.
Custa duas entregas em vez de uma.

**B. Conta obrigatória para comprar.** Um só caminho de compra, mas cada passo
antes de pagar perde compradores, e obrigava a abrir o login ao público antes da
primeira venda. Vai contra o objetivo.

**C. Botão "Comprar já", sem carrinho.** O mais rápido de fazer. Um produto por
compra: perde os portes grátis como alavanca e não é o que o dono pediu.

## Como funciona, do lado do cliente

1. **Página do produto.** Nas peças que se podem comprar, o botão principal passa
   a ser "Adicionar ao carrinho", com quantidade. Se o produto tem campos de
   personalização, preenchem-se ali mesmo, com a sobretaxa à vista. "Fazer uma
   pergunta" continua. "Tenho interesse" fica só onde não se pode comprar.
2. **Depois de adicionar**, abre um painel lateral com o carrinho: as linhas, o
   subtotal, quanto falta para os portes grátis ("Faltam 6,50 € para portes
   grátis") e dois botões, "Ver carrinho" e "Finalizar compra".
3. **Cabeçalho.** Um ícone de carrinho com o número de peças, em todas as páginas
   da montra.
4. **`/carrinho`.** As linhas com fotografia, variante, personalização, preço e
   quantidade editável; remover; campo do código de desconto; subtotal, desconto,
   portes e total.
5. **`/finalizar`.** Um só ecrã: nome, email, telemóvel, NIF (opcional), morada em
   Portugal, o resumo com o total, e a caixa "Li e aceito os termos". O botão
   "Pagar" leva à página do Stripe.
6. **Stripe.** Cartão, MB WAY ou Multibanco, conforme o que estiver ligado na conta.
7. **`/compra/{token}`.** A página de regresso. Enquanto o Stripe não confirma,
   diz "A confirmar o pagamento…" e atualiza-se sozinha. Depois mostra o número da
   encomenda, o resumo e o prazo. Com Multibanco, diz que a referência foi gerada e
   que a encomenda avança quando for paga.
8. **Email de confirmação**, o que já existe (`OrderConfirmationMail`), em
   português.

### O que se pode comprar

Decide-se no servidor, num só sítio, e a página do produto e o carrinho perguntam
aí:

| Produto | Variante | Compra-se? |
|---|---|---|
| `custom` (por medida, sem preço) | qualquer | Não. Continua pela conversa. |
| `made_to_order` | visível na montra | Sim, com o prazo de produção. |
| `in_stock` | com stock disponível para a quantidade | Sim. Desconta stock. |
| `in_stock` | stock a menos, produto aceita espera (`allow_backorder`) | Sim. A linha inteira é feita por encomenda. |
| `in_stock` | stock a menos, não aceita espera | Só até ao que há. Sem nenhum: "Tenho interesse". |

Um produto fora da montra (rascunho, arquivado, apagado) ou uma variante escondida
nunca se compram.

Uma linha é de stock ou é de produção, nunca metade de cada. Pedir 3 quando há 2,
num produto que aceita espera, faz as 3 por encomenda: vão para produção com o
prazo de produção e não tocam no stock. Num produto que não aceita espera, a
quantidade não passa de 2.

## Como funciona, por dentro

### Carrinho

Vive na base de dados (`carts`, `cart_items`), e o browser guarda só um token
aleatório num cookie com 60 dias. Razões: a sessão do Laravel dura 120 minutos, e
um carrinho que desaparece não vende; o texto da personalização não cabe com
segurança num cookie; e quando houver contas, liga-se o carrinho ao utilizador.

- O carrinho nasce no primeiro "Adicionar" (um POST). Abrir páginas não o cria.
- Cada linha guarda a variante, a quantidade e a personalização. **Não guarda
  preços**: são lidos da BD de cada vez que o carrinho é mostrado.
- A mesma variante com a mesma personalização soma à linha que já existe.
- Ao mostrar o carrinho, uma linha que deixou de se poder comprar (sem stock,
  produto retirado) fica marcada e impede o "Finalizar" até ser removida ou
  acertada. Não desaparece sozinha.
- Limites: quantidade por linha e número de linhas, mais um throttle nos POST.
- Carrinhos parados há mais de 60 dias são apagados pelo scheduler.
- O cookie do carrinho é estritamente necessário: não depende de consentimento.

### Totais

`total = subtotal − desconto + portes`, pelo `OrderTotalsCalculator` do ramo dos
códigos de desconto. As mesmas contas no carrinho, no checkout e na encomenda.

- Linha = (preço da variante + sobretaxa de personalização, se preenchida) × quantidade.
- Portes: os de `config('shop.shipping')` (4,50 €, grátis a partir de 25 € de
  subtotal **antes** do desconto). A tabela `shipping_rates` continua por usar.
- Portugal inteiro, ilhas incluídas, ao mesmo preço.
- Código de desconto: validado pelo `PromoCodeValidator`. No carrinho ainda não
  há email, e o validador recusa os códigos pessoais e os de uso único por email
  quando ele falta. Essa recusa em particular (`EMAIL_REQUIRED`) não se mostra
  como erro: o carrinho diz que o código se confirma no passo seguinte, e o
  desconto só entra nas contas no checkout, já com o email. O uso só se regista
  quando a encomenda fica paga, como já acontece.
- O Stripe não cobra menos de 0,50 €. Um código que deixe o total abaixo disso
  não se aplica na loja: o checkout diz porquê e manda falar com a loja. Não há
  encomendas grátis sem passar pelo Stripe.

### Do "Pagar" à encomenda

A encomenda **só nasce quando o Stripe confirma**. Até lá existe um registo na
tabela `checkouts`, que já existe desde a Fase 1 para isto.

1. O POST de `/finalizar` valida tudo outra vez no servidor (dados do cliente,
   cada linha, o código, os totais) e grava um `checkout` com o **retrato
   congelado** da compra: linhas com nomes e preços, dados do cliente, morada,
   desconto, portes e total.
2. Cria uma Checkout Session no Stripe a partir desse retrato e manda o cliente
   para lá. A sessão expira ao fim de uma hora.
3. O webhook `checkout.session.completed` encontra o `checkout` pela metadata da
   sessão e cria a encomenda a partir do retrato, **nunca do carrinho**, que
   entretanto pode ter mudado.
   - Pago na hora (cartão, MB WAY): a encomenda nasce e fica paga pelo
     `OrderService::setPaymentStatus`, que já trata do resto (produção ou
     expedição, uso do código, histórico).
   - Multibanco (referência gerada, por pagar): a encomenda nasce
     `pending_payment`. Quando o Stripe avisar que foi paga, ou que falhou, o
     aviso traz a metadata do `checkout`, não a da encomenda: chega-se à
     encomenda pelo `checkout`. O `StripeEvents::orderOf` de hoje só conhece
     sessões criadas a partir de uma encomenda e ignorava este pagamento em
     silêncio; tem de ganhar este caminho.
4. O carrinho é esvaziado e o `checkout` fica `completed`, a apontar para a encomenda.
5. Sai o email de confirmação e o aviso do Discord. Hoje ninguém manda o email
   sozinho (só o backoffice e o MCP, ao criar uma encomenda manual): a criação da
   encomenda da loja tem de o mandar, depois do commit.

A encomenda da loja nasce por um método próprio do `OrderService`, irmão do
`createFromMarketplace`: sem autor, com email, NIF, morada, código de desconto e
sobretaxa de personalização, e podendo nascer paga ou por pagar. Fica sem
`user_id`, como as da Etsy; ligar compras a clientes é do projeto das contas.
O que identifica uma compra da loja é o `checkout` que aponta para ela, não o
`sales_channel = website`, que as encomendas manuais também podem usar.

**Stock.** Se uma linha é de stock ou de produção fica decidido no retrato, ao
clicar em "Pagar". As linhas de stock descontam-no quando a encomenda nasce. Se
entretanto o stock acabou, a encomenda nasce na mesma, com `stock_issue` marcado
e nota no histórico: o dinheiro já foi cobrado, e é o dono que resolve, mesmo
num produto que aceita espera. É o comportamento que as vendas da Etsy já têm.
Não há reserva de stock durante o pagamento; a tabela `stock_reservations`
continua por usar.

**Multibanco que não chega a ser pago.** A encomenda fica por pagar, como uma
encomenda manual: o stock continua descontado até o dono a cancelar, e o alerta de
encomendas paradas que já existe avisa.

**Idempotência.** O Stripe repete avisos. A mesma sessão nunca cria duas
encomendas: a âncora é o `checkout` (`checkouts.stripe_session_id` é único, e o
`checkout` passa a `completed` e ganha a encomenda na mesma transação em que ela
nasce), e o `StripeWebhookController` já guarda os eventos vistos. O
`orders.stripe_session_id` não serve de âncora: um Multibanco falhado apaga-o.

**Valor.** A sessão do Stripe é montada a partir do retrato, e antes de pedir
confere-se que as linhas, os portes e o desconto somam o total. Se não somarem,
não se cobra, como no link de pagamento.

### Stripe

O `StripeCheckout` de hoje só sabe cobrar uma `Order`. Passa a montar a sessão a
partir de uma descrição neutra do que há a cobrar (linhas, portes, desconto,
total, email, endereços de regresso), que tanto se constrói de uma encomenda (link
de pagamento) como de um `checkout` (loja). O `StripeEvents` ganha o caminho do
`checkout`; o da encomenda fica como está.

As regras do link de pagamento mantêm-se: uma variante com o preço normal usa o
Price dela, o desconto vai como cupão de uso único, os portes como portes.

### Ligar e desligar

Uma definição nova em Definições → Montra, "Compras na montra", com três estados:

- **Desligada** (como nasce): a página do produto mostra o que mostra hoje, e as
  rotas do carrinho e do checkout dão 404.
- **Só para a equipa**: um admin com sessão iniciada vê e usa a compra; os
  clientes continuam a ver a montra de hoje. É assim que o dono experimenta em
  produção, com chaves de teste do Stripe, sem que um visitante consiga "pagar"
  com um cartão de teste. Segue o que o `EnsureStoreOpen` já faz com a
  pré-visualização da montra fechada.
- **Ligada**: todos.

Em qualquer estado, a compra só funciona com o Stripe ligado (`STRIPE_ENABLED`)
**e** o segredo do webhook configurado. Sem o segredo, o
`StripeWebhookController` responde 404, o cliente pagava e a encomenda nunca
aparecia: nesse caso a compra comporta-se como desligada.

### Idiomas

A montra tem pt, en, es e fr. O carrinho e o checkout usam as mesmas traduções
(`lang/*/store.php`) e os mesmos endereços com prefixo de idioma. Nos idiomas que
não o português, o checkout avisa que só se envia para Portugal.

Os emails ficam em português, como hoje, e os nomes dos produtos no retrato e na
encomenda também: é o mesmo `product_name` que alimenta o backoffice, o Discord e
o quadro de produção. O `checkout` guarda o idioma da compra, para quando se
traduzirem os emails; isso fica fora deste projeto.

### Base de dados

Tudo aditivo:

- `carts`: token, datas.
- `cart_items`: carrinho, variante, quantidade, personalização.
- `checkouts` (já existe, vazia de uso): colunas novas para o token público, os
  dados do cliente, a morada, o desconto e o código, os portes, o total, o idioma,
  o carrinho de origem e a encomenda criada.

Nada muda em `orders` nem em `order_items` além do que o ramo dos códigos de
desconto já traz.

## O que tem de estar feito antes de ligar em produção

Não é trabalho deste projeto, mas a compra não se deve ligar sem isto:

- As páginas `/termos`, `/privacidade` e `/envios-e-devolucoes` publicadas. Hoje
  estão em rascunho e dão 404, e o checkout tem uma caixa que aponta para elas.
- `STRIPE_*` no `.env`, o endpoint do webhook no Dashboard do Stripe e
  `stripe:sync-products`, primeiro com chaves de teste.
- Os métodos de pagamento (MB WAY, Multibanco) ligados na conta Stripe.
- O `.env` de produção com os portes, se forem diferentes de 4,50 € e 25 €.

## Decisões

Do dono:

- Caminho A: carrinho e compra sem conta agora, contas depois.
- O objetivo é vender mais.

Minhas, para o dono corrigir:

- A encomenda só nasce quando o Stripe confirma (tabela `checkouts`), e não ao
  clicar em "Pagar". Evita encomendas-fantasma no backoffice.
- O carrinho vive na base de dados, com um cookie de 60 dias.
- Só Portugal, com os portes do ramo dos códigos de desconto. Açores e Madeira
  incluídos, ao mesmo preço.
- Peças por medida continuam pela conversa.
- "Compras na montra" com três estados (desligada, só para a equipa, ligada),
  desligada à nascença.
- Sem reserva de stock durante o pagamento; se faltar, a encomenda entra marcada.
- Uma linha é toda de stock ou toda de produção.
- Emails e nomes de produto em português, seja qual for o idioma da compra.
- Um total abaixo de 0,50 € não se compra na loja.
- O ramo de trabalho leva o `gn/promo-codes-stripe-a62c3d` dentro (há um conflito
  conhecido em `resources/js/pages/admin/encomendas/show.tsx`).
- A página de regresso é `/compra/{token}` e serve só para confirmar a compra. Uma
  página de acompanhamento da encomenda fica para o projeto das contas.

## Deixado a quem constrói

- Os nomes das classes, das rotas e das colunas, e como partir o `StripeCheckout`.
- Os limites exatos (quantidade por linha, linhas por carrinho, throttles).
- O desenho visual do painel, do carrinho e do checkout, dentro do tema da
  montra (`store-theme`) e dos componentes que já existem.
- Como a página de regresso se atualiza enquanto espera pelo webhook.
- A validação do código postal e do NIF.
- Onde encaixa o ícone do carrinho no `store-header`.
- A divisão em fases e a ordem dos commits (vem no plano).
