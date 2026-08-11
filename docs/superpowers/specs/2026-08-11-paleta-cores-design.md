# Paleta de cores "The Twelve Studio"

Data: 2026-08-11

## Objetivo

Substituir o cinzento neutro herdado do Laravel starter kit pela paleta da marca,
em todo o site — páginas públicas e backoffice, modo claro e escuro.

## Contexto

O projeto usa Tailwind v4 em configuração CSS-first: não existe `tailwind.config.js`.
Todas as cores nascem de variáveis CSS em `resources/css/app.css`, expostas ao Tailwind
pelo bloco `@theme` (`--color-primary: var(--primary)`), no padrão do shadcn/ui.

As páginas usam tokens semânticos (`bg-background`, `text-muted-foreground`), pelo que
trocar as variáveis propaga para quase todo o lado. As exceções são 41 cores fixas
espalhadas por 25 ficheiros, listadas na secção "Cores fixas".

## Decisões

1. **Modo escuro derivado.** A paleta cobre só o tema claro. Mantém-se o seletor
   claro/escuro/sistema e cria-se um tema escuro na mesma família quente, em vez de
   remover a funcionalidade ou deixar o cinzento neutro.
2. **Dourado em detalhe fino.** `#C6A77B` fica em anéis de foco, sublinhados, marcas
   pequenas e badges. Os botões principais mantêm-se `#332F2B`, como na paleta.
3. **Cores de estado numa família quente.** Os `red`/`green`/`amber` do Tailwind são
   frios e saturados demais para o bege; substituem-se por terracota, verde-oliva e
   âmbar tostado.
4. **Hex, não `oklch`.** O ficheiro atual usa `oklch` por herança do starter kit.
   Converter introduz arredondamento e torna o ficheiro impossível de comparar com a
   paleta de origem. O Tailwind v4 resolve `bg-primary/50` com hex via `color-mix`.

## Desvios face à paleta original

Sete valores foram ajustados por contraste. Os três primeiros vêm da primeira ronda de
medições; os quatro últimos só apareceram na revisão final. Cada ajuste está medido na
secção "Verificação de contraste".

| Original | Ajustado | Motivo |
|---|---|---|
| `--button-primary-hover: #A99582` | `#7F7061` | `#FAF8F5` sobre `#A99582` dá 2.71:1; falha AA (mín. 4.5:1). O taupe escurecido mantém o gesto de aclarar no hover e passa a 4.51:1. |
| Aviso âmbar `#9A6B1F` | `#97691E` | Estava a 4.41:1, imediatamente abaixo do mínimo. |
| Anel de foco dourado `#C6A77B` | `#A68C67` (só no claro) | `#C6A77B` sobre `#FAF8F5` dá 2.15:1, abaixo dos 3:1 exigidos a um indicador de foco. O `--gold` decorativo mantém-se `#C6A77B`. No modo escuro o dourado original dá 7.27:1 e fica inalterado. |
| `--muted-foreground` (desvio novo) `#756D65` | `#6A6259` | O cliente deu `#756D65` como "Texto secundário" e a spec original adoptou-o sem ajuste. Sobre `--background` (`#FAF8F5`) dá 4.79:1 e passa, mas o texto de apoio também assenta em `--muted` (`#F1ECE5`) — avatares, abas de aparência — onde cai para 4.32:1 e falha. O valor novo dá 5.10:1 sobre `--muted`, 5.65:1 sobre `--background` e 5.99:1 sobre `--card`. |
| `--ring` `#A68C67` | `#8B7556` | O `#A68C67` (linha acima) só tinha sido medido contra o fundo da página (3.02:1). Sobre `--muted`/`--secondary`/`--sidebar` (`#F1ECE5`) dava 2.72:1, e sobre `--sidebar-accent` (`#DDD6CD`) 2.22:1 — abaixo dos 3:1 da SC 1.4.11. O valor novo dá 4.15:1 sobre o fundo, 4.40:1 sobre `--card`, 3.74:1 sobre `--muted` e 3.05:1 sobre `--sidebar-accent`. |
| `--sidebar-ring` `#A68C67` | `#8B7556` | Mesma razão que `--ring`, do qual esta variável é a cópia usada na barra lateral. |
| `--destructive` (`.dark`) `#D4705E` | `#DC7B69` | Sobre `--card` (`#2A2624`) dava 4.48:1, e o `input-error` renderiza dentro de cartões e diálogos, não só sobre o fundo. O valor novo dá 5.05:1 sobre o cartão e 5.59:1 sobre o fundo. |

As quatro últimas linhas existem porque a primeira ronda mediu cada cor só contra o
fundo da página, não contra todas as superfícies onde essa cor pode assentar: texto de
apoio também aparece sobre `--muted`, anéis de foco também aparecem sobre
`--secondary`/`--sidebar`/`--sidebar-accent`, e cores de erro também aparecem sobre
`--card`. A revisão final testou essas superfícies e foi aí que surgiram as quatro
falhas — é a lição a levar deste trabalho, mais do que os números em si.

`#A99582` continua a existir como `--brand-taupe`, para usos decorativos e onde leva
texto escuro por cima (`#332F2B` sobre `#A99582` dá 4.62:1, passa).

## Tokens — modo claro

```css
:root {
    --background: #FAF8F5;
    --foreground: #332F2B;

    --card: #FFFFFF;
    --card-foreground: #332F2B;
    --popover: #FFFFFF;
    --popover-foreground: #332F2B;

    --primary: #332F2B;
    --primary-foreground: #FAF8F5;
    --primary-hover: #7F7061;

    --secondary: #F1ECE5;
    --secondary-foreground: #332F2B;
    --secondary-hover: #DDD6CD;

    --muted: #F1ECE5;
    --muted-foreground: #6A6259;

    --accent: #F1ECE5;
    --accent-foreground: #332F2B;

    --border: #DDD6CD;
    --input: #DDD6CD;
    --ring: #8B7556;

    --gold: #C6A77B;
    --brand-taupe: #A99582;

    --destructive: #A63D2E;
    --destructive-foreground: #FAF8F5;
    --destructive-soft: #F7E7E2;
    --destructive-soft-foreground: #7A2C21;

    --success: #4F6B45;
    --success-foreground: #FAF8F5;
    --success-soft: #E8EEE3;
    --success-soft-foreground: #3B5133;

    --warning: #97691E;
    --warning-foreground: #FAF8F5;
    --warning-soft: #F6EBD6;
    --warning-soft-foreground: #6E4C15;

    --info: #3B5560;
    --info-foreground: #FAF8F5;
    --info-soft: #E3EBED;
    --info-soft-foreground: #2F454E;

    --chart-1: #C6A77B;
    --chart-2: #A99582;
    --chart-3: #756D65;
    --chart-4: #332F2B;
    --chart-5: #8C7A66;

    --sidebar: #F1ECE5;
    --sidebar-foreground: #332F2B;
    --sidebar-primary: #332F2B;
    --sidebar-primary-foreground: #FAF8F5;
    --sidebar-accent: #DDD6CD;
    --sidebar-accent-foreground: #332F2B;
    --sidebar-border: #DDD6CD;
    --sidebar-ring: #8B7556;

    --radius: 0.625rem;
}
```

Notas de mapeamento onde o nome do token engana:

- `--secondary` é `#F1ECE5`, não `#A99582`. No shadcn, `--secondary` é o fundo do botão
  secundário; a paleta define `button-secondary-bg: #F1ECE5`.
- `--accent` é `#F1ECE5`, não o dourado. No shadcn, `--accent` é o fundo subtil de hover
  de menus e dropdowns. Pôr dourado aqui pintaria cada item de menu em hover.
- `--card` e `--popover` usam `#FFFFFF` para se lerem como superfícies elevadas sobre o
  fundo bege.

## Tokens — modo escuro

Derivado, não fornecido pela paleta. O fundo é `#211E1C`, mais escuro que o `#332F2B` da
paleta, para que os cartões possam ser `#2A2624` e se lerem como elevação.

```css
.dark {
    --background: #211E1C;
    --foreground: #FAF8F5;

    --card: #2A2624;
    --card-foreground: #FAF8F5;
    --popover: #2A2624;
    --popover-foreground: #FAF8F5;

    --primary: #FAF8F5;
    --primary-foreground: #332F2B;
    --primary-hover: #DDD6CD;

    --secondary: #332F2B;
    --secondary-foreground: #FAF8F5;
    --secondary-hover: #413B36;

    --muted: #332F2B;
    --muted-foreground: #A99582;

    --accent: #332F2B;
    --accent-foreground: #FAF8F5;

    --border: #413B36;
    --input: #413B36;
    --ring: #C6A77B;

    --gold: #C6A77B;
    --brand-taupe: #A99582;

    --destructive: #DC7B69;
    --destructive-foreground: #211E1C;
    --destructive-soft: #3A2320;
    --destructive-soft-foreground: #E6A99C;

    --success: #8FAE7F;
    --success-foreground: #211E1C;
    --success-soft: #26301F;
    --success-soft-foreground: #A9C599;

    --warning: #D9A84E;
    --warning-foreground: #211E1C;
    --warning-soft: #38290F;
    --warning-soft-foreground: #E3BE73;

    --info: #9FC0C9;
    --info-foreground: #211E1C;
    --info-soft: #1F2E33;
    --info-soft-foreground: #A9C9D2;

    --chart-1: #C6A77B;
    --chart-2: #A99582;
    --chart-3: #8C8179;
    --chart-4: #DDD6CD;
    --chart-5: #6E6259;

    --sidebar: #2A2624;
    --sidebar-foreground: #FAF8F5;
    --sidebar-primary: #FAF8F5;
    --sidebar-primary-foreground: #332F2B;
    --sidebar-accent: #413B36;
    --sidebar-accent-foreground: #FAF8F5;
    --sidebar-border: #413B36;
    --sidebar-ring: #C6A77B;
}
```

No modo escuro, `--muted-foreground` é `#A99582` e não `#756D65`: o cinzento-quente da
paleta afundava-se contra `#211E1C` (2.0:1), o taupe dá 5.77:1.

## Entradas novas no bloco `@theme`

Necessárias para o Tailwind gerar as utilidades correspondentes aos tokens novos:

```css
--color-primary-hover: var(--primary-hover);
--color-secondary-hover: var(--secondary-hover);
--color-gold: var(--gold);
--color-brand-taupe: var(--brand-taupe);
--color-success: var(--success);
--color-success-foreground: var(--success-foreground);
--color-success-soft: var(--success-soft);
--color-success-soft-foreground: var(--success-soft-foreground);
--color-warning: var(--warning);
--color-warning-foreground: var(--warning-foreground);
--color-warning-soft: var(--warning-soft);
--color-warning-soft-foreground: var(--warning-soft-foreground);
--color-destructive-soft: var(--destructive-soft);
--color-destructive-soft-foreground: var(--destructive-soft-foreground);
--color-info: var(--info);
--color-info-foreground: var(--info-foreground);
--color-info-soft: var(--info-soft);
--color-info-soft-foreground: var(--info-soft-foreground);
```

## Cores fixas a converter

### Bloqueadores

- `resources/views/app.blade.php:23-31` — o `<style>` inline fixa
  `html { background-color: oklch(1 0 0) }` e `html.dark { oklch(0.145 0 0) }`. Existe
  para o fundo aparecer antes do CSS do Vite carregar. Sem alterar, cada carregamento
  começa com um flash branco antes de saltar para `#FAF8F5`. Passa a `#FAF8F5` e
  `#211E1C`.
- `resources/js/app.tsx:43` — barra de progresso do Inertia em `#4B5563`, cinzento
  azulado. Passa a `#332F2B`.

### Marca

| Ficheiro | Linhas | O que muda |
|---|---|---|
| `resources/js/components/app-header.tsx` | 64, 96, 171, 224, 241 | `neutral-*`, `black`, `white` → `foreground`, `accent`, `muted-foreground` |
| `resources/js/components/appearance-tabs.tsx` | 23, 35, 36 | `neutral-100/200/700/800` → `muted`, `background`, `accent` |
| `resources/js/layouts/auth/auth-split-layout.tsx` | 15, 16, 21, 31 | `bg-zinc-900` → `bg-primary`; `text-white` → `text-primary-foreground` |
| `resources/js/layouts/auth/auth-card-layout.tsx` | 30 | `text-black dark:text-white` → `text-foreground` |
| `resources/js/layouts/auth/auth-simple-layout.tsx` | 21 | `dark:text-white` → `text-foreground` |
| `resources/js/components/app-logo.tsx` | 11 | `text-white dark:text-black` → `text-primary-foreground` |
| `resources/js/components/nav-footer.tsx` | 30 | `neutral-*` → `muted-foreground` / `hover:text-foreground` |
| `resources/js/components/user-info.tsx` | 18 | `bg-neutral-200 text-black` → `bg-muted text-foreground` |
| `resources/js/pages/dashboard.tsx` | 12, 15, 18, 22 | `stroke-neutral-900/20` → `stroke-border` |
| `resources/js/components/text-link.tsx` | 15 | `decoration-neutral-300 dark:decoration-neutral-500` → `decoration-gold`. É aqui que o dourado entra como sublinhado, conforme a decisão 2. |
| `resources/js/pages/settings/profile.tsx` | 96 | mesmo padrão de sublinhado que `text-link.tsx` |
| `resources/js/pages/auth/two-factor-challenge.tsx` | 119 | mesmo padrão de sublinhado que `text-link.tsx` |
| `resources/js/components/two-factor-setup-modal.tsx` | 80 | `bg-white` mantém-se: é o fundo do QR code, precisa de branco puro para leitura |
| `resources/js/components/ui/dialog.tsx` | 39 | `bg-black/80` → `bg-primary/70`, véu quente |
| `resources/js/components/ui/sheet.tsx` | 37 | idem |

### Estado

| Ficheiro | Linhas | O que muda |
|---|---|---|
| `resources/js/components/admin/status-badge.tsx` | 8, 10, 12, 13 | `sky/amber/emerald/red-*` → `info-soft`, `warning-soft`, `success-soft`, `destructive-soft` |
| `resources/js/components/input-error.tsx` | 12 | `text-red-600 dark:text-red-400` → `text-destructive` |
| `resources/js/components/delete-user.tsx` | 29, 30 | `red-50/100/600/700` → `destructive-soft` e `destructive-soft-foreground` |
| `resources/js/pages/auth/login.tsx` | 98 | `text-green-600` → `text-success` |
| `resources/js/pages/auth/verify-email.tsx` | 15 | idem |
| `resources/js/pages/auth/forgot-password.tsx` | 18 | idem |
| `resources/js/pages/settings/profile.tsx` | 105 | idem |
| `resources/js/pages/admin/produtos/edit.tsx` | 90 | `text-amber-600` → `text-warning` |
| `resources/js/pages/admin/encomendas/index.tsx` | 73 | idem |
| `resources/js/pages/admin/encomendas/show.tsx` | 119, 162 | bloco de aviso → `warning-soft` / `warning-soft-foreground` |
| `resources/js/components/ui/badge.tsx` | 17 | `text-white` fixo → `text-destructive-foreground` |
| `resources/js/components/ui/button.tsx` | 15 | `text-white` fixo → `text-destructive-foreground` |

### Variantes de botão

`resources/js/components/ui/button.tsx` usa hoje `hover:bg-primary/90` e
`hover:bg-secondary/80`, que produzem um esbatimento em vez das cores que a paleta
define. Passam a `hover:bg-primary-hover` e `hover:bg-secondary-hover`.

## Verificação de contraste

37 pares medidos, todos a passar. Alvos: 4.5:1 para texto normal (WCAG AA), 3:1 para
indicadores de foco. As bordas (`#DDD6CD` sobre `#FAF8F5`, `#413B36` sobre `#211E1C`)
não constam: são divisórias decorativas, não portadoras de informação, e a WCAG não
lhes impõe mínimo. O sublinhado dourado dos links também não: o texto do link é
`#332F2B` a 12.52:1, e o sublinhado é pista secundária, não o único sinal de que
aquilo é um link.

Os quatro pares novos, medidos na revisão final, cobrem os dois temas: no claro,
`--muted-foreground` sobre `--muted` e `--ring`/`--sidebar-ring` sobre
`--muted`/`--sidebar`; no escuro, `--destructive` sobre `--card`.

**Modo claro** — texto principal 12.52:1 · texto de apoio 5.65:1 · apoio sobre cartão
5.99:1 · apoio sobre `--muted` 5.10:1 · botão primário 12.52:1 · botão primário em
hover 4.51:1 · botão secundário 11.29:1 · botão secundário em hover 9.21:1 · anel de
foco 4.15:1 · anel de foco sobre `--muted` 3.74:1 · anel de foco da barra lateral
sobre `--sidebar` 3.74:1 · erro 5.95:1 · sucesso 5.63:1 · aviso 4.55:1 ·
badges 7.91 / 7.37 / 6.56:1

**Modo escuro** — texto principal 15.63:1 · texto de apoio 5.77:1 · apoio sobre cartão
5.22:1 · botão primário 12.52:1 · botão primário em hover 9.21:1 · botão secundário
12.52:1 · botão secundário em hover 10.41:1 · anel de foco 7.27:1 · erro 5.59:1 ·
erro sobre `--card` 5.05:1 · sucesso 6.73:1 · aviso 7.62:1 · badges 7.30 / 7.30 / 7.96:1

**Info** (ambos os modos) — sólido 7.46:1 no claro e 8.57:1 no escuro · badge 8.34:1 no
claro e 8.00:1 no escuro

## Critérios de aceitação

1. `npm run build` e `npm run lint` passam.
2. Nenhuma classe de cor da paleta do Tailwind em `resources/js/`, verificada pelo
   regex do teste `DesignTokensTest`, que cobre os prefixos `bg`, `text`, `border`,
   `from`, `to`, `via`, `ring`, `fill`, `stroke`, `divide`, `outline`, `shadow`,
   `decoration`, `accent`, `caret` e `placeholder` contra as 22 famílias de cor do
   Tailwind mais `white` e `black`. Exceção única e documentada: o `bg-white` do QR
   code em `two-factor-setup-modal.tsx:80`.
3. Nenhum valor `oklch` em `resources/css/app.css` nem em `app.blade.php`.
4. Inspeção visual de home, coming-soon, login e um ecrã do backoffice, nos dois modos,
   com screenshots.
5. Sem flash branco no primeiro carregamento com cache fria.

## Fora de âmbito

Tipografia, espaçamentos, raios de canto e qualquer alteração de layout. Só cor.
