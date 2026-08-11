# Marca de ícone "12studio"

Data: 2026-08-11

## Objetivo

Substituir o mark do Laravel — herdado do starter kit — por uma marca derivada do
logótipo do 12studio, nos quatro sítios onde aquele ainda vivia.

## Contexto

A branch da paleta ([2026-08-11-paleta-cores-design.md](2026-08-11-paleta-cores-design.md))
trocou todas as cores do site mas deixou o `favicon.svg` com o `#FF2D20` do Laravel.
O levantamento encontrou mais três locais com o mesmo desenho:

| Ficheiro | Como estava |
|---|---|
| `public/favicon.svg` | mark do Laravel, `fill="#FF2D20"` |
| `public/favicon.ico` | 32×32, cor dominante `#FF2D20` |
| `public/apple-touch-icon.png` | mark do Laravel a vermelho |
| `resources/js/components/app-logo-icon.tsx` | **o mesmo desenho do Laravel**, sem cor própria |

O quarto é o que importa registar. O componente usa `fill-current`: não declara cor,
herda-a do tema. Quando a paleta mudou, o logótipo do Laravel passou automaticamente a
castanho `#332F2B` — ficou na paleta certa, com a forma errada. Nenhum teste de cor ou
de contraste o podia apanhar, e o mark do Laravel é feito de cubos isométricos, que numa
loja de impressão 3D não desperta suspeita. Está renderizado em 6 pontos: `app-header.tsx:95`,
`app-logo.tsx:11` e as três variantes de `layouts/auth/`.

## O logótipo de origem

O logótipo fornecido é um render 3D: o "12" construído em lâminas paralelas empilhadas,
off-white sobre fundo greige, com o wordmark TWELVE / STUDIO por baixo. Três propriedades
tornam-no impossível de usar diretamente como ícone:

1. **É raster com sombra.** A identidade não está na silhueta, está no detalhe interno e
   na oclusão. A 16px as lâminas ficam abaixo de meio pixel.
2. **É claro sobre fundo mais escuro.** O fundo do render é mais escuro que o `#FAF8F5`
   do site; colocado tal e qual numa página, ou desaparece ou aparece dentro de um quadrado.
3. **As camadas estão empilhadas em profundidade**, não na vertical. É informação 3D, sem
   equivalente literal num ícone plano.

## Decisões

1. **Derivar, não converter.** A marca é um redesenho vetorial do "12" do logótipo, não uma
   redução do render. Nenhum tratamento automático sobrevive aos 16px.

2. **Fatias horizontais, não contornos concêntricos.** As duas traduções 2D possíveis das
   camadas são contornos aninhados (mais fiel ao render) e fatias horizontais (como uma peça
   FDM saída da impressora). Os contornos precisam de ~3px cada para não fundir e morrem
   muito antes das fatias. Perde-se a profundidade, ganha-se legibilidade.

3. **Dimensionamento ótico: a silhueta é a constante, o detalhe entra quando há espaço.**
   Não existe contagem de camadas que sirva de 16px a 180px — a folga precisa de ≥1px físico
   e a 16px o mark inteiro tem 9px de altura. A prova de render mostrou 4 e 5 camadas a
   parecerem o mark partido (a folga corta o braço do "1"), e 12 camadas ilegíveis abaixo de
   ~48px mas as melhores acima disso. Daí: sólido até 32px, 12 camadas a partir de 48px.

4. **Agnóstica à polaridade.** A app pede a mesma marca nas duas direções — clara sobre
   `bg-sidebar-primary` em `app-logo.tsx:10`, escura sobre a página em `app-header.tsx:95`.
   A marca não pode depender de sombra para ter forma: é silhueta com contraforma.

5. **`apple-touch-icon` opaco e com margem.** O iOS compõe o ícone sobre fundo opaco —
   transparência aparece como preto — e arredonda os cantos. Fica em RGB sem alfa, azulejo
   `#F1ECE5`, marca a 72% da largura.

## Geometria

`viewBox` de referência `0 0 64 64`. Marca em x 4..60, y 13..51.

```
"1"  M4 13 H23 V51 H13.5 V20.5 H4 Z
"2"  M29 51 H60 V41.5 H48.5 L57.2 37.4 A15.5 15.5 0 1 0 29 28.5
     H38.5 A6 6 0 1 1 49.4 31.9 L29 41.5 Z
```

O "2" é um arco exterior de r=15.5 e um interior de r=6 centrados em (44.5, 28.5), com a
diagonal a descer para a barra de base em y 41.5..51. A diagonal fica ~8.3 de espessura
contra 9.5 do arco — o afinamento é normal em desenho de tipos.

O braço do "1" foi encurtado face à primeira versão, onde a 16px lia como "7".

**Camadas.** `n` bandas com `n-1` folgas *entre* elas, folga = 22% do passo:

```
folga = (38 / n) * 0.22
banda = (38 - (n - 1) * folga) / n
```

A primeira banda começa em y=13 e a última acaba em y=51, para que a silhueta exterior seja
idêntica à da versão sólida. Com `passo = 38/n` a última banda acabaria em 50.3 e a marca
encolheria ao ganhar camadas.

## Artefactos

| Ficheiro | Tamanhos | Variante | Cor |
|---|---|---|---|
| `public/favicon.svg` | vetor, `viewBox 0 0 64 64` | sólida | `#332F2B`, e `#FAF8F5` sob `prefers-color-scheme: dark` |
| `public/favicon.ico` | 16, 32, 48 (PNG embutido) | sólida | `#332F2B` sobre transparente |
| `public/apple-touch-icon.png` | 180×180, RGB sem alfa | 12 camadas | `#332F2B` sobre `#F1ECE5` |
| `app-logo-icon.tsx` | `viewBox 4 13 56 38` | sólida | herdada via `fill-current` |

O componente usa o recorte justo da mesma geometria: uma tela de ícone é quadrada e precisa
da margem, um logótipo em CSS não — assim enche a largura que lhe derem.

Os rasters são gerados a partir do vetor, rasterizados em Edge headless (dois fundos,
branco e preto, para resolver o alfa: `a = 1 - (Cbranco - Cpreto)`) e montados com Pillow.
O `.ico` é escrito à mão com PNG embutido: cabeçalho de 6 bytes, 16 bytes por entrada.

## Verificação

- **Frames do `.ico`:** 16, 32 e 48 presentes, alfa máximo 255.
- **Modo escuro do `favicon.svg`:** renderizado em Edge com
  `--blink-settings=preferredColorScheme`; pixel da haste dá `#332F2B` no claro e
  `#FAF8F5` no escuro. Confirmado por render, não por leitura do ficheiro.
- **Call sites**, com o CSS compilado da app e as classes reais, nos dois temas:

  | Local | Claro | Escuro |
  |---|---|---|
  | `app-header.tsx:95` | 12.52:1 | 15.63:1 |
  | `auth-simple-layout.tsx:21` | 12.52:1 | 15.63:1 |
  | `auth-split-layout.tsx:31` | 12.52:1 | 15.63:1 |
  | chip de `app-logo.tsx:10` | 11.29:1 | 14.14:1 |

- `tsc --noEmit`, `eslint` e `prettier --check` limpos.

## Limitações

- **O `apple-touch-icon` não sai do render.** O logótipo chegou como imagem no chat, sem
  ficheiro em disco. É a marca vetorial em 12 camadas sobre azulejo bege. Com o PNG em alta
  ou o ficheiro 3D, vale a pena trocar só este — é o único artefacto grande o suficiente
  para o render original funcionar.
- **O wordmark TWELVE / STUDIO não é usado.** Ilegível abaixo de ~120px de largura.
- **Nomenclatura por decidir.** O logótipo diz TWELVE STUDIO, a app diz `12studio`
  (`APP_NAME`, `coming-soon.tsx:17`). Não bloqueia nada.

## Fora do âmbito, encontrado pelo caminho

`resources/css/app.css:25` usa `@theme` e não `@theme inline`, pelo que o Tailwind emite
`.text-foreground{color:var(--color-foreground)}` com `--color-foreground` substituído uma
única vez em `:root`. Funciona porque `use-appearance.ts:51` põe a classe `dark` em
`document.documentElement` — o mesmo elemento que `:root`. Consequência: **um bloco escuro
dentro de uma página clara (`<div class="dark">`) falha silenciosamente**, herdando os
valores claros já resolvidos. Não afeta nada hoje.
