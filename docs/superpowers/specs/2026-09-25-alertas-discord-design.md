# Alertas no Discord

Data: 2026-09-25

## Objetivo

O dono é avisado no Discord de tudo o que acontece no 12studio — e do que devia acontecer e
não aconteceu. Quatro canais, um webhook por canal.

## Decisões do dono

- **Canais:** um por família — `#encomendas`, `#stock-producao`, `#seguranca`, `#sistema`.
- **Granularidade:** tudo evento a evento, incluindo logins, escritas do MCP e cada mudança
  no quadro de produção. Exceção: as leituras do MCP chegam num resumo a cada 15 minutos
  (uma conversa do Claude faz dezenas).
- **Dados de clientes:** completos — nome, email, telefone e morada nas mensagens.
- **Sem vigilante externo.** Se a máquina cair de vez, ninguém avisa. O scheduler parado
  (o caso comum) é apanhado por dentro, pelo healthcheck do Docker.

## Arquitetura

Serviço explícito, chamado pelos serviços de domínio nos pontos certos — o padrão do
`StaffService::notify()`. Sem eventos de domínio novos.

- **Webhooks no backoffice** (Definições → Alertas, admins, password pedida outra vez):
  guardados na tabela `settings`, cifrados com a `APP_KEY`; o browser só vê o fim do URL.
  Só aceita URLs de webhooks do Discord, e cada mudança avisa o `#seguranca` no webhook
  novo e no antigo. A página tem o guia de como obter cada webhook.
- `config/alerts.php`: o `.env` (`DISCORD_WEBHOOK_*`) como alternativa — o backoffice passa
  à frente — e os limites (dias para "parada", etc.). Sem webhook = canal desligado.
- `App\Alerts\AlertChannel` (enum dos quatro canais) e `App\Alerts\DiscordMessage`
  (título, descrição, cor, campos, link — serializável).
- `App\Alerts\Alerts`: um método por evento. Monta a mensagem e despacha o job.
- `App\Jobs\SendDiscordAlert`: fila `default`, depois do commit. Respeita o `retry_after`
  do Discord (429). Se falhar de vez, fica só no log: um alerta falhado nunca gera outro.
- `alert_states`: tabela chave → último envio. Serve o verificador (não repetir o mesmo
  aviso) e a marca d'água do resumo de leituras do MCP.

**Nunca estraga o negócio.** Montar e despachar um alerta corre dentro de `try/catch` com
`report()`: uma encomenda grava-se mesmo que o alerta rebente.

**Erros 500** saem sem fila (HTTP direto, timeout curto): quando a BD é o problema, a fila
também é. Agrupados por exceção durante 10 minutos, com contagem.

## O que chega a cada canal

**#encomendas** — nova (backoffice ou Claude), pagamento registado, ajuste de total,
enviada, entregue, cancelada (com o motivo, p. ex. "pagamento falhou"), reembolsada,
avançada sem pagamento (forçada, com a nota), parada à espera de pagamento há ≥ 3 dias
(verificador, relembra 1×/dia).

**#stock-producao** — cada mudança no quadro de produção (incluindo recuos), encomenda
pronta a enviar, ajuste manual de stock (antes → depois), variante que cruza o limiar de
stock baixo, variante sem stock, bobines abaixo do mínimo (verificador), encomenda em
produção há ≥ 3 dias contados desde que entrou em produção (verificador).

**#seguranca** — login com sucesso, login falhado, bloqueio, password reposta ou mudada,
2FA ligado/desligado, passkey adicionada/removida, mudanças na equipa, chave de API criada
(as "sem validade" marcadas) ou revogada, "revogar tudo", chaves revogadas automaticamente,
OAuth aprovado/recusado, cliente OAuth registado, cada escrita pelo Claude (o que mudou),
recusas e erros do MCP, resumo das leituras do MCP (15 min), chave a expirar em ≤ 3 dias.

**#sistema** — erro 500 (agrupado), job falhado, backup das 04:00 (OK com tamanho, ou
falhou — incluindo "não criou ficheiro"), tarefa agendada falhou, scheduler parado,
deploy começou / OK / falhou / rollback (Jenkinsfile).

## Detalhes

- **Verificador** `alerts:check`, de hora a hora: encomendas paradas, bobines, chaves a
  expirar. Avisa uma vez e relembra a cada 24 h enquanto durar; quando o problema passa, a
  marca apaga-se e a próxima vez volta a avisar.
- **Resumo MCP** `alerts:mcp-reads`, a cada 15 min: leituras (`arguments` nulo, resultado
  `ok`) desde a última marca, agrupadas por chave.
- **Batimento do scheduler:** um agendamento de minuto a minuto toca num ficheiro em
  `storage/framework`. No `/up` (o Docker chama-o a cada 30 s), um listener de
  `DiagnosingHealth` avisa se o toque tem mais de 10 min — no máximo 1×/hora. Nunca faz o
  `/up` falhar.
- **Backup:** o alerta é do agendamento, não do comando (no deploy, "ainda não há BD" é
  normal). Depois de correr, confirma que nasceu um ficheiro novo.
- **Deploy:** o `post` do Jenkinsfile manda para o `#sistema` com uma credencial
  `12studio-discord-webhook-sistema`. Sem a credencial, o deploy segue sem aviso.
- **Origem:** pedidos ao `/mcp` são marcados "via Claude".

## Pôr a funcionar

1. No Discord: criar os quatro canais e um webhook em cada (o guia está na página).
2. No backoffice, Definições → Alertas: colar cada webhook, Guardar, Testar.
3. No Jenkins (opcional): credencial "Secret text" `12studio-discord-webhook-sistema` com o
   webhook do `#sistema`, para os avisos de deploy.

## Testes

- `Http::fake()` para o Discord; cada canal com webhook de teste.
- Um teste por família: a ação certa dispara a mensagem certa, no canal certo.
- Webhook vazio: nada é enviado e nada rebenta.
- Discord em baixo: a encomenda grava-se na mesma.
- 429: o job volta à fila com o `retry_after`.
- Verificador: avisa uma vez, relembra ao fim de 24 h, esquece quando resolve.
- Erros: a mesma exceção 10× dá uma mensagem.
- Batimento: ficheiro velho avisa; novo não; o `/up` responde 200 nos dois.
