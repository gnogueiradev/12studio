# Fuso horário de apresentação no backoffice

Data: 2026-08-12

## Objetivo

Mostrar todas as datas do backoffice em hora de Lisboa, mantendo o armazenamento em UTC.

## Contexto

O `config/app.php` define `'timezone' => 'UTC'` e todos os ecrãs de administração formatam
os `Carbon` sem converter — `$order->created_at?->format('Y-m-d H:i')` no
`OrderController`, `translatedFormat('l, j \d\e F')` no `DashboardController`.

Portugal continental é UTC+1 no verão (WEST) e UTC+0 no inverno. Entre as 00:00 e a 01:00
de Lisboa, no verão, o backoffice mostra a data do dia anterior.

### O problema é mais largo do que a formatação

A formatação é o sintoma visível. O sintoma silencioso é a matemática de fronteiras de
calendário, que também corre em UTC:

| Local | Operação | Efeito |
|---|---|---|
| `DashboardController:100` | `where('created_at', '>=', $now->startOfWeek())` | `startOfWeek()` sobre um instante UTC dá segunda-feira 00:00 UTC = segunda 01:00 em Lisboa. Uma encomenda feita às 00:30 de Lisboa (= 23:30 UTC de domingo) fica fora de "esta semana". |
| `DashboardController:178` | `startOfWeek()->subWeeks(11)` | Os 12 baldes do gráfico de vendas ficam todos deslocados uma hora. |
| `DashboardController:196` | `isSameMonth($now)` | O destaque do mês corrente muda na hora errada. |

Uma correção só na camada de apresentação arranja as etiquetas e deixa o `ordersThisWeek`
a contar semanas UTC. O número e a etiqueta passariam a discordar — pior do que o estado
atual, que pelo menos é consistentemente errado.

### A armadilha inversa

O *grammar* do query builder do Laravel formata um `Carbon` ligado a um `where()` usando o
fuso **do próprio objeto**. Entregar um `startOfWeek()` em hora de Lisboa a um `where()`
liga `2026-08-10 00:00:00` contra linhas gravadas em UTC — uma hora de desvio, no sentido
oposto ao do bug que se está a corrigir, e sem aviso nenhum.

Qualquer instante em hora local tem de voltar a UTC antes de tocar numa query.

## Decisões

1. **O valor vive em `config/shop.php`, fixo.** Não `env()`-driven, tal como o
   `'currency' => 'EUR'` ao lado. O `ConfigCacheSafetyTest` já proíbe `env()` fora de
   `config/`, e o fuso de apresentação de um estúdio português não muda por deploy.
   É chave de config e não literal para que os testes o possam sobrepor e provar que a
   conversão é real — um literal deixaria passar um no-op que só falha no verão.

2. **Um único ponto de leitura: `App\Support\LocalTime`.** Classe estática sem estado, no
   padrão do `Money` e do `Slug`. Absorve o `inPortuguese()` privado do
   `DashboardController`, para a justificação do `pt_PT` fixo passar a viver num sítio só.

   | Método | Papel |
   |---|---|
   | `now()` | "Agora" em hora civil de Lisboa — entrada para a matemática de calendário |
   | `at(?CarbonInterface)` | Instante guardado → Lisboa, seguro a `null` |
   | `format(?CarbonInterface, string)` | Converte e formata, seguro a `null` |
   | `formatPt(?CarbonInterface, string)` | O mesmo com locale `pt_PT` para `translatedFormat` |
   | `toUtc(CarbonInterface)` | Instante local → UTC, antes de entrar numa query |

3. **A regra em uma frase.** Matemática de calendário civil faz-se em Lisboa; tudo o que
   atravessa para um `where()` passa primeiro por `LocalTime::toUtc()`. Explícito e
   pesquisável em vez de implícito.

4. **Não se altera `config('app.timezone')`.** É a correção de uma linha mais tentadora e
   a mais perigosa: o `freshTimestamp()` do Laravel deriva de `app.timezone`, portanto as
   linhas novas passariam a ser *gravadas* em hora de Lisboa enquanto as antigas ficavam
   em UTC, sem offset guardado que as distinga. Mistura irrecuperável.

5. **Acessores nos modelos ficam de fora.** Não servem o `today()` nem o `startOfWeek()`,
   que partem de `now()` e não de um modelo, e obrigariam a repetição por modelo e por
   coluna.

6. **O frontend não muda.** O React recebe apenas strings já formatadas
   (`createdAt: string | null`, renderizado direto). Os únicos `new Date()` são
   `getFullYear()` nos rodapés. O servidor continua a ser a única fonte de datas.

## Âmbito

### Converter para apresentação (17 sítios)

`OrderPresenter` ×7 · `CustomerController` ×3 · `ProductionBoardController` ×2 ·
`OrderController` ×2 (incluindo `shortDate`) · `DashboardController` ×3

### Calcular em Lisboa, ligar em UTC (3 sítios, todos no `DashboardController`)

`ordersThisWeek` (`:100`) · os 12 baldes do `weeklySales` (`:178`) · `isSameMonth` (`:196`)

### Deliberadamente inalterados

| Local | Motivo |
|---|---|
| `subDays(30)`, `subDays(60)`, `subDays(3)`, `diffInDays` | São durações absolutas, não fronteiras de calendário. Neutras ao fuso; mexer seria ruído. |
| `orderBy('created_at')` | Ordenação é neutra ao fuso. |
| `BackupDatabaseCommand:61` — `now()->format('Ymd-His')` | É um nome de ficheiro, não apresentação. Fica em UTC para os backups já existentes continuarem comparáveis entre si. Entra na lista de exceções do teste-guarda. |

### Caso a meio

As duas chamadas `diffForHumans()` do `SecurityController` são durações relativas, logo
neutras ao fuso — não são um bug. Passam na mesma pelo `LocalTime::at()`: não custa nada e
poupa uma exceção na lista do teste-guarda que um leitor futuro teria de decifrar.

## Testes

1. **`tests/Unit/LocalTimeTest.php`** — instante de verão `2026-08-12 23:30 UTC` mostra o
   dia **13**; instante de inverno não sofre desvio (fixa o DST, e não um `+1` fixo);
   ida-e-volta do `toUtc()`; e uma sobreposição de `config()` a provar que a conversão
   acontece mesmo.
2. **`tests/Feature/Admin/DashboardTest.php`** — relógio congelado dentro da janela
   partida: a prop `today` dá o dia certo **e** uma encomenda criada nesse instante entra
   no `ordersThisWeek`. É o teste que falha com uma correção só de apresentação.
3. **`tests/Feature/Admin/OrderIndexTest.php`** — `createdAt` e `createdAtShort` mostram o
   dia de Lisboa.
4. **`tests/Unit/DisplayTimezoneGuardTest.php`** — teste-guarda de arquitetura, na estrutura
   do `ConfigCacheSafetyTest`: percorre `app/` à procura de `->format(`,
   `->translatedFormat(`, `->diffForHumans(` e `->isoFormat(` fora do `LocalTime`, e falha
   com o ficheiro em falta. Uniformizar hoje é fácil; o que é difícil é continuar uniforme
   quando o próximo ecrã for escrito.

## Riscos

- **PHPStan nível 7** vai exigir precisão nas cadeias nullable do helper, e devolver
  `CarbonImmutable` a chamadores que seguram `CarbonInterface` pede cuidado. Assinaturas
  estreitas em vez de `@phpstan-ignore`.
- **O teste-guarda pode dar falsos positivos** se aparecer um `->format(` que não seja de
  data (por exemplo um `NumberFormatter`). Hoje não existe nenhum — o
  `Money::toDecimal` usa `number_format(`, que é função e não método, logo não casa com o
  padrão. Se aparecer, entra na lista de exceções com justificação.

## Verificação

`composer ci:check` (eslint, prettier, tsc, pint, phpstan nível 7, phpunit) tem de ficar
verde.
