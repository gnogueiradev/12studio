# Construtor à Elementor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a página da montra passa a ser uma árvore de contentores e
elementos. O editor ganha o painel Elementos/Editar, o Navegador em árvore,
arrastar e largar, as estruturas de colunas e os valores por dispositivo. As
páginas que já existem convertem-se sozinhas e ficam iguais.

**Architecture:**
- **Servidor:**
  - o `PageDocument` converte v1 em v2 e percorre a árvore;
  - o `BlockSanitizer` e o `PageRenderer` passam a ser recursivos;
  - os estilos por dispositivo são uma lista branca de `StyleProp`.
- **Loja:** um `NodeTree` desenha os contentores com variáveis CSS e uma
  folha de estilos fixa com media queries. Os blocos "Da loja" respondem à
  largura da coluna por container queries.
- **Editor:** a árvore fica no estado. O iframe da pré-visualização desenha
  as seleções e as zonas de largar e fala com o editor por postMessage.

**Tech Stack:**
- Laravel 13.34 (preso);
- Inertia v3, React 19 e TS;
- Tailwind v4 (container queries `@container`);
- @dnd-kit (já instalado) e HTML5 DnD nativo;
- PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-28-construtor-elementor-design.md`

## Global Constraints

- **Ramo `staging`.** Commits locais, sem push e sem merge no `main` (o
  merge é o deploy).
- **PHP só pelo PowerShell (Herd).** Para correr testes:
  `php artisan test --compact <ficheiro>`. A suite completa (~11 min) corre
  em segundo plano e nunca ao mesmo tempo que o `npm run build`.
- **Antes do tsc e do ESLint:** `php artisan wayfinder:generate --with-form`.
  Portões do frontend: `npx tsc --noEmit`, `npx eslint resources/js` e
  `npx prettier --check resources/js`.
- **Dependências:** nada de `composer require/update`. No npm não entram
  pacotes novos.
- **Esquema da BD:** as colunas JSON `draft_blocks`, `published_blocks` e
  `page_revisions.blocks` continuam. Não há migração de esquema.
- **Cores:** só tokens do tema. Raw palette (`text-green-600`…) é proibida
  pelo DesignTokensTest. Valores numéricos livres vão por variáveis CSS.
- **Nunca `crypto.randomUUID`:** usar `randomId()` de `@/lib/utils`.
- **Textos:** o backoffice está em PT-PT, com "tu". Os comentários são em
  português, poucos, como no código à volta.
- **Limites:** `MAX_DEPTH = 4` contentores, `MAX_NODES = 400` nós,
  `MAX_ROW_CHILDREN = 6` filhos num contentor em linha.
- **Dispositivos:** `desktop` (≥ 64rem), `tablet` (≥ 48rem) e `mobile` (o
  resto). O que não se define herda do tamanho acima.
- **Raiz:** só contentores. Os elementos `rootOnly()` (`product_main`,
  `catalog_grid`, `catalog_header`, `breadcrumb`, `product_related`) ficam
  sozinhos num contentor de raiz.
- **Caminhos de erro:** mantém-se a raiz `blocks.` e os filhos ficam em
  `.children.N`, por exemplo `blocks.0.children.1.content.pt.title`. Isto é
  um desvio deliberado do `nodes.` da spec, para não partir o editor nem o
  `TranslationService`.

## Review Focus

1. **Revisões v1 antigas repostas depois da mudança.** Têm de ser
   convertidas e ficar como eram. Teste:
   `test_restoring_a_v1_revision_upgrades_it`, na Task 6.
2. **Largar um contentor dentro de um descendente seu** (no Navegador ou na
   página). Tem de ser recusado sem perder nós. O guarda é `canDrop` na
   Task 9, e o servidor também recusa ciclos e ids repetidos
   (`test_duplicate_ids_across_the_tree_are_renumbered`, na Task 3).
3. **Um URL de vídeo que não é YouTube nem Vimeo**, ou com `javascript:`.
   Tem de ser recusado com uma mensagem clara.
   `test_video_rejects_other_hosts`, na Task 5.
4. **Colar (Ctrl+V) um nó de outra página com um elemento `unique` ou
   `rootOnly`.** Não pode partir a gravação: o editor não cola e mostra um
   aviso. Guarda `canPaste`, na Task 11.
5. **Uma página no limite (400 nós, ou 4 níveis).** O servidor diz qual é o
   limite. `test_node_and_depth_limits`, na Task 3.

---

## Ficheiros

**Novos (servidor):**
- `app/PageBuilder/PageDocument.php`: conversão v1 → v2, percorrer e
  procurar.
- `app/PageBuilder/StyleProp.php`: uma propriedade de estilo (enum, int,
  token, toggle, media) e a sua limpeza por dispositivo.
- `app/PageBuilder/Container.php`: o esquema do contentor (as `StyleProp`)
  e os limites.
- `app/PageBuilder/Blocks/{Heading,Paragraph,Image,Button,Icon,Divider,Video,IconList,Quote}Block.php`.
- `app/PageBuilder/VideoEmbed.php`.

**Novos (loja e editor):**
- `resources/js/blocks/node-tree.tsx`: o `NodeTree`, o `ContainerNode` e o
  `ElementNode`.
- `resources/js/blocks/{heading,paragraph,image,button,icon,divider,video,icon-list,quote}.tsx`.
- `resources/js/lib/page-tree.ts`: operações puras na árvore (procurar,
  inserir, mover, remover, duplicar, `canDrop`, `canPaste`, `resolveDevices`).
- `resources/css/page-builder.css`: a folha fixa das variáveis CSS (importada
  em `app.css`).
- `resources/js/components/admin/montra/builder/`:
  - `elements-panel.tsx`;
  - `edit-panel.tsx` (Conteúdo, Estilo e Avançado);
  - `style-control.tsx` (um controlo por `StyleProp`, com o dispositivo e a
    herança);
  - `navigator.tsx`;
  - `structure-picker.tsx`;
  - `canvas-overlay.tsx` (corre dentro do iframe);
  - `dnd-protocol.ts`.

**Alterados:**
- `BlockSanitizer`, `PageRenderer`, `Block`, `BlockRegistry`,
  `DefaultPages`, `TranslationService`;
- `app/Mcp/Tools/Read/PageGetTool.php`, `app/Mcp/Tools/Read/PagesListTool.php`;
- `SecurityHeaders` (frame-src);
- `resources/js/blocks/{index,section}.tsx` e os blocos com layout por ecrã;
- `resources/js/pages/store/page.tsx`;
- `resources/js/types/{page-builder,store}.ts`;
- `resources/js/pages/admin/montra/editor.tsx`, `preview-frame.tsx`,
  `use-block-history.ts`, `shortcuts.tsx`.

**Retirados:**
- `block-list.tsx`, substituído pelo `navigator.tsx`;
- `inspector.tsx`, substituído pelo `edit-panel.tsx`, que reaproveita o
  `field-input.tsx`.

---

## Fase A — Servidor

### Task 1: `PageDocument` (conversão e travessia)

**Files:**
- Create: `app/PageBuilder/PageDocument.php`
- Test: `tests/Feature/PageBuilder/PageDocumentTest.php`

**Interfaces — Produces:**
- `PageDocument::upgrade(array $nodes): array`: cada nó de raiz que não é
  `container` fica embrulhado em
  `PageDocument::wrapper(array $node): array`. Um documento que já é v2 sai
  igual.
- `PageDocument::wrapper(array $child): array`. Devolve
  `{id: 'c-'.$child['id'], type:'container', v:2, visible:true, settings:[], content:[], style:[], advanced:[], children:[$child]}`.
  O `style` vazio significa transparente: largura total, sem preenchimento e
  sem fundo. Os ids são determinísticos, para a conversão ser estável entre
  leituras.
- `PageDocument::walk(array $nodes, callable $fn, string $path = 'blocks', int $depth = 0): void`.
  Chama `$fn(array $node, string $path, int $depth)` em pré-ordem.
- `PageDocument::find(array $nodes, string $id): ?array`
- `PageDocument::count(array $nodes): int`

- [x] **Step 1: Escrever os testes que falham**
  - `test_v1_blocks_are_wrapped_in_transparent_root_containers`:
    - `upgrade([['id'=>'a','type'=>'hero',...]])` dá um container com
      `id 'c-a'`, `children[0]['id'] === 'a'` e `style === []`;
    - o `style` v1 do hero mantém-se no filho.
  - `test_upgrade_is_idempotent`:
    `upgrade(upgrade($v1)) === upgrade($v1)`.
  - `test_mixed_roots_only_wrap_non_containers`.
  - `test_walk_visits_in_preorder_with_paths`. Os caminhos esperados são
    `['blocks.0', 'blocks.0.children.0', 'blocks.0.children.1', 'blocks.1']`.
  - `test_find_and_count_cover_nested_nodes`.
- [x] **Step 2:** `php artisan test --compact tests/Feature/PageBuilder/PageDocumentTest.php`
  deve falhar com "Class not found".
- [x] **Step 3:** implementar o `PageDocument` (static e puro).
- [x] **Step 4:** correr o mesmo comando e confirmar PASS.
- [x] **Step 5:** commit: "Construtor: PageDocument converte v1 em árvore e
  percorre-a".

### Task 2: `StyleProp`, `Container` e os estilos dos elementos

**Files:**
- Create: `app/PageBuilder/StyleProp.php`, `app/PageBuilder/Container.php`
- Modify: `app/PageBuilder/Block.php` (`styleFields()`, `hasShell()`,
  `rootOnly()`, `advancedFields()`) e `BlockRegistry::schema()`
- Test: `tests/Feature/PageBuilder/StylePropTest.php`

**Interfaces — Produces:**
- `StyleProp::enum(string $key, string $label, array $options, ?string $default = null)`
- `StyleProp::int(string $key, string $label, int $min, int $max, string $unit = 'px', ?int $default = null)`
- `StyleProp::token(string $key, string $label, array $tokens)`
- `StyleProp::toggle(string $key, string $label)`
- `StyleProp::media(string $key, string $label)`
- `StyleProp->clean(mixed $byDevice, string $path, array &$errors): array<'desktop'|'tablet'|'mobile', scalar>`.
  Larga dispositivos desconhecidos e valores fora da lista ou dos limites,
  com o erro em `$path.$device`. Devolve só os dispositivos com valor.
- `StyleProp->toArray(): array`, para o esquema do editor.
- `Container::MAX_DEPTH = 4`, `Container::MAX_NODES = 400`,
  `Container::MAX_ROW_CHILDREN = 6`.
- `Container::styleProps(): array<StyleProp>`:

  | Prop | Tipo | Valores |
  |---|---|---|
  | `direction` | enum | `row`, `column` |
  | `wrap` | toggle | |
  | `gap` | int | 0–120 |
  | `justify` | enum | start, center, end, between |
  | `align` | enum | stretch, start, center, end |
  | `width` | int | 10–100 `%`, ausente = automática |
  | `max_width` | enum | `contained`, `full` |
  | `min_height` | int | 0–1200 |
  | `padding_y` | int | 0–200 |
  | `padding_x` | int | 0–120 |
  | `bg` | token | `Block::BACKGROUNDS` |
  | `bg_image` | media | |
  | `overlay` | int | 0–90 `%` |
  | `radius` | int | 0–48 |
  | `text_align` | enum | start, center, end |

- `Block::advancedFields(): array<StyleProp>`. Igual para todos os nós:
  `margin_top`, `margin_bottom` (0–200), `padding_y` (0–200), `padding_x`
  (0–120) e `hide` (toggle).
- `Block::styleFields(): array<StyleProp>`. Por omissão é `[]`; os
  elementos novos definem o seu.
- `Block::hasShell(): bool`. Por omissão é `true`. Os elementos básicos novos
  devolvem `false`.
- `Block::rootOnly(): bool`. Por omissão é `false`. Devolvem `true` o
  `ProductMainBlock`, o `CatalogGridBlock`, o `CatalogHeaderBlock`, o
  `BreadcrumbBlock` e o `ProductRelatedBlock`.
- O `schema()` de cada bloco ganha `styleFields`, `advancedFields`,
  `hasShell` e `rootOnly`. O `BlockRegistry::schema($template)` passa a
  devolver `{blocks: [...], container: Container::schema()}`. Ajustar os
  consumidores no `PageController::edit` (prop `schema`).

- [x] **Step 1: Testes**
  - `test_int_prop_keeps_valid_devices_and_drops_out_of_range` (com
    `padding_y` `{desktop: 40, tablet: 999, phone: 10}`):
    - devolve `{desktop: 40}`;
    - dá um erro em `…tablet`;
    - ignora `phone`.
  - `test_token_prop_refuses_free_colours`: `bg: {desktop:'#ff0000'}` dá
    erro.
  - `test_schema_exposes_container_and_style_fields`: o
    `schema('page')['container']` tem `direction`; o esquema do `hero` tem
    `hasShell` a `true` e `rootOnly` a `false`.
- [x] **Step 2:** correr e confirmar FAIL.
- [x] **Step 3:** implementar.
- [x] **Step 4:** PASS.
- [x] **Step 5:** commit.

### Task 3: `BlockSanitizer` recursivo

**Files:**
- Modify: `app/PageBuilder/BlockSanitizer.php`
- Test: `tests/Feature/PageBuilder/BlockSanitizerTest.php` (os testes v1
  continuam, porque a entrada v1 passa pelo `upgrade`; os resultados agora
  são v2, por isso ajustar os acessos a `[0]['children'][0]`)

**Interfaces:**
- Consumes: `PageDocument::upgrade/walk/count`, `Container`, `StyleProp`.
- Produces: `clean(array $nodes, string $template, bool $requireFields = true): array`
  e `preview(array $nodes, string $template): array`. A assinatura é a
  mesma; a entrada pode ser v1 ou v2 e a saída é sempre v2.
  - Nó contentor limpo:
    `{id,type:'container',v:2,visible,settings:[],content:[],style:{prop:{device:val}},advanced:{…},children:[…]}`.
  - Elemento limpo: igual ao de hoje, mais `advanced`, `styleProps` (os
    valores do `styleFields`, por dispositivo) e `shell` (bool). O `shell`
    é `true` por omissão quando `hasShell()`; num elemento novo largado
    numa coluna, o editor manda `false`.
- `BlockSanitizer::MAX_BLOCKS` é retirado e substituído por
  `Container::MAX_NODES`.
- Todos os nós aceitam `label`: o nome dado no Navegador, até 60 caracteres,
  sem HTML. Vazio fica `null`.

**Regras (cada uma com o seu erro, no caminho do nó):**
- Depois do `upgrade`, a raiz só tem contentores.
- A profundidade de contentores não passa de 4: "Não se pode meter mais de 4
  contentores uns dentro dos outros."
- Uma página não passa de 400 nós.
- Um contentor com `direction.desktop === 'row'` não tem mais de 6 filhos.
- Um elemento `rootOnly` tem de ser o único filho de um contentor de raiz:
  "O bloco "X" só pode ficar sozinho numa secção da página."
- `unique` e `required` contam-se na árvore inteira.
- Os ids são únicos na árvore inteira; um id repetido ganha um uuid novo.
- O tipo `container` não está no `BlockRegistry`; o sanitizador trata-o à
  parte.

- [x] **Step 1: Testes novos**
  - `test_nested_tree_is_kept_and_cleaned`: um contentor com duas colunas e
    `heading` e `paragraph` (Task 5; até lá usar `text` e `spacer`).
  - `test_node_and_depth_limits`:
    - 5 níveis dão erro em `blocks.0.children.0.children.0.children.0.children.0`;
    - 401 nós dão o erro `blocks`.
  - `test_row_container_with_seven_children_is_refused`.
  - `test_root_only_block_inside_a_column_is_refused`: `product_main`
    dentro de uma coluna, no modelo `product`.
  - `test_unique_counts_across_the_tree`.
  - `test_duplicate_ids_across_the_tree_are_renumbered`.
  - `test_preview_never_throws_and_drops_unknown_nodes`.
- [x] **Step 2:** FAIL.
- [x] **Step 3:** implementar.
- [x] **Step 4:** correr o `BlockSanitizerTest` e o `PageEditorTest` até
  passarem.
- [x] **Step 5:** commit.

### Task 4: `PageRenderer` recursivo

**Files:**
- Modify: `app/PageBuilder/PageRenderer.php`, `resources/js/types/store.ts`
- Test: `tests/Feature/PageBuilder/PageRendererTest.php`

**Interfaces — Produces** (a forma que a loja recebe):
- `StoreNode = StoreContainer | StoreBlock`.
- `StoreContainer = {id, type:'container', style: Record<prop, {desktop?,tablet?,mobile?}>, advanced, bgImage: ImageData|null, children: StoreNode[]}`.
- `StoreBlock`: o de hoje mais `shell: boolean`,
  `styleProps: Record<prop, devices>` e `advanced`.
- Só saem os nós visíveis. Um contentor sem filhos visíveis sai na mesma,
  para o espaço contar. As imagens pré-carregam-se na árvore inteira,
  incluindo `bg_image`.

- [x] **Step 1: Testes**
  - `test_tree_is_rendered_recursively_with_hidden_nodes_removed`.
  - `test_v1_blocks_render_like_before`: para cada página do
    `DefaultPages`, o `data` de cada elemento renderizado é igual ao `data`
    do bloco v1 renderizado antes. Guardar a saída de referência num teste
    de "snapshot" em array, gerada no próprio teste a partir do v1 com
    `shell = true`.
  - `test_container_background_image_is_resolved`.
- [x] **Step 2:** FAIL.
- [x] **Step 3:** implementar.
- [x] **Step 4:** PASS.
- [x] **Step 5:** commit.

### Task 5: Elementos básicos novos, vídeo e CSP

**Files:**
- Create: os 9 blocos em `app/PageBuilder/Blocks/` e
  `app/PageBuilder/VideoEmbed.php`
- Modify: `BlockRegistry::BLOCKS`; `SecurityHeaders` (frame-src nas rotas da
  montra e na `admin.montra.preview`)
- Test: `tests/Feature/PageBuilder/BasicElementsTest.php`,
  `tests/Feature/Security/SecurityHeadersTest.php` (onde já estiver o teste
  do CSP)

**Campos (Conteúdo e styleFields). Todos com `hasShell() = false` e
`templates() = ALL_TEMPLATES`:**

| Elemento | Conteúdo | styleFields |
|---|---|---|
| `heading` | `text` (tradução, obrigatório, máx. 200) | `level` (h1–h4, `h2`); `size` (enum sm/md/lg/xl/2xl); `color` (token: default, muted, accent, gold) |
| `paragraph` | `body` (richtext, tradução, obrigatório) | `size` (sm/md/lg); `color` (token) |
| `image` | `image` (obrigatório); `caption` (tradução); `link` | `ratio` (auto, 1:1, 4:3, 16:9, 3:4); `radius` (0–48); `fit` (cover/contain) |
| `button` | `link` (tradução, obrigatório) | `variant` (primary/outline/link); `size` (sm/md/lg); `full_width` (toggle) |
| `icon` | `icon` (Field::icon); `link` | `size` (16–128); `color` (token) |
| `divider` | — | `thickness` (1–8); `width` (10–100 %); `color` (token: border, muted, accent) |
| `video` | `url` (texto, obrigatório; o sanitizador guarda o URL de embed) | `ratio` (16:9, 4:3, 1:1, 9:16) |
| `icon_list` | repeater `items` (máx. 12): `icon`, `text` (tradução), `link` | `color` (token); `gap` (0–40) |
| `quote` | `text` (tradução, obrigatório); `author` | `size` (md/lg) |

**`VideoEmbed::fromUrl(string $url): ?string`:**
- `youtube.com/watch?v=ID`, `youtu.be/ID` e `youtube.com/shorts/ID` dão
  `https://www.youtube-nocookie.com/embed/ID`, com o ID em
  `[A-Za-z0-9_-]{11}`.
- `vimeo.com/NUM` dá `https://player.vimeo.com/video/NUM`.
- Qualquer outro dá `null`, e o sanitizador mostra o erro "Só vídeos do
  YouTube ou do Vimeo."

**CSP:**
- Nas respostas da montra (rotas `store.*`, `home`, `i18n.*`, landing
  pages) e na `admin.montra.preview`, o `frame-src` passa a
  `'self' https://www.youtube-nocookie.com https://player.vimeo.com`.
- No resto do backoffice continua `'self'`.

- [x] **Step 1: Testes**
  - `test_heading_requires_text_and_cleans_level`.
  - `test_paragraph_is_purified`: um `<script>` sai.
  - `test_video_accepts_youtube_and_vimeo_urls`. É um data provider com os
    quatro formatos acima.
  - `test_video_rejects_other_hosts`: `https://evil.example/x` e
    `javascript:alert(1)`.
  - `test_store_pages_allow_video_frames_but_admin_does_not`.
  - `BlockComponentsTest` (que já existe): tem de falhar enquanto faltarem
    os componentes React.
- [x] **Step 2:** FAIL.
- [x] **Step 3:** implementar os blocos PHP e criar os componentes React em
  `resources/js/blocks/*.tsx`, registados no `BLOCK_COMPONENTS`. Usam os
  tokens; o vídeo é um `<iframe>` com
  `loading="lazy" allow="fullscreen; picture-in-picture"`.
- [x] **Step 4:** PASS, incluindo o `BlockComponentsTest` e o
  `DesignTokensTest`.
- [x] **Step 5:** commit.

### Task 6: Consumidores (serviço, páginas de origem, traduções, MCP)

**Files:**
- Modify: `DefaultPages::blocks()`, que passa a devolver
  `PageDocument::upgrade(<os blocos v1 de hoje>)`.
- Modify: `PageBuilderService`:
  - `duplicate()` gera ids novos na árvore inteira com `PageDocument::walk`;
  - `restore()` passa as revisões v1 pelo sanitizador, que já as converte.
- Modify: `TranslationService`. O sítio que percorre o `draft_blocks`
  (linha ~230) e o `translatePage()` usam `PageDocument::walk/find`, por
  isso um id de qualquer nível serve.
- Modify: o `PageGetTool` e o `PagesListTool` devolvem a árvore, com uma
  linha a dizer que é v2 na descrição da ferramenta.
- Test: `PageBuilderServiceTest`, `SystemPagesTest`, os testes do MCP das
  páginas e os de tradução de páginas.

- [x] **Step 1: Testes**
  - `test_restoring_a_v1_revision_upgrades_it`. Cria uma `PageRevision` com
    blocos v1, repõe-na e confirma que o `draft_blocks[0]['type']` é
    `container` e que o `children[0]` é o bloco original.
  - `test_duplicate_gives_new_ids_to_every_node`.
  - `test_translate_page_finds_nested_nodes`.
  - `test_system_pages_are_created_as_trees`.
  - `test_mcp_page_get_returns_the_tree`.
- [x] **Step 2:** FAIL.
- [x] **Step 3:** implementar.
- [x] **Step 4:** correr em segundo plano todos os testes de
  `tests/Feature/PageBuilder`, `tests/Feature/Store` e `tests/Feature/Mcp`,
  e os de tradução. Têm de passar.
- [x] **Step 5:** commit.

## Fase B — Loja

### Task 7: `NodeTree`, variáveis CSS e a casca

**Files:**
- Create: `resources/js/blocks/node-tree.tsx`,
  `resources/css/page-builder.css` (importado em `resources/css/app.css`) e
  `resources/js/lib/page-tree.ts` (só o `resolveDevices` nesta task)
- Modify: `resources/js/blocks/index.tsx`:
  - `BlockProps = { block, first, nested }`;
  - o `BlockList` é substituído pelo `NodeTree`.
- Modify: `resources/js/blocks/section.tsx`. O `BlockSection` recebe
  `shell: boolean`:
  - com `false`, devolve só os filhos dentro de um
    `<div className="@container">`, sem fundo, padding nem `max-w`;
  - com `true`, fica igual a hoje, mais `@container` no div interior.
- Modify: `resources/js/pages/store/page.tsx` (o `NodeTree` no lugar do
  `BlockList`) e o tipo do protocolo `blocks` (passa a árvore).

**Interfaces — Produces:**
- `resolveDevices<T>(v: {desktop?: T; tablet?: T; mobile?: T}, fallback?: T): {desktop?: T; tablet?: T; mobile?: T}`.
  Preenche o tablet com o desktop e o mobile com o tablet.
- No `ContainerNode`, cada propriedade sai como variáveis
  `--pb-<prop>-d|t|m` no `style` inline:
  - os inteiros em px, ou % no `width`;
  - os enums como o valor CSS (`between` dá `space-between`).
- `page-builder.css` define `.pb-container` com
  `display:flex; flex-direction: var(--pb-direction-m, column)` e afins, mais
  `@media (min-width: 48rem)` com as `-t` e `@media (min-width: 64rem)` com
  as `-d`. Os mesmos padrões valem para `.pb-advanced` (margens, padding e
  `hide`: `display:none` por dispositivo).
- **Por omissão (spec):** um contentor em linha passa a `column` no `mobile`
  se o dono não definir o `mobile`. O `max_width: contained` dá
  `max-width: 72rem; margin-inline: auto` (o `max-w-6xl` de hoje), mais o
  `px-4` de hoje.
- O fundo `bg` usa as mesmas classes do `BlockSection` (`bg-muted`, `dark …`).
  Exporta-se o mapa `BACKGROUNDS` do `section.tsx`.

- [x] **Step 1:** implementar, sem testes de JS (o projeto não tem runner).
- [x] **Step 2:** `npm run build` e a verificação do frontend (os três
  comandos das Global Constraints).
- [x] **Step 3:** correr o `BlockComponentsTest`, o `DesignTokensTest` e o
  `tests/Feature/Store` até passarem.
- [x] **Step 4: Verificação visual de referência.** Antes desta task, com o
  build anterior, tirar capturas em 1280 e em 390 de cada página de origem
  (início, catálogo, um produto, sobre, personalizado, contacto, perguntas,
  termos, privacidade e envios) para
  `.superpowers/visual/antes/<pagina>-<largura>.png`. Depois desta task,
  tirar as `depois/`. Comparar uma a uma no browser; qualquer diferença
  visível é um bug desta task.
- [x] **Step 5:** commit.

### Task 8: Os blocos da loja respondem à largura da coluna

**Files:**
- Modify: `resources/js/blocks/{product-grid,categories,gallery,testimonials,steps,trust,text-image,hero,banner,faq,contact,custom-order,text}.tsx`.
  Só as classes de layout que dependem do ecrã.

**Regra:** dentro dos blocos, `md:` passa a `@3xl:` (48rem) e `lg:` passa a
`@5xl:` (64rem), sempre dentro do `@container` que o `BlockSection` dá.
- Os `sm:` passam a `@xl:` (36rem).
- Não se tocam as classes de tipografia que já dependem do ecrã
  (`md:text-…`).
- O `sizes` das imagens fica igual.

- [x] **Step 1:** mudar as classes.
- [x] **Step 2:** fazer o build e repetir a comparação visual da Task 7
  (antes e depois). Na largura contida de hoje o resultado tem de ser igual.
- [x] **Step 3:** num rascunho local, pôr uma grelha de produtos numa coluna
  de 33% e confirmar que passa a 1 ou 2 colunas em vez de 4 espremidas.
  Fazer captura.
- [x] **Step 4:** commit.

## Fase C — Editor

### Task 9: Estado em árvore e operações

**Files:**
- Modify: `resources/js/lib/page-tree.ts`;
  `resources/js/types/page-builder.ts` (`EditorNode`, `EditorContainer`,
  `ContainerSchema`, `StylePropSchema`; o `BlockSchema` ganha
  `styleFields`, `advancedFields`, `hasShell` e `rootOnly`);
  `resources/js/components/admin/montra/use-block-history.ts` (genérico:
  `useHistory<T>`); `resources/js/components/admin/montra/blocks.ts`
  (`blankNode(schema, …)`, `blankContainer(columns: number[])`,
  `duplicateNode` com ids novos em profundidade).
- Modify: `editor.tsx`. O estado passa a `nodes: EditorNode[]`; a seleção, o
  autosave, a fila, o `flush`, os guardas, o desfazer e o `resetBlocks`
  continuam iguais sobre a árvore. O payload do `rascunho` continua a ser
  `{blocks: nodes, seo}`.

**Interfaces — Produces (`page-tree.ts`, todas puras e imutáveis):**
- `findNode(nodes, id): {node, parent: EditorContainer | null, index, depth} | null`
- `insertNode(nodes, node, target: {parentId: string | null; index: number}): EditorNode[]`.
  Com `parentId` nulo e um nó que não é contentor, embrulha-o com
  `blankContainer([100])`.
- `moveNode(nodes, id, target): EditorNode[]`
- `removeNode(nodes, id): EditorNode[]`
- `updateNode(nodes, id, patch): EditorNode[]`
- `duplicateInPlace(nodes, id): {nodes, newId}`
- `canDrop(nodes, draggedId | {type}, target, schemaByType, limits): string | null`.
  Devolve `null` se pode largar; senão, a mensagem. Cobre:
  - um contentor para dentro de si mesmo ou de um descendente;
  - a profundidade acima de 4;
  - mais de 6 filhos numa linha;
  - um `rootOnly` fora da raiz;
  - um `unique` repetido.
- `canPaste(nodes, node, target, …)`: igual ao `canDrop`, aplicado à
  sub-árvore colada.

- [x] **Step 1:** implementar e passar o editor para a árvore. A lista de
  blocos antiga fica a funcionar só com a raiz até à Task 10.
- [x] **Step 2:** tsc, ESLint e Prettier.
- [x] **Step 3:** no browser (editor de uma página de origem):
  - abre sem erros;
  - mudar um campo grava ("Rascunho guardado");
  - desfazer funciona;
  - publicar funciona;
  - a loja mostra a alteração.
- [x] **Step 4:** commit.

### Task 10: Painel Elementos/Editar, controlos por dispositivo e Navegador

**Files:**
- Create: `builder/elements-panel.tsx`, `builder/edit-panel.tsx`,
  `builder/style-control.tsx`, `builder/navigator.tsx`.
- Modify: `editor.tsx`. O layout passa a: painel esquerdo de 300px
  (Elementos/Editar), canvas, e o Navegador de 280px à direita, que se abre
  e fecha (o botão "☰ Navegador" na barra de topo e o estado no
  `localStorage`). O seletor de dispositivo do topo define também o
  `editingDevice`.
- Delete: `block-list.tsx` e `inspector.tsx`.

**Comportamento:**
- **Elementos:**
  - grelha de 3 colunas agrupada em Estrutura, Básicos e Da loja, com
    pesquisa sem acentos (a função que já existe no `block-catalog`);
  - cada tile é `draggable` e põe o tipo no
    `dataTransfer('application/x-studio-element', type)`, e também no estado
    `draggingType` do editor;
  - clicar num tile insere a seguir à seleção ou no fim;
  - os elementos fora do modelo não aparecem; os `unique` que já existem
    ficam desativados.
- **Editar:**
  - selecionar muda para Editar; Esc ou "← Elementos" volta;
  - separadores Conteúdo (o `FieldInput` de hoje, com as variantes e os
    idiomas), Estilo e Avançado;
  - um contentor não tem Conteúdo e abre em Estilo, com uma estrutura
    rápida: "Colunas: 1 · 2 · 3 · 4" e as larguras.
  - nos elementos com `hasShell`, o Estilo mostra o interruptor "Casca de
    secção":
    - ligado: aparecem os controlos de hoje (fundo, espaçamento, largura e
      alinhamento) e a variante;
    - desligado: aparece só o alinhamento, e o fundo passa a ser o do
      contentor.
  - o nome do nó edita-se com duplo clique no Navegador e grava em
    `label`.
- **Ícones:** o mapa `ICONS` do `blocks.ts` ganha os 9 elementos novos
  (Heading, Pilcrow, Image, RectangleHorizontal, Star, Minus, Play, List,
  Quote, do lucide) e `container: Square`.
- **`StyleControl`:**
  - um controlo por `StylePropSchema`: Select para enum e token, slider
    mais número para int, Switch para toggle e o `MediaPicker` para media;
  - ao lado, o ícone do `editingDevice`;
  - um valor herdado aparece esbatido, com "herdado do
    computador/tablet" e o botão "×" para repor;
  - escrever grava em `style[prop][editingDevice]`.
- **Navegador:**
  - árvore com ▾/▸ (o aberto/fechado fica no estado; o caminho até ao nó
    selecionado abre-se sozinho);
  - em cada linha: ícone (o `BlockIcon`, ou ▣ para o contentor) e nome (o
    `label` do esquema; nos contentores, "Contentor · N colunas" quando em
    linha);
  - o olho (`visible`) e o "…" com Duplicar, Copiar, Colar dentro/depois e
    Apagar;
  - arrastar com o dnd-kit, em lista achatada com profundidade (o padrão
    "sortable tree"): o deslocamento horizontal decide o nível e o
    `canDrop` recusa com um contorno vermelho e o aviso em `title`.

- [x] **Step 1:** implementar.
- [x] **Step 2:** tsc, ESLint, Prettier e build.
- [x] **Step 3:** no browser:
  - selecionar pelo Navegador abre Editar;
  - mudar o `padding_y` em telemóvel e confirmar que o computador mantém o
    seu valor (a pré-visualização em telemóvel mostra o novo);
  - arrastar no Navegador um título de uma coluna para a outra;
  - tentar meter um contentor dentro de si próprio e confirmar que é
    recusado.
- [x] **Step 4:** commit.

### Task 11: A página editável (seleção, arrastar e largar, estruturas, copiar e colar)

**Files:**
- Create: `builder/canvas-overlay.tsx`, montado pelo `store/page.tsx` só em
  preview; `builder/dnd-protocol.ts`; `builder/structure-picker.tsx`.
- Modify: `preview-frame.tsx`, o tipo `PreviewMessage` e `shortcuts.tsx`.

**Protocolo (acrescenta ao que já existe e não muda o que há):**

| Direção | Mensagem |
|---|---|
| editor → iframe | `{source:'studio-editor', kind:'drag', type: string \| null}` (começou ou acabou um arrasto da grelha) |
| editor → iframe | `{kind:'labels', labels: Record<id, string>}` (os nomes para as etiquetas) |
| iframe → editor | `{source:'studio-preview', kind:'drop', type, target:{parentId, index}}` |
| iframe → editor | `{kind:'move', id, target}` |
| iframe → editor | `{kind:'action', id, action:'duplicate'\|'remove'\|'add-structure', columns?: number[]}` |

**`canvas-overlay`:**
- **Rato por cima:** um contorno tracejado no nó (`[data-node-id]`; o
  `NodeTree` põe o atributo em contentores e elementos).
- **Nó selecionado:** um contorno sólido `ring`, com uma etiqueta no topo:
  ⠿ nome, ⧉ e ✕. A pega é `draggable` e manda `move` no `drop`.
- **Contentores vazios:** desenham "+ ▥ Arrasta um elemento para aqui".
  - O "+" pede ao editor para abrir a grelha de Elementos com o alvo
    guardado.
  - O ▥ pede o `structure-picker`, através da ação `add-structure` com o
    alvo.
- **`dragover`:** calcula o alvo pelo nó por baixo do ponteiro.
  - Contentor vazio: dentro.
  - Num filho: antes ou depois, conforme a metade em que está o ponteiro,
    na direção do pai (horizontal em linha, vertical em coluna).
  - Desenha uma linha de 3px `bg-ring`.
- **Fim da página:** fica sempre um "+ ▥" com o alvo `{parentId:null, index: n}`.

**Editor:**
- `drop` e `move` validam com o `canDrop` antes de aplicar. Se não puder,
  sai um `toast.error(mensagem)`.
- Um elemento "Da loja" (`hasShell`) largado dentro de uma coluna nasce com
  `shell: false`. Largado na raiz, nasce com `shell: true`.
- `structure-picker`: um popover com 6 opções, que dão
  `blankContainer([100])`, `[50,50]`, `[66,34]`, `[34,66]`, `[33,33,34]` e
  `[25,25,25,25]`. Cada coluna é um contentor `direction: column` com
  `width.desktop` = a percentagem.
- **Ctrl+C, Ctrl+V e Ctrl+X:**
  - copiar põe a sub-árvore em
    `localStorage['studio-clipboard'] = {v:2, node}` (dentro de
    try/catch);
  - colar cola depois do selecionado, ou dentro dele se for um contentor
    vazio, com ids novos e passando pelo `canPaste`;
  - Delete e Ctrl+D mantêm as regras de foco que já existem (o Navegador
    em vez do `[data-block-list]`).

- [x] **Step 1:** implementar.
- [x] **Step 2:** tsc, ESLint, Prettier e build.
- [x] **Step 3:** no browser, reproduzir a frase de sucesso da spec:
  1. "+ ▥" no fim, escolher 66/34;
  2. arrastar Título, Texto e Botão da grelha para a coluna 66, e Imagem
     para a coluna 34;
  3. por baixo, um contentor de 3 colunas com um Ícone e um Texto em cada;
  4. em telemóvel, confirmar que as colunas empilham;
  5. Ctrl+C no contentor e Ctrl+V noutra página;
  6. desfazer;
  7. publicar e ver a loja.

  Fazer capturas.
- [x] **Step 4:** commit.

### Task 12: Fecho

- [x] **Step 1:** atualizar a lista de páginas (as miniaturas continuam,
  porque usam a preview) e a `tema.tsx`, se esta usar o `BlockList`.
  Confirmar com `grep -rn "BlockList" resources/js`.
- [x] **Step 2:** correr a suite completa em segundo plano até passar.
  Depois, os portões do frontend e um `npm run build` à parte.
- [x] **Step 3:** repetir a comparação visual antes/depois das páginas de
  origem.
- [x] **Step 4:** revisão independente do ramo inteiro e correção das
  falhas.
- [x] **Step 5:** marcar as tarefas neste plano e fazer o commit final.
