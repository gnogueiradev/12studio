# Contas de cliente e ofertas para voltar a comprar — desenho

Data: 2026-11-19. Projeto seguinte ao da loja com carrinho e compra sem conta
(`2026-11-18-loja-carrinho-checkout-design.md`), que já está no `main`.

## Para quê

O dono quer **que quem já comprou volte a comprar**. São duas peças que servem
esse objetivo:

1. **Dar um motivo para voltar.** Umas semanas depois de a encomenda ser enviada,
   a loja manda por email um código pessoal de desconto para a próxima compra,
   com um lembrete antes de o código expirar. Corre sozinho.
2. **Tirar atrito ao regresso.** Uma conta onde o cliente vê as encomendas,
   encontra os dados já guardados no checkout e volta a comprar o mesmo com um
   botão. Entra-se com um link enviado por email, sem password.

O código para a próxima compra não precisa de conta: fica ligado ao email. A
conta torna o regresso mais confortável, mas não é uma condição.

## Fora de âmbito

- **Campanhas escritas pelo dono** (newsletter, promoções a todos). Se fizerem
  falta, ficam para depois, de preferência com um serviço próprio (Brevo,
  Mailchimp) ligado à loja.
- **Passwords para clientes.** Não há registo com password, nem "esqueci-me da
  password" do lado do cliente.
- **Apagar a conta pelo próprio cliente.** O pedido faz-se por email, como diz a
  política de privacidade, e trata-se no backoffice.
- **Emails noutras línguas.** Estes emails seguem em português, como os outros da
  loja.
- **Ofertas a quem comprou fora da loja online.** As vendas do Instagram e da
  Vinted registadas à mão não tiveram a opção de recusar que a lei exige, e os
  termos da API da Etsy proíbem usar os compradores para marketing.
- Moradas múltiplas, listas de desejos, pontos de fidelidade.

## Anti-objetivos

Falha, mesmo que funcione, se:

- **enfraquecer o login da equipa.** O login de hoje (Fortify, 2 passos, passkeys,
  escondido pelo `EnsureLoginGate`) fica como está. Uma conta da equipa nunca
  entra pelo link de cliente, porque isso saltava os 2 passos;
- **mostrar a alguém dados de outra pessoa.** As encomendas só aparecem numa conta
  depois de o dono do email provar que o é, ao abrir um link;
- **revelar se um email tem conta ou compras.** O formulário de entrada responde
  sempre o mesmo;
- **chatear.** Nada de emails a quem recusou, a quem já voltou a comprar, ou a
  quem ainda tem um código destes por usar;
- **sair marketing sem base legal.** As ofertas só vão para quem comprou na loja
  online e teve a opção de recusar.

## Base legal das ofertas

A Lei 41/2004, das comunicações eletrónicas, deixa enviar marketing de produtos
análogos a quem já é cliente, sem consentimento prévio, se o email foi recolhido
numa venda e se o cliente pode recusar, de forma gratuita e fácil, em dois
momentos: quando deixa o email e em cada mensagem. Daqui vêm três regras:

- **No checkout**, uma caixa desmarcada: "Não quero receber ofertas da 12studio
  por email". Marcar a caixa é recusar.
- **Cada email de oferta** traz um link para deixar de receber, que funciona com
  um clique e sem sessão iniciada, e o cabeçalho `List-Unsubscribe` com
  `List-Unsubscribe-Post`.
- **Quem só criou conta**, sem nunca ter comprado, não recebe ofertas. Recebe
  apenas o código de boas-vindas que a montra promete. Esse email é a resposta a
  um pedido do próprio, não marketing.
- **Só conta uma compra feita no checkout depois de a caixa existir.** A escolha
  da caixa fica guardada no `checkout` que deu origem à encomenda. Uma encomenda
  sem esse registo nunca dá direito a oferta, mesmo que tenha
  `sales_channel = website`. Ficam de fora:
  - as encomendas feitas à mão no backoffice, incluindo as pagas por link
    (`/pagar`);
  - as que nasceram de uma conversa;
  - as compras da loja anteriores a esta funcionalidade.

  Assim, ligar a funcionalidade não manda de uma vez um email a todos os
  compradores antigos.

A política de privacidade (`docs/paginas-legais-rascunho.md`) tem de passar a
falar das contas, das ofertas por email e da forma de as recusar antes de estas
ligarem em produção.

## Como funciona, do lado do cliente

### Entrar

```
Entrar na tua conta
Enviamos-te um link para entrares, sem password.
[ ana@exemplo.pt        ] [Enviar link]
→ "Se este email puder entrar, vais receber um link em breve. Vale 20 minutos."
```

- **O link:**
  - é de uso único e vale 20 minutos;
  - abre a conta no browser onde for aberto, que pode não ser o mesmo onde foi
    pedido;
  - entra com "lembrar-me" de 60 dias, a mesma vida do cookie do carrinho. Quem
    volta passados uns meses costuma já estar dentro.
- **A primeira vez que um email entra é o registo.** Se ainda não houver
  utilizador com esse email, nasce um cliente. Se houver, é esse que entra: um
  cliente criado no backoffice ou nascido de uma compra.
- **Emails da equipa** (`is_admin` ou `staff_role`) nunca entram por aqui: não
  sai link nenhum, e a resposta no ecrã é a mesma de sempre.
- **Contas desativadas** também não entram.
- **Abuso:** há um limite de pedidos por email e por IP.

### Criar conta logo a seguir a pagar

Na página de regresso da compra (`/compra/{token}`), quando o estado já é "paga"
ou "à espera do pagamento":

```
Guarda os teus dados para a próxima
Enviamos um link para ana@exemplo.pt. Ao abri-lo, ficas com a conta criada.
[Enviar link]
```

A conta só nasce quando o link é aberto, porque é aí que o email fica provado. O
botão não aparece a quem já tem sessão iniciada.

### A conta (`/conta`)

- **Encomendas.** Número, data, total, estado e seguimento (quando houver). Cada
  encomenda tem uma página com as peças e a morada de entrega.
  - **Que encomendas aparecem:** as que têm `orders.user_id` igual ao da conta, e
    só essas.
  - **Quando o email é provado**, as encomendas com esse email (sem distinguir
    maiúsculas) e com `user_id` vazio passam para a conta. Nunca se tira uma
    encomenda que já está ligada a outro cliente. Uma ligação feita à mão no
    backoffice vale como está, mesmo que o email da encomenda seja outro: foi
    a equipa que a fez.
  - **As da Etsy** não guardam email e não entram.
- **"Comprar outra vez"** em cada encomenda. Põe no carrinho as mesmas variantes,
  com as mesmas quantidades e personalizações, pelo `CartService::add`, com a
  regra do `Buyable`. O que já não se vende fica de fora, e o cliente vê uma
  mensagem a dizer o quê. Depois abre o carrinho.
- **Os meus dados.** Nome, telemóvel, NIF e morada (uma, a `addresses` com
  `is_default`, que já existe). O email não se muda aqui: mudar o email é mudar
  de conta.
- **Ofertas por email.** Um interruptor ligado/desligado, que mostra o mesmo
  estado que o link de "deixar de receber" e que a caixa do checkout.
- **Sair.**

As páginas da conta seguem as quatro línguas da montra (`lang/*/store.php`) e o
tema dela.

### O checkout com sessão iniciada

- **Dados já preenchidos.** O nome, o telemóvel, o NIF e a morada vêm da conta.
  O email vem da conta e não se edita; para usar outro, o cliente sai da conta.
- **Ao pagar, a morada usada passa a ser a guardada.** Escreve-se quando a
  encomenda nasce, no webhook, e não ao carregar em "Pagar".
- **A caixa de recusar ofertas aparece já marcada** se o cliente as tiver
  desligado.

### As ofertas automáticas

- **Gatilho.** Para cada encomenda nascida do checkout da loja depois desta
  funcionalidade (ver "Base legal"), paga e enviada, passados **21 dias** sobre o
  `shipped_at`, sai um email de
  agradecimento com um **código pessoal**. Conta-se do envio e não da entrega,
  porque o "entregue" depende de alguém o marcar e o `shipped_at` está sempre
  lá.
- **O código, por omissão:**
  - 10 %, de uso único;
  - pessoal: só funciona com aquele email, pelo `PromoCodeValidator`;
  - válido 45 dias;
  - sem valor mínimo;
  - de um canal novo, distinto do `welcome`.
- **Lembrete.** 7 dias antes de o código expirar, se ainda não foi usado.
- **Não sai** se:
  - o email recusou ofertas (no checkout, na conta ou pelo link do email);
  - o cliente já fez outra encomenda paga depois daquela;
  - há um código destes ativo e por usar para esse email;
  - a encomenda foi cancelada ou reembolsada;
  - a funcionalidade está desligada.
- **Uma vez por encomenda.** Nenhuma encomenda dispara o email duas vezes, mesmo
  com o agendador a correr de novo.
- **Definições.** Ligar/desligar (desligado à nascença), percentagem, dias até ao
  envio, validade e dias do lembrete ficam em Definições > Montra, no padrão do
  `StoreSettings` / `SettingService`. Os valores de cima são as omissões.

### Os 5 € de boas-vindas

Ficam como já estão feitos (`SendWelcomePromoCode`, `IssueWelcomePromoCode`,
`config/shop.php` `welcome_code`), e a faixa da montra já os promete. Mudam duas
coisas:

- **O que dispara o código.** Passa a ser a primeira prova do email: o momento em
  que o `email_verified_at` passa de vazio a preenchido, ao abrir o link. Vale
  para um cliente novo e também para quem já existia sem email provado (criado
  no backoffice, ou nascido de um Multibanco que nunca foi pago). Uma compra ou
  um cliente criado no backoffice não disparam nada.
- **Quem o recebe.** Quem já tem uma encomenda paga com aquele email não recebe
  os 5 €: esse cliente já está coberto pelas ofertas para voltar.

## Peças do lado do servidor (decisões tomadas)

- **Guarda própria.** `customer`, com o driver `session` e o provider `users`,
  separada da `web`:
  - uma sessão de cliente nunca satisfaz o `EnsureAdmin` nem o resto do
    backoffice, nem por engano;
  - quem é da equipa pode estar dentro do backoffice e, no mesmo browser,
    experimentar uma conta de cliente. Isto é o que torna possível testar com as
    compras em "Só para a equipa".
- **Os clientes são `users`**, como já são no backoffice (`User::customers()`),
  sem password utilizável (`Str::password(32)` com hash, como o
  `CustomerService`).
- **Toda a compra paga na loja online liga-se a um cliente.** Se já houver um
  utilizador com aquele email que não seja da equipa, liga-se a esse. Se não
  houver, nasce um cliente sem `email_verified_at`.
  - **Assim fica guardada a recusa de ofertas** de quem não tem conta.
  - **E o backoffice passa a ver todos os compradores** em Clientes.
  - **Riscos aceites:** alguém pode escrever no checkout o email de outra pessoa;
    a encomenda fica ligada a esse cliente, mas os dados dela são os de quem
    comprou, e só o dono do email a vê depois de provar que o é.
  - **Compras com o email de alguém da equipa** não se ligam a ninguém.
  - **Um checkout sem sessão nunca muda um cliente que já existe.** Não toca no
    nome, no telemóvel, no NIF nem na morada. Pode acrescentar uma recusa de
    ofertas, mas nunca apagar uma: deixar a caixa desmarcada não volta a ligar as
    ofertas. Só um cliente novo nasce com os dados da compra.
- **Emails sem distinguir maiúsculas.** Hoje o `CustomerService` guarda o email
  como foi escrito, e o índice único do SQLite distingue maiúsculas. A partir
  daqui, guardar e procurar fazem-se em minúsculas. Os clientes que já existem
  procuram-se com `lower(email)`, e quem constrói decide o que fazer quando
  aparecem dois (por exemplo, não ligar e avisar).
- **O login da equipa deixa de aceitar clientes.** Hoje qualquer utilizador com
  email consegue pôr uma password pelo "recuperar password" e entrar pela guarda
  `web`, sem 2 passos (`FortifyServiceProvider` `authenticateUsing`; o
  comentário do `CustomerService` conta com isso). Com todos os compradores a
  passarem a utilizadores, essa porta fica grande demais. O login e a
  recuperação de password do Fortify passam a aceitar só contas da equipa
  (`is_admin` ou `staff_role`). Para quem é da equipa nada muda, e o login fica
  mais fechado, não mais fraco.
- **Email provado = `email_verified_at`.** É preenchido quando o link é aberto. É
  nesse momento, e só nele, que as encomendas antigas do email (as que ainda
  têm `user_id` nulo) passam para a conta.
- **A recusa de ofertas** é um campo novo nos `users`
  (`marketing_opt_out_at`, nulo = pode receber). O link de deixar de receber é um
  URL assinado, sem sessão.
- **Os envios de ofertas** ficam registados por encomenda, para não haver
  duplicados e para o lembrete saber que código acompanhar. A forma (colunas na
  encomenda ou tabela própria) fica a quem constrói.
- **Migrações só aditivas.** A BD de produção tem dados.
- **Tudo atrás do `ShopGate`, menos o "deixar de receber".** A conta e a entrada
  por link aparecem quando a loja deixa comprar (desligada / só para a equipa /
  ligada). Desligada a loja, `/conta` e `/entrar` dão 404 e o agendador não
  envia ofertas.
  - **A porta usa sempre a guarda `web`.** A decisão faz-se com
    `Auth::guard('web')->user()`, e não com o `$request->user()`: o
    `EnsureShopOpen` usa a guarda por omissão, e um `auth:customer` trocava-a
    pela do cliente.
  - **O link de deixar de receber funciona sempre,** com a loja como estiver.
    Fica fora da porta, como o `/compra/{token}`.
- **Rotas com prefixo de idioma**, como o resto da montra.
- **Fora da indexação.** `/conta` e `/entrar` levam `noindex` e
  `Referrer-Policy: no-referrer`.
- **O link de entrada nunca vai para os registos de acesso nem para o
  Discord.** Os avisos de erro do Discord levam o URI do pedido
  (`SystemAlerts`). Ou o token fica fora do URI, ou esse campo esconde-o antes de
  sair. O mesmo vale para o link de deixar de receber.
- **Discord.** Uma conta nova pode avisar em #conversas, só com "conta nova" e o
  link para o cliente no backoffice, sem nome nem email (regra de
  `fa15b66`). Fica a quem constrói se vale a pena.
- **O que fica na página de compra.** A página de regresso da compra continua a
  servir só para confirmar a compra. O acompanhamento das encomendas passa a ser
  na conta.

## O cabeçalho da montra

Um ícone de pessoa ao lado do carrinho:

- **Sem sessão** leva a `/entrar`.
- **Com sessão** leva a `/conta`.

Segue as mesmas regras de visibilidade do carrinho (`ShopGate`).

## Abordagens consideradas

| | Como se entra | Escolha |
|---|---|---|
| **A** | Link de uso único enviado por email, sem password | **Escolhida pelo dono** |
| B | Email e password (Fortify registration, o plano original da Fase 5) | Rejeitada |
| C | As duas | Rejeitada |

- **Porque o A.** Quem volta passados meses perde-se na password esquecida, e o A
  tira esse atrito exatamente a quem se quer trazer de volta. Não toca no login
  da equipa, e a prova do email vem incluída, o que permite ligar as compras
  antigas com segurança.
- **O que custa o A.** Cada entrada depende de o email chegar. O serviço de email
  de produção tem de estar bem configurado (SPF, DKIM), mas as confirmações de
  compra já dependem dele.
- **Porque não o B.** Precisa de mais páginas, e quase todos os regressos acabam
  em "recuperar password", ou seja, num link por email na mesma. Também obriga a
  abrir ao público o login da equipa ou a construir um segundo login.
- **Porque não o C.** Custa o dobro e não serve melhor o objetivo.

## Decisões

**Do dono:**

- Objetivo: quem já comprou volta a comprar.
- O motivo para voltar é um lembrete ou uma oferta da loja.
- Emails automáticos, e não campanhas escritas por ele.
- Entrada por link no email (abordagem A).
- Aprovou as regras das ofertas tal como foram apresentadas: 21 dias depois do
  envio, 10 %, 45 dias de validade, lembrete 7 dias antes; não sai a quem já
  voltou, a quem tem um código por usar nem a quem recusou; só compras da loja
  online; o código não exige conta; emails em português; os 5 € de boas-vindas
  mantêm-se.

**Minhas, para o dono corrigir:**

- Os valores das ofertas são omissões editáveis nas Definições. A funcionalidade
  nasce desligada.
- A caixa do checkout recusa quando marcada e vem desmarcada.
- O link de entrada vale 20 minutos, é de uso único e entra com 60 dias de
  "lembrar-me".
- Guarda `customer` separada da `web`.
- Toda a compra da loja online liga-se a um cliente, novo ou existente, exceto
  quando o email é da equipa. Um checkout sem sessão nunca muda os dados de um
  cliente que já existe e só pode acrescentar uma recusa de ofertas.
- As compras antigas passam para a conta quando o email é provado, e só então.
  Só passam as que não estão ligadas a ninguém. A conta mostra o que estiver
  ligado a ela.
- As ofertas só valem para encomendas nascidas do checkout depois desta
  funcionalidade. As anteriores e as do backoffice nunca as recebem.
- Os 5 € de boas-vindas são para a primeira prova do email de quem não tem
  nenhuma encomenda paga.
- O login e a recuperação de password da equipa passam a recusar clientes.
- Os emails comparam-se sem distinguir maiúsculas.
- Uma só morada guardada: a do último checkout com sessão, editável na conta.
- O email da conta não se muda na conta.
- Tudo atrás do `ShopGate`.

## Deixado a quem constrói

- Os nomes das classes, das rotas e das colunas, e a forma de guardar os tokens
  de entrada. A regra: só o hash na BD, uso único, expiração.
- Os limites exatos de pedidos de link, por email e por IP.
- A forma de registar os envios de ofertas, com a regra "uma vez por encomenda".
- O desenho visual da página de entrada, da conta e dos emails, dentro do tema da
  montra e das regras de contraste (sem cores fixas de Tailwind).
- O texto dos emails e das páginas, em português, com as traduções da conta em
  es/en/fr.
- O aviso de conta nova no Discord: se existe ou não.
