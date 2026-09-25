# Chaves de API sem validade

Data: 2026-09-25

## Objetivo

Poder criar uma chave de API para o MCP que não expira — para leitura e para escrita —
ao lado das validades de 7, 30 e 90 dias que já existem.

## Contexto

A validade de uma chave pessoal vive em dois sítios:

| Onde | Hoje | Quem o aplica |
|---|---|---|
| `expires_at` da linha em `oauth_access_tokens` | a validade escolhida (7/30/90 dias) | `EnsureMcpToken`, a cada pedido ao `/mcp` |
| `exp` do JWT | teto global de 90 dias (`personalAccessTokensExpireIn`) | o guard `auth:api` do Passport |

O `EnsureMcpToken` já trata `expires_at` nulo como "sem data", e o email de chave criada já
omite a data quando ela não existe. O `ApiKeyService::list()` filtra por
`expires_at > now()`, pelo que uma linha sem data desapareceria da página.

A validade curta era uma das compensações pela remoção do 2FA no MCP. O dono decidiu que a
opção sem validade fica disponível para leitura e para escrita. As outras compensações
mantêm-se: email a cada chave, password pedida em cada ação, "Revogar tudo" e revogação
automática quando a password, o papel ou o estado da conta mudam.

## Desenho

**Servidor**

- `ApiKeyService::NO_EXPIRY = 'never'`: o valor do formulário para "sem validade".
- `ApiKeyService::create()` aceita `?int $days`; com `null` deixa `expires_at` a nulo.
- `ApiKeyService::list()` mostra as chaves sem data além das que expiram no futuro.
- `StoreApiKeyRequest`: `days` aceita os valores de `LIFETIMES` e `NO_EXPIRY`.
- `ApiKeyController::store()` converte `NO_EXPIRY` em `null`.
- `AppServiceProvider`: o JWT das chaves pessoais passa a durar 100 anos. A validade de
  cada chave com prazo continua a ser a do `expires_at`, aplicada pelo `EnsureMcpToken`.
  Os tokens OAuth não mudam (1 hora de acesso, 30 dias de refresh).

**Página "Chaves de API"**

- O seletor "Validade" ganha "Sem validade". Com essa opção escolhida, um aviso diz que a
  chave só deixa de funcionar quando for revogada.
- A coluna "Expira" mostra "Nunca" para estas chaves.

## Testes

- Criar uma chave sem validade deixa `expires_at` a nulo, a chave aparece na lista e é
  aceite no `/mcp`.
- Uma chave de 7 dias continua recusada no `/mcp` a partir do 8.º dia, apesar do JWT longo.
- As chaves sem validade são revogadas pelo "Revogar tudo" e pela mudança de password.
- Os tokens OAuth mantêm a validade de 1 hora.
