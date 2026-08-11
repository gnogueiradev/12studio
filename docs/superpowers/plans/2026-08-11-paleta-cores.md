# Paleta The Twelve Studio — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir o cinzento neutro do Laravel starter kit pela paleta da marca em todo o site — público e backoffice, modo claro e escuro — com um teste-guarda que impede regressões de contraste.

**Architecture:** O Tailwind v4 deste projeto é CSS-first: não há `tailwind.config.js`, e todas as cores nascem de variáveis CSS em `resources/css/app.css`, expostas ao Tailwind pelo bloco `@theme`. A implementação troca essas variáveis, acrescenta os tokens que a paleta precisa e o shadcn não tem, e depois converte as cores fixas que ignoram os tokens. Um teste PHPUnit lê o CSS, extrai os tokens e calcula os rácios de contraste WCAG — o mesmo padrão de teste-guarda de arquitetura já usado em `tests/Unit/ConfigCacheSafetyTest.php`.

**Tech Stack:** Laravel 12, Inertia + React 19, Tailwind v4, shadcn/ui, PHPUnit 12, Vite 8.

**Spec:** `docs/superpowers/specs/2026-08-11-paleta-cores-design.md`

## Global Constraints

- **Só cor.** Tipografia, espaçamentos, raios de canto e layout ficam intactos. Se um passo parece exigir mexer em estrutura, é sinal de que se percebeu mal o passo.
- **Hex maiúsculo de 6 dígitos** em `resources/css/app.css`. Nada de `oklch`, nada de `#abc`, nada de `rgb()`. O teste-guarda compara com `strtoupper`.
- **Nenhuma classe de cor da paleta do Tailwind** em `resources/js/` — nem `neutral`, `zinc`, `gray`, `slate`, `stone`, `sky`, `red`, `green`, `amber`, `emerald`, nem `white`/`black`. Exceção única: o `bg-white` do QR code em `resources/js/components/two-factor-setup-modal.tsx:80`, que precisa de branco puro para os leitores de QR funcionarem.
- **Contrastes mínimos:** 4.5:1 para texto normal, 3:1 para indicadores de foco. Os valores do spec já estão medidos e passam; qualquer alteração a um token tem de manter o teste verde.
- **Comentários em português**, a explicar o *porquê* e não o *quê*, como no resto do repositório.
- **`vendor/` e `node_modules/` nunca entram** em nenhum grep nem em nenhum commit.
- Cada tarefa acaba com `git add` explícito dos ficheiros que tocou. Nunca `git add -A`.

---

## Estrutura de ficheiros

**Criar:**
- `tests/Unit/DesignTokensTest.php` — teste-guarda único. Responsabilidade: garantir que os tokens existem com os valores certos, que os contrastes passam WCAG, e que nenhuma cor fixa do Tailwind volta a entrar. Fica em `Unit` e estende `PHPUnit\Framework\TestCase` (não `Tests\TestCase`), porque só lê ficheiros do disco e não precisa da aplicação — é o mesmo desenho do `ConfigCacheSafetyTest`.

**Modificar:**
- `resources/css/app.css` — os blocos `@theme`, `:root` e `.dark`
- `resources/views/app.blade.php` — o `<style>` inline anti-flash
- `resources/js/app.tsx` — a cor da barra de progresso do Inertia
- `resources/js/components/ui/button.tsx`, `badge.tsx`, `dialog.tsx`, `sheet.tsx` — variantes e véus
- 13 ficheiros de marca e 12 de estado, listados nas tarefas 5 e 6

---

## Task 1: Teste-guarda e tokens do modo claro

**Files:**
- Create: `tests/Unit/DesignTokensTest.php`
- Modify: `resources/css/app.css:64-98` (o bloco `:root`)

**Interfaces:**
- Consumes: nada.
- Produces: os métodos privados `projectPath(string $relative): string`, `css(): string`, `tokensIn(string $selector): array<string,string>`, `relativeLuminance(string $hex): float` e `contrast(string $foreground, string $background): float`. As tarefas 2 e 3 acrescentam testes a este mesmo ficheiro e reutilizam estes cinco métodos — não os redefinem.

- [ ] **Step 1: Escrever o teste a falhar**

Criar `tests/Unit/DesignTokensTest.php`:

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Teste-guarda de design (padrao qrcode, irmao do ConfigCacheSafetyTest).
 *
 * A paleta da marca vive em variaveis CSS, longe de qualquer codigo PHP, e
 * por isso nada a protege: um `bg-neutral-200` copiado de um exemplo do
 * shadcn passa em code review sem ninguem reparar, e so se ve meses depois
 * quando alguem nota que metade do backoffice esta cinzento-azulado. Pior
 * ainda com contraste: aclarar um tom "so um bocadinho" pode empurrar texto
 * abaixo do minimo WCAG sem sinal nenhum. Este teste le o CSS, resolve os
 * tokens e faz as contas.
 */
class DesignTokensTest extends TestCase
{
    public function test_light_theme_uses_the_brand_palette(): void
    {
        $expected = [
            'background' => '#FAF8F5',
            'foreground' => '#332F2B',
            'card' => '#FFFFFF',
            'card-foreground' => '#332F2B',
            'popover' => '#FFFFFF',
            'popover-foreground' => '#332F2B',
            'primary' => '#332F2B',
            'primary-foreground' => '#FAF8F5',
            'primary-hover' => '#7F7061',
            'secondary' => '#F1ECE5',
            'secondary-foreground' => '#332F2B',
            'secondary-hover' => '#DDD6CD',
            'muted' => '#F1ECE5',
            'muted-foreground' => '#756D65',
            'accent' => '#F1ECE5',
            'accent-foreground' => '#332F2B',
            'border' => '#DDD6CD',
            'input' => '#DDD6CD',
            'ring' => '#A68C67',
            'gold' => '#C6A77B',
            'brand-taupe' => '#A99582',
            'destructive' => '#A63D2E',
            'destructive-foreground' => '#FAF8F5',
            'destructive-soft' => '#F7E7E2',
            'destructive-soft-foreground' => '#7A2C21',
            'success' => '#4F6B45',
            'success-foreground' => '#FAF8F5',
            'success-soft' => '#E8EEE3',
            'success-soft-foreground' => '#3B5133',
            'warning' => '#97691E',
            'warning-foreground' => '#FAF8F5',
            'warning-soft' => '#F6EBD6',
            'warning-soft-foreground' => '#6E4C15',
            'info' => '#3B5560',
            'info-foreground' => '#FAF8F5',
            'info-soft' => '#E3EBED',
            'info-soft-foreground' => '#2F454E',
            'sidebar' => '#F1ECE5',
            'sidebar-foreground' => '#332F2B',
            'sidebar-primary' => '#332F2B',
            'sidebar-primary-foreground' => '#FAF8F5',
            'sidebar-accent' => '#DDD6CD',
            'sidebar-accent-foreground' => '#332F2B',
            'sidebar-border' => '#DDD6CD',
            'sidebar-ring' => '#A68C67',
        ];

        $tokens = $this->tokensIn(':root');

        foreach ($expected as $name => $value) {
            $this->assertArrayHasKey($name, $tokens, "Falta o token --{$name} no bloco :root de app.css.");
            $this->assertSame($value, $tokens[$name], "O token --{$name} devia ser {$value}.");
        }
    }

    /**
     * Pares (token do texto, token do fundo, racio minimo).
     *
     * Referem-se a tokens e nao a hexes: assim o teste le os valores reais do
     * CSS e falha se alguem mexer numa cor sem refazer as contas. Os dois
     * temas partilham a lista de proposito — o modo escuro e sujeito a
     * exatamente a mesma fasquia que o claro.
     *
     * @return array<string, array{string, string, float}>
     */
    public static function contrastPairs(): array
    {
        return [
            'texto sobre o fundo' => ['foreground', 'background', 4.5],
            'texto de apoio sobre o fundo' => ['muted-foreground', 'background', 4.5],
            'texto sobre o cartao' => ['card-foreground', 'card', 4.5],
            'botao primario' => ['primary-foreground', 'primary', 4.5],
            'botao primario em hover' => ['primary-foreground', 'primary-hover', 4.5],
            'botao secundario' => ['secondary-foreground', 'secondary', 4.5],
            'botao secundario em hover' => ['secondary-foreground', 'secondary-hover', 4.5],
            'anel de foco sobre o fundo' => ['ring', 'background', 3.0],
            'erro sobre o fundo' => ['destructive', 'background', 4.5],
            'texto sobre o erro solido' => ['destructive-foreground', 'destructive', 4.5],
            'sucesso sobre o fundo' => ['success', 'background', 4.5],
            'aviso sobre o fundo' => ['warning', 'background', 4.5],
            'info sobre o fundo' => ['info', 'background', 4.5],
            'badge de erro' => ['destructive-soft-foreground', 'destructive-soft', 4.5],
            'badge de sucesso' => ['success-soft-foreground', 'success-soft', 4.5],
            'badge de aviso' => ['warning-soft-foreground', 'warning-soft', 4.5],
            'badge de info' => ['info-soft-foreground', 'info-soft', 4.5],
        ];
    }

    #[DataProvider('contrastPairs')]
    public function test_light_theme_meets_wcag(string $foreground, string $background, float $minimum): void
    {
        $this->assertContrast(':root', $foreground, $background, $minimum);
    }

    protected function assertContrast(string $selector, string $foreground, string $background, float $minimum): void
    {
        $tokens = $this->tokensIn($selector);

        $this->assertArrayHasKey($foreground, $tokens, "Falta o token --{$foreground} em {$selector}.");
        $this->assertArrayHasKey($background, $tokens, "Falta o token --{$background} em {$selector}.");

        $ratio = $this->contrast($tokens[$foreground], $tokens[$background]);

        $this->assertGreaterThanOrEqual(
            $minimum,
            round($ratio, 2),
            sprintf(
                '--%s (%s) sobre --%s (%s) da %.2f:1, abaixo do minimo de %.1f:1.',
                $foreground,
                $tokens[$foreground],
                $background,
                $tokens[$background],
                $ratio,
                $minimum
            )
        );
    }

    /**
     * Extrai os pares token => hex de um bloco do app.css.
     *
     * @return array<string, string>
     */
    protected function tokensIn(string $selector): array
    {
        $pattern = '/^'.preg_quote($selector, '/').'\s*\{(.*?)^\}/ms';

        $this->assertMatchesRegularExpression(
            $pattern,
            $this->css(),
            "Nao encontrei o bloco {$selector} em app.css."
        );

        preg_match($pattern, $this->css(), $block);
        preg_match_all('/--([a-z0-9-]+):\s*(#[0-9A-Fa-f]{6})\s*;/', $block[1], $pairs, PREG_SET_ORDER);

        $tokens = [];

        foreach ($pairs as $pair) {
            $tokens[$pair[1]] = strtoupper($pair[2]);
        }

        return $tokens;
    }

    protected function css(): string
    {
        return file_get_contents($this->projectPath('resources/css/app.css'));
    }

    protected function projectPath(string $relative): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Luminancia relativa segundo a WCAG 2.1.
     */
    protected function relativeLuminance(string $hex): float
    {
        $channels = array_map(
            static function (string $channel): float {
                $value = hexdec($channel) / 255;

                return $value <= 0.03928
                    ? $value / 12.92
                    : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2)
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    protected function contrast(string $foreground, string $background): float
    {
        $a = $this->relativeLuminance($foreground);
        $b = $this->relativeLuminance($background);

        return $a > $b
            ? ($a + 0.05) / ($b + 0.05)
            : ($b + 0.05) / ($a + 0.05);
    }
}
```

- [ ] **Step 2: Correr o teste para confirmar que falha**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: FALHA. `test_light_theme_uses_the_brand_palette` queixa-se de `Falta o token --primary-hover no bloco :root de app.css.`

- [ ] **Step 3: Substituir o bloco `:root` em `resources/css/app.css`**

Substituir integralmente o bloco `:root { ... }` (linhas 64-98) por:

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
    /* A paleta da marca pedia #A99582 aqui, mas #FAF8F5 por cima desse taupe
       da 2.71:1 e falha o minimo WCAG de 4.5:1. Este tom escurecido mantem o
       gesto de aclarar no hover e passa a 4.51:1. */
    --primary-hover: #7F7061;

    --secondary: #F1ECE5;
    --secondary-foreground: #332F2B;
    --secondary-hover: #DDD6CD;

    --muted: #F1ECE5;
    --muted-foreground: #756D65;

    /* No shadcn, --accent e o fundo subtil de hover de menus, nao uma cor de
       destaque. O dourado da marca vive em --gold. */
    --accent: #F1ECE5;
    --accent-foreground: #332F2B;

    --border: #DDD6CD;
    --input: #DDD6CD;
    /* Dourado escurecido: o #C6A77B da marca da 2.15:1 contra o fundo e nao
       chega aos 3:1 que um indicador de foco precisa. */
    --ring: #A68C67;

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
    --sidebar-ring: #A68C67;

    --radius: 0.625rem;
}
```

- [ ] **Step 4: Correr o teste**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: PASSA. São 17 casos de `test_light_theme_meets_wcag` mais `test_light_theme_uses_the_brand_palette`. O bloco `.dark` ainda está em `oklch`, mas nenhum teste olha para ele ainda — é a Tarefa 2 que o trata.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/DesignTokensTest.php resources/css/app.css
git commit -m "Tokens do modo claro na paleta da marca, com teste-guarda de contraste"
```

---

## Task 2: Modo escuro

**Files:**
- Modify: `tests/Unit/DesignTokensTest.php`
- Modify: `resources/css/app.css` (o bloco `.dark`)

**Interfaces:**
- Consumes: `tokensIn()`, `assertContrast()`, `css()` da Tarefa 1.
- Produces: nada de novo para tarefas seguintes.

- [ ] **Step 1: Escrever o teste a falhar**

Acrescentar a `DesignTokensTest`, logo a seguir a `test_light_theme_meets_wcag`:

```php
    public function test_dark_theme_uses_the_derived_warm_palette(): void
    {
        $expected = [
            'background' => '#211E1C',
            'foreground' => '#FAF8F5',
            'card' => '#2A2624',
            'card-foreground' => '#FAF8F5',
            'popover' => '#2A2624',
            'popover-foreground' => '#FAF8F5',
            'primary' => '#FAF8F5',
            'primary-foreground' => '#332F2B',
            'primary-hover' => '#DDD6CD',
            'secondary' => '#332F2B',
            'secondary-foreground' => '#FAF8F5',
            'secondary-hover' => '#413B36',
            'muted' => '#332F2B',
            'muted-foreground' => '#A99582',
            'accent' => '#332F2B',
            'accent-foreground' => '#FAF8F5',
            'border' => '#413B36',
            'input' => '#413B36',
            'ring' => '#C6A77B',
            'gold' => '#C6A77B',
            'brand-taupe' => '#A99582',
            'destructive' => '#D4705E',
            'destructive-foreground' => '#211E1C',
            'destructive-soft' => '#3A2320',
            'destructive-soft-foreground' => '#E6A99C',
            'success' => '#8FAE7F',
            'success-foreground' => '#211E1C',
            'success-soft' => '#26301F',
            'success-soft-foreground' => '#A9C599',
            'warning' => '#D9A84E',
            'warning-foreground' => '#211E1C',
            'warning-soft' => '#38290F',
            'warning-soft-foreground' => '#E3BE73',
            'info' => '#9FC0C9',
            'info-foreground' => '#211E1C',
            'info-soft' => '#1F2E33',
            'info-soft-foreground' => '#A9C9D2',
            'sidebar' => '#2A2624',
            'sidebar-foreground' => '#FAF8F5',
            'sidebar-primary' => '#FAF8F5',
            'sidebar-primary-foreground' => '#332F2B',
            'sidebar-accent' => '#413B36',
            'sidebar-accent-foreground' => '#FAF8F5',
            'sidebar-border' => '#413B36',
            'sidebar-ring' => '#C6A77B',
        ];

        $tokens = $this->tokensIn('.dark');

        foreach ($expected as $name => $value) {
            $this->assertArrayHasKey($name, $tokens, "Falta o token --{$name} no bloco .dark de app.css.");
            $this->assertSame($value, $tokens[$name], "O token --{$name} do modo escuro devia ser {$value}.");
        }
    }

    /**
     * Reutiliza o provider da Tarefa 1. Um tema escuro nao e o claro
     * invertido — a percecao de luminosidade nao e simetrica, e ha tokens que
     * trocam de papel (o --muted-foreground e taupe no escuro, porque o
     * cinzento-quente da paleta afundava-se a 2.0:1 contra o fundo) — mas a
     * fasquia de contraste e a mesma, e por isso a lista de pares tambem.
     */
    #[DataProvider('contrastPairs')]
    public function test_dark_theme_meets_wcag(string $foreground, string $background, float $minimum): void
    {
        $this->assertContrast('.dark', $foreground, $background, $minimum);
    }

    public function test_no_oklch_survives_in_the_stylesheet(): void
    {
        $this->assertStringNotContainsString(
            'oklch(',
            $this->css(),
            'app.css ainda tem cores em oklch: a paleta da marca e definida em hex.'
        );
    }
```

- [ ] **Step 2: Correr o teste para confirmar que falha**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: FALHA com `Falta o token --gold no bloco .dark de app.css.`

- [ ] **Step 3: Substituir o bloco `.dark` em `resources/css/app.css`**

Substituir integralmente o bloco `.dark { ... }` por:

```css
.dark {
    /* Mais escuro que o #332F2B da paleta de proposito: assim os cartoes
       podem ser #2A2624 e ler-se como elevacao em vez de se fundirem com o
       fundo. */
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
    /* Taupe e nao o #756D65 do modo claro: contra este fundo o cinzento-quente
       da 2.0:1 e o texto de apoio ficava ilegivel. O taupe da 5.77:1. */
    --muted-foreground: #A99582;

    --accent: #332F2B;
    --accent-foreground: #FAF8F5;

    --border: #413B36;
    --input: #413B36;
    /* Aqui o dourado da marca ja da 7.27:1 e nao precisa de ser escurecido. */
    --ring: #C6A77B;

    --gold: #C6A77B;
    --brand-taupe: #A99582;

    --destructive: #D4705E;
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

- [ ] **Step 4: Correr o teste**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: PASSA na totalidade, incluindo `test_no_oklch_survives_in_the_stylesheet`. São 36 casos de contraste (17 claros + 17 escuros) mais 3 testes de tokens.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/DesignTokensTest.php resources/css/app.css
git commit -m "Tema escuro derivado na mesma familia quente da marca"
```

---

## Task 3: Tokens no bloco `@theme` e variantes de botão

Sem esta tarefa, os tokens novos existem mas o Tailwind não gera nenhuma classe para eles: `bg-gold` e `hover:bg-primary-hover` seriam ignorados em silêncio. É o passo que torna as tarefas 5 e 6 possíveis.

**Files:**
- Modify: `tests/Unit/DesignTokensTest.php`
- Modify: `resources/css/app.css:10-62` (o bloco `@theme`)
- Modify: `resources/js/components/ui/button.tsx:13,15,19`

**Interfaces:**
- Consumes: `css()`, `projectPath()` da Tarefa 1.
- Produces: as classes utilitárias `bg-gold`, `text-gold`, `decoration-gold`, `bg-brand-taupe`, `hover:bg-primary-hover`, `hover:bg-secondary-hover`, `bg-success`, `text-success`, `bg-success-soft`, `text-success-soft-foreground`, e as equivalentes para `warning`, `info` e `destructive-soft`. As tarefas 5 e 6 usam-nas.

- [ ] **Step 1: Escrever o teste a falhar**

Acrescentar a `DesignTokensTest`:

```php
    public function test_theme_block_exposes_the_new_tokens(): void
    {
        $utilities = [
            'primary-hover',
            'secondary-hover',
            'gold',
            'brand-taupe',
            'destructive-soft',
            'destructive-soft-foreground',
            'success',
            'success-foreground',
            'success-soft',
            'success-soft-foreground',
            'warning',
            'warning-foreground',
            'warning-soft',
            'warning-soft-foreground',
            'info',
            'info-foreground',
            'info-soft',
            'info-soft-foreground',
        ];

        $pattern = '/^@theme\s*\{(.*?)^\}/ms';
        preg_match($pattern, $this->css(), $block);

        foreach ($utilities as $utility) {
            $this->assertStringContainsString(
                "--color-{$utility}: var(--{$utility});",
                $block[1] ?? '',
                "O bloco @theme nao expoe --color-{$utility}: sem isto o Tailwind ignora a classe em silencio."
            );
        }
    }

    public function test_buttons_use_the_palette_hover_colours(): void
    {
        $button = file_get_contents($this->projectPath('resources/js/components/ui/button.tsx'));

        $this->assertStringContainsString('hover:bg-primary-hover', $button);
        $this->assertStringContainsString('hover:bg-secondary-hover', $button);
        $this->assertStringNotContainsString('hover:bg-primary/90', $button);
        $this->assertStringNotContainsString('hover:bg-secondary/80', $button);
    }
```

- [ ] **Step 2: Correr o teste para confirmar que falha**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: FALHA com `O bloco @theme nao expoe --color-primary-hover`.

- [ ] **Step 3: Acrescentar as entradas ao `@theme`**

Em `resources/css/app.css`, dentro do bloco `@theme`, logo a seguir à linha `--color-ring: var(--ring);`:

```css
    --color-primary-hover: var(--primary-hover);
    --color-secondary-hover: var(--secondary-hover);

    /* Cores da marca sem slot proprio no shadcn. */
    --color-gold: var(--gold);
    --color-brand-taupe: var(--brand-taupe);

    --color-destructive-soft: var(--destructive-soft);
    --color-destructive-soft-foreground: var(--destructive-soft-foreground);

    --color-success: var(--success);
    --color-success-foreground: var(--success-foreground);
    --color-success-soft: var(--success-soft);
    --color-success-soft-foreground: var(--success-soft-foreground);

    --color-warning: var(--warning);
    --color-warning-foreground: var(--warning-foreground);
    --color-warning-soft: var(--warning-soft);
    --color-warning-soft-foreground: var(--warning-soft-foreground);

    --color-info: var(--info);
    --color-info-foreground: var(--info-foreground);
    --color-info-soft: var(--info-soft);
    --color-info-soft-foreground: var(--info-soft-foreground);
```

- [ ] **Step 4: Corrigir as variantes de botão**

Em `resources/js/components/ui/button.tsx`, três substituições exactas. Manter a indentação de 2 espaços e as aspas duplas — este ficheiro vem do shadcn e segue o estilo dele.

Linha 13, de:
```
          "bg-primary text-primary-foreground shadow-xs hover:bg-primary/90",
```
para:
```
          "bg-primary text-primary-foreground shadow-xs hover:bg-primary-hover",
```

Linha 15, de:
```
          "bg-destructive text-white shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
```
para:
```
          "bg-destructive text-destructive-foreground shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
```

Linha 19, de:
```
          "bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80",
```
para:
```
          "bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary-hover",
```

- [ ] **Step 5: Correr o teste**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: PASSA.

- [ ] **Step 6: Commit**

```bash
git add resources/css/app.css resources/js/components/ui/button.tsx tests/Unit/DesignTokensTest.php
git commit -m "Expoe os tokens novos ao Tailwind e liga os hovers da paleta aos botoes"
```

---

## Task 4: Os dois bloqueadores de arranque

O `<style>` inline do Blade existe para pintar o fundo antes de o CSS do Vite carregar. Continua em branco puro: sem esta tarefa, cada carregamento com cache fria começa com um flash branco antes de saltar para o bege. A barra de progresso do Inertia tem o mesmo problema em ponto pequeno.

**Files:**
- Modify: `tests/Unit/DesignTokensTest.php`
- Modify: `resources/views/app.blade.php:23-31`
- Modify: `resources/js/app.tsx:43`

**Interfaces:**
- Consumes: `projectPath()` da Tarefa 1.
- Produces: nada.

- [ ] **Step 1: Escrever o teste a falhar**

Acrescentar a `DesignTokensTest`:

```php
    public function test_the_anti_flash_style_matches_the_palette(): void
    {
        $blade = file_get_contents($this->projectPath('resources/views/app.blade.php'));

        $this->assertStringNotContainsString(
            'oklch(',
            $blade,
            'O <style> inline do Blade ainda pinta o fundo em oklch: com cache fria a pagina abre com um flash branco antes de saltar para o bege.'
        );
        $this->assertStringContainsString('background-color: #FAF8F5;', $blade);
        $this->assertStringContainsString('background-color: #211E1C;', $blade);
    }

    public function test_the_progress_bar_uses_the_brand_colour(): void
    {
        $app = file_get_contents($this->projectPath('resources/js/app.tsx'));

        $this->assertStringNotContainsString('#4B5563', $app, 'A barra de progresso do Inertia ainda esta no cinzento-azulado do starter kit.');
        $this->assertStringContainsString("color: '#332F2B'", $app);
    }
```

- [ ] **Step 2: Correr o teste para confirmar que falha**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: FALHA com a mensagem sobre o flash branco.

- [ ] **Step 3: Corrigir o Blade**

Em `resources/views/app.blade.php`, substituir o bloco `<style>` (linhas 22-31) por:

```blade
        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #FAF8F5;
            }

            html.dark {
                background-color: #211E1C;
            }
        </style>
```

- [ ] **Step 4: Corrigir a barra de progresso**

Em `resources/js/app.tsx:43`, substituir:

```
        color: '#4B5563',
```

por:

```
        color: '#332F2B',
```

- [ ] **Step 5: Correr o teste**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: PASSA.

- [ ] **Step 6: Commit**

```bash
git add resources/views/app.blade.php resources/js/app.tsx tests/Unit/DesignTokensTest.php
git commit -m "Tira o flash branco do arranque e poe a barra de progresso na cor da marca"
```

---

## Task 5: Cores fixas de marca

**Files:**
- Modify: `tests/Unit/DesignTokensTest.php`
- Modify: 15 ficheiros, listados no Step 3

**Interfaces:**
- Consumes: as utilidades produzidas na Tarefa 3.
- Produces: o método `tsxFiles(): array<string>` e a constante `BANNED_COLOUR_CLASSES`, que a Tarefa 6 reutiliza.

- [ ] **Step 1: Escrever o teste a falhar**

Acrescentar a `DesignTokensTest`. O regex cobre as 22 famílias de cor do Tailwind mais `white` e `black`, contra todos os prefixos de utilidade que aceitam cor:

```php
    /**
     * O QR code do 2FA precisa de branco puro: os leitores dependem do
     * contraste maximo e um fundo bege reduz a taxa de leitura. E a unica
     * excecao permitida, e por isso vive aqui e nao num comentario perdido.
     */
    private const COLOUR_EXCEPTIONS = [
        'resources/js/components/two-factor-setup-modal.tsx' => ['bg-white'],
    ];

    /**
     * As familias sao duas listas porque a limpeza foi feita em duas fases: os
     * cinzentos de marca primeiro, as cores de estado depois. Ficam separadas
     * para o proximo que quiser perceber porque e que um `bg-emerald-100` e
     * tratado de forma diferente de um `bg-neutral-100`.
     */
    private const BRAND_FAMILIES = 'neutral|zinc|gray|slate|stone|white|black';

    private const STATE_FAMILIES = 'red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    private const COLOUR_PREFIXES = 'bg|text|border|from|to|via|ring|fill|stroke|divide|outline|shadow|decoration|accent|caret|placeholder';

    public function test_no_neutral_brand_colours_remain_in_the_frontend(): void
    {
        $this->assertNoColourClasses(self::BRAND_FAMILIES);
    }

    protected function assertNoColourClasses(string $families): void
    {
        $pattern = '/\b(?:'.self::COLOUR_PREFIXES.')-(?:'.$families.')(?:-\d{2,3})?\b/';

        $offenders = [];

        foreach ($this->tsxFiles() as $relative => $absolute) {
            preg_match_all($pattern, file_get_contents($absolute), $matches);

            $allowed = self::COLOUR_EXCEPTIONS[$relative] ?? [];
            $found = array_diff(array_unique($matches[0]), $allowed);

            if ($found !== []) {
                $offenders[] = $relative.': '.implode(', ', $found);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Cores fixas da paleta do Tailwind em resources/js — use os tokens da marca:\n".implode("\n", $offenders)
        );
    }

    /**
     * @return array<string, string> caminho relativo => caminho absoluto
     */
    protected function tsxFiles(): array
    {
        $root = $this->projectPath('resources/js');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'tsx' && $file->getExtension() !== 'ts') {
                continue;
            }

            $relative = str_replace(
                [$this->projectPath(''), DIRECTORY_SEPARATOR],
                ['', '/'],
                $file->getPathname()
            );

            $files[ltrim($relative, '/')] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }
```

- [ ] **Step 2: Correr o teste para confirmar que falha**

```bash
php artisan test --filter=test_no_neutral_brand_colours_remain_in_the_frontend
```

Esperado: FALHA com exactamente **15 ficheiros** — os do Step 3. O `button.tsx` já foi tratado na Tarefa 3; se ele aparecer na lista, essa tarefa não ficou concluída. O `two-factor-setup-modal.tsx` não deve aparecer: está coberto pela excepção do QR code.

Esta contagem foi confirmada contra o código real antes de o plano ser escrito, com o regex exacto do teste. Se o número for outro, alguém mexeu no frontend entretanto — vale a pena perceber o quê antes de continuar.

- [ ] **Step 3: Converter as cores de marca**

Quinze ficheiros. Cada substituição é exacta.

Atenção a um pormenor do regex: `dark:bg-white` também conta como `bg-white`, porque a fronteira de palavra `\b` casa logo a seguir aos dois pontos. Por isso `badge.tsx` entra nesta tarefa e não na das cores de estado — apesar de a classe estar na variante `destructive`, o que o guarda vê é um `white`.

`resources/js/components/app-logo.tsx:11` — o logo herda a cor do texto do contentor, por isso `currentColor` via token:
```
text-white dark:text-black   →   text-primary-foreground
```

`resources/js/components/app-header.tsx`, cinco linhas:
```
64:  'text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100'
  →  'bg-accent text-accent-foreground'
96:  fill-current text-black dark:text-white   →   fill-current text-foreground
171: bg-black dark:bg-white                    →   bg-foreground
224: bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white
  →  bg-muted text-muted-foreground
241: text-neutral-500                          →   text-muted-foreground
```

`resources/js/components/appearance-tabs.tsx`, três linhas:
```
23:  'inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800'
  →  'inline-flex gap-1 rounded-lg bg-muted p-1'
35:  'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
  →  'bg-background text-foreground shadow-xs'
36:  'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60'
  →  'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
```

`resources/js/layouts/auth/auth-split-layout.tsx`, quatro linhas:
```
15:  bg-muted p-10 text-white   →   bg-muted p-10 text-primary-foreground
16:  bg-zinc-900                →   bg-primary
21:  fill-current text-white    →   fill-current text-primary-foreground
31:  fill-current text-black    →   fill-current text-foreground
```

`resources/js/layouts/auth/auth-card-layout.tsx:30`:
```
fill-current text-black dark:text-white   →   fill-current text-foreground
```

`resources/js/layouts/auth/auth-simple-layout.tsx:21`:
```
fill-current text-[var(--foreground)] dark:text-white   →   fill-current text-foreground
```

`resources/js/components/nav-footer.tsx:30`:
```
'text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100'
  →  'text-muted-foreground hover:text-foreground'
```

`resources/js/components/user-info.tsx:18`:
```
bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white
  →  bg-muted text-muted-foreground
```

`resources/js/pages/dashboard.tsx`, quatro linhas iguais (12, 15, 18, 22):
```
stroke-neutral-900/20 dark:stroke-neutral-100/20   →   stroke-border
```

`resources/js/components/text-link.tsx:15` — é aqui que o dourado entra, conforme a decisão 2 do spec:
```
'text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500'
  →  'text-foreground underline decoration-gold underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!'
```

`resources/js/pages/settings/profile.tsx:96` e `resources/js/pages/auth/two-factor-challenge.tsx:119` — o mesmo padrão de sublinhado, copiado à mão em vez de usar `TextLink`. Aplicar a mesma substituição do ponto anterior em ambos.

`resources/js/components/ui/dialog.tsx:39` e `resources/js/components/ui/sheet.tsx:37` — o véu do modal passa a quente:
```
bg-black/80   →   bg-primary/70
```

`resources/js/components/ui/badge.tsx:17`:
```
text-white   →   text-destructive-foreground
```

- [ ] **Step 4: Correr o teste**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: PASSA. As cores de estado (`amber`, `emerald`, `red`, `sky`) continuam lá, mas ainda não são cobertas pelo guarda — é a Tarefa 6 que alarga o regex e as apanha.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components resources/js/layouts resources/js/pages/dashboard.tsx resources/js/pages/settings/profile.tsx resources/js/pages/auth/two-factor-challenge.tsx tests/Unit/DesignTokensTest.php
git commit -m "Converte as cores de marca fixas para os tokens da paleta"
```

---

## Task 6: Cores de estado

**Files:**
- Modify: 10 ficheiros, listados no Step 3

**Interfaces:**
- Consumes: as utilidades `success`, `warning`, `info` e `*-soft` da Tarefa 3; `assertNoColourClasses()` e `STATE_FAMILIES` da Tarefa 5.
- Produces: nada.

- [ ] **Step 1: Escrever o teste a falhar**

Acrescentar a `DesignTokensTest`, a seguir a `test_no_neutral_brand_colours_remain_in_the_frontend`:

```php
    public function test_no_tailwind_state_colours_remain_in_the_frontend(): void
    {
        $this->assertNoColourClasses(self::STATE_FAMILIES);
    }
```

- [ ] **Step 2: Correr o teste para confirmar que falha**

```bash
php artisan test --filter=test_no_tailwind_state_colours_remain_in_the_frontend
```

Esperado: FALHA com exactamente **10 ficheiros**: `status-badge.tsx`, `delete-user.tsx`, `input-error.tsx`, `admin/encomendas/index.tsx`, `admin/encomendas/show.tsx`, `admin/produtos/edit.tsx`, `auth/forgot-password.tsx`, `auth/login.tsx`, `auth/verify-email.tsx` e `settings/profile.tsx`. Contagem confirmada contra o código real.

- [ ] **Step 3: Converter as cores de estado**

`resources/js/components/admin/status-badge.tsx`, linhas 7-14. Substituir o mapa `TONE_CLASSES` inteiro por:

```tsx
const TONE_CLASSES: Record<Tone, string> = {
    neutral: 'bg-muted text-muted-foreground border-transparent',
    info: 'bg-info-soft text-info-soft-foreground border-transparent',
    warning: 'bg-warning-soft text-warning-soft-foreground border-transparent',
    success: 'bg-success-soft text-success-soft-foreground border-transparent',
    danger: 'bg-destructive-soft text-destructive-soft-foreground border-transparent',
};
```

Os tokens `*-soft` já trocam de valor entre claro e escuro, por isso os sufixos `dark:` desaparecem — deixam de ser precisos.

`resources/js/components/input-error.tsx:12`:
```
'text-sm text-red-600 dark:text-red-400'   →   'text-sm text-destructive'
```

`resources/js/components/delete-user.tsx`, linhas 29 e 30:
```
29: 'space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10'
  →  'space-y-4 rounded-lg border border-destructive/30 bg-destructive-soft p-4'
30: 'relative space-y-0.5 text-red-600 dark:text-red-100'
  →  'relative space-y-0.5 text-destructive-soft-foreground'
```

Os quatro `text-green-600`, todos com a mesma substituição para `text-success`:
- `resources/js/pages/auth/login.tsx:98`
- `resources/js/pages/auth/verify-email.tsx:15`
- `resources/js/pages/auth/forgot-password.tsx:18`
- `resources/js/pages/settings/profile.tsx:105`

`resources/js/pages/admin/produtos/edit.tsx:90`:
```
'text-amber-600'   →   'text-warning'
```

`resources/js/pages/admin/encomendas/index.tsx:73`:
```
size-4 text-amber-600   →   size-4 text-warning
```

`resources/js/pages/admin/encomendas/show.tsx`, linhas 119 e 162:
```
119: 'flex items-center gap-2 rounded-xl border border-amber-500/50 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200'
  →   'flex items-center gap-2 rounded-xl border border-warning/50 bg-warning-soft p-4 text-sm text-warning-soft-foreground'
162: 'text-xs text-amber-700 dark:text-amber-400'
  →   'text-xs text-warning'
```

- [ ] **Step 4: Correr o teste**

```bash
php artisan test --filter=DesignTokensTest
```

Esperado: PASSA na totalidade. A única cor fixa que sobra em `resources/js` é o `bg-white` do QR code, coberto pela excepção.

- [ ] **Step 5: Commit**

```bash
git add resources/js tests/Unit/DesignTokensTest.php
git commit -m "Cores de estado na familia quente: terracota, oliva, ambar tostado e petroleo"
```

---

## Task 7: Verificação de ponta a ponta

**Files:** nenhum ficheiro novo. Esta tarefa só corre os portões e olha para o resultado.

**Interfaces:**
- Consumes: tudo.
- Produces: as capturas de ecrã que provam que o trabalho está feito.

- [ ] **Step 1: Correr os portões de qualidade completos**

```bash
composer ci:check
```

Isto corre `npm run lint:check`, `npm run format:check`, `npm run types:check`, e depois `pint --test`, `phpstan` e `artisan test`. Esperado: tudo verde.

Se `format:check` falhar, é o Prettier a reordenar as classes do Tailwind (o projeto usa `prettier-plugin-tailwindcss`). Corrigir com:

```bash
npm run format
```

e voltar a correr `composer ci:check`.

- [ ] **Step 2: Confirmar que o build produz CSS válido**

```bash
npm run build
```

Esperado: sucesso. Se o Tailwind não reconhecer uma classe (`bg-gold`, `text-success`), não dá erro — gera CSS sem ela. Por isso o passo seguinte é indispensável.

- [ ] **Step 3: Confirmar que as classes novas existem mesmo no CSS compilado**

```bash
grep -c "\-\-color-gold\|bg-gold\|text-success\|bg-warning-soft\|bg-info-soft" public/build/assets/*.css
```

Esperado: contagem maior que zero. Se der zero, o `@theme` não expôs os tokens ou nenhum ficheiro usa as classes — voltar à Tarefa 3.

- [ ] **Step 4: Ver as páginas no browser**

Arrancar o servidor de desenvolvimento via `preview_start` (nunca com `Bash`) e percorrer, em modo claro e escuro:

1. `/` com a loja fechada — a landing `coming-soon`
2. `/` com `STORE_OPEN=true` — a montra `home`
3. `/login` — o `auth-split-layout`, onde estava o `bg-zinc-900`
4. `/dashboard` e um ecrã de encomendas — a sidebar e os `StatusBadge`

Em cada um: confirmar que não há cinzento-azulado ao lado do bege, que o anel de foco dourado aparece ao navegar por Tab, e que os badges de estado continuam distinguíveis entre si.

- [ ] **Step 5: Confirmar que não há flash branco**

Com o DevTools aberto, desligar a cache (Network → Disable cache) e recarregar. O fundo tem de nascer bege. Se piscar branco, o Step 3 da Tarefa 4 não foi aplicado.

- [ ] **Step 6: Capturas de ecrã**

Tirar screenshot de cada uma das quatro páginas, nos dois modos, e mostrá-las. Sem estas provas o trabalho não está fechado.

- [ ] **Step 7: Commit final, se houve reformatação**

```bash
git add resources/
git commit -m "Reformatacao do Prettier depois da troca de paleta"
```

Se `git status` estiver limpo, saltar este passo.

---

## Notas para quem implementa

**O que é fácil enganar-se:**

- **`--accent` não é o dourado.** No shadcn é o fundo de hover de menus. Se puseres dourado lá, cada item de dropdown fica dourado sólido em hover. O dourado vive em `--gold`, e usa-se em `--ring`, em `decoration-gold` e pouco mais.
- **`--secondary` não é `#A99582`.** É `#F1ECE5`, o fundo do botão secundário. O `#A99582` está em `--brand-taupe`.
- **Três valores fogem à paleta de propósito.** `--primary-hover` é `#7F7061` e não `#A99582`; `--ring` é `#A68C67` e não `#C6A77B` no modo claro; o aviso é `#97691E` e não `#9A6B1F`. Todos por contraste, todos documentados no spec. Não os "corrigir" de volta.
- **Os tokens `*-soft` já mudam entre claro e escuro.** Ao converter, apagar os sufixos `dark:` em vez de os traduzir — se os mantiveres, ficas com duas fontes de verdade a discordar.
- **O Prettier reordena classes do Tailwind.** Correr `npm run format` antes de commitar poupa uma ida e volta ao `ci:check`.
