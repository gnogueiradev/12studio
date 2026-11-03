# Montra pública — Implementation Plan (reconstruído)

> **Reconstruído a 2026-10-27 a partir dos commits.** O plano original ficou noutra máquina e não chegou ao git. As fases 0 a 7 vêm descritas pelo que os commits fizeram, não pelo que o plano pedia. Quando o original aparecer, compara-o com este e fica com o que estiver mais certo.
>
> **For agentic workers:** as fases 0–7 estão feitas. O que falta são as Tasks 8–11 (integrar, preparar o deploy, fazer o deploy e pôr a loja no ar). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pôr a loja pública no ar. Leva catálogo e página de produto, páginas montadas por blocos e editáveis no backoffice, quatro idiomas e conversas com clientes sem conta: contacto, interesse num produto e peça personalizada. **Não há carrinho nem checkout.** Uma venda começa numa conversa, e a conversa passa a rascunho de encomenda.

**Architecture:**
- Rotas: a montra fica pública no topo de `routes/web.php`. Os idiomas são PT na raiz e ES, EN e FR com prefixo. As landings ficam em `/{slug}`, no fim de tudo.
- Partilha de dados: o middleware `store.shared` (`ShareStorefront`) só reparte menus, contactos e categorias na montra. O `store.open` (`EnsureStoreOpen`) mostra o "em breve" quando a loja está fechada. Nesse caso o admin vê a montra com uma faixa de pré-visualização.
- `App\PageBuilder`:
  - os blocos têm um esquema de campos (`Field`) e são 14 genéricos, mais os do catálogo e do produto;
  - o HTML passa pelo `BlockSanitizer` (HTMLPurifier);
  - o `PageRenderer` escolhe o idioma campo a campo e nunca rebenta com um bloco desconhecido;
  - cada página tem rascunho e publicado lado a lado (`pages`), e guarda as últimas 20 revisões.
- Catálogo e produto:
  - o `CatalogQuery` lê os filtros do URL e pesquisa sem acentos (`products.search_text`);
  - o `ProductCard` e o `ProductDetail` nunca mostram custos, stock exato, tempos, gramagem nem SKU.
- Conversas:
  - o link privado é `/conversa/{token}`, e a BD só guarda o hash;
  - os emails seguem em fila, encriptados;
  - o anti-spam funciona sem captcha;
  - o RGPD resolve-se com `model:prune` e `conversations:forget-ips`.
- Traduções:
  - `product_translations` e `category_translations` guardam um `source_hash`, e a tradução passa a desatualizada quando o português muda;
  - cores e materiais ganham `name_i18n`;
  - o português fica onde estava.
- Imagens: o `GenerateImageVariants` faz o WebP por largura e o JPEG do Open Graph com `intervention/image`. O `images:variants` apanha as fotografias que ainda não têm versões.

**Tech Stack:** Laravel 13 (preso na 13.34), PHPUnit, Inertia + React 19 TS com SSR, Tailwind 4 + shadcn, `@dnd-kit` no editor, `intervention/image`, HTMLPurifier.

## Global Constraints

- A BD de produção tem dados. As migrações têm de ser aditivas e avisadas. Nada de `migrate:fresh` nem de escritas de manutenção à socapa.
- Os preços na montra levam IVA incluído. Os custos nunca chegam ao browser.
- PHP corre só no PowerShell (Herd). Os portões são `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check` e `php artisan test`. Não fazer `composer update`, porque o Laravel tem de ficar na 13.34.
- Os comentários de código são em português. No PHP vão sem acentos, no TS com acentos.
- Um merge em `main` faz deploy (Jenkins). Por isso, merge só a pedido do dono.

## Ramos

As fases estão empilhadas: cada ramo parte do anterior, e o `feat/montra-fase-7` contém as oito e o `main` todo (verificado a 2026-10-27). Os ramos 0 a 6 só servem para rever uma fase de cada vez.

---

### Fase 0: Abrir, fechar e contactos nas definições — `feat/montra-fase-0` ✅

- [x] Merge de `feat/marca-configuravel`: o nome da loja passa a vir das definições.
- [x] `StoreSettings` guarda se a montra está aberta, o email, o telefone, a morada, o horário, o tempo de resposta e as redes sociais. O `STORE_OPEN` do `.env` **só conta enquanto não houver nada gravado**.
- [x] Página Definições → Montra (`/admin/definicoes/montra`). Cada link social é validado contra o domínio da própria rede.
- [x] Middleware `store.open` e badge do dashboard.

### Fase 1: Idiomas, imagens, tema e layout — `feat/montra-fase-1` ✅

- [x] Quatro idiomas: PT na raiz, ES, EN e FR com prefixo.
- [x] Versões reduzidas das fotografias (WebP por largura e JPEG para o OG). Migração `add_variants_to_product_images_table`.
- [x] Tema por variáveis CSS a partir das definições, ainda sem editor.
- [x] Novo `StoreLayout`. A tabela `menus` e o `StoreMenus` dão o header e o rodapé. O layout traz seletor de idioma, rodapé em quatro colunas com o Livro de Reclamações e o botão "Fala connosco".

### Fase 2: Motor de blocos — `feat/montra-fase-2` ✅

- [x] Tabelas `pages`, `page_revisions` e `media`.
- [x] `App\PageBuilder` com `Field`, `Block`, 14 blocos, `BlockRegistry`, `BlockSanitizer`, `PageRenderer`, `PageBuilderService` e `ReservedSlugs`.
- [x] `DefaultPages` cria início, sobre, personalizado, contacto e FAQ nos 4 idiomas. **As legais (`terms`, `privacy`, `shipping`) nascem em rascunho, só com um esqueleto em português.** As páginas de sistema criam-se na primeira visita, sem seeder no deploy.
- [x] `visibleInStore` é a regra única para produtos e variantes. Também entram o `PriceRange` e o `ProductCard`.

### Fase 3: Catálogo, produto, SEO — `feat/montra-fase-3` ✅

- [x] `CatalogQuery` com filtros no URL, pesquisa sem acentos e ordem manual dos destaques. Migração `add_store_columns_to_products_table`, com o backfill do `search_text`.
- [x] Rotas `/produtos`, `/categoria/{slug}` e `/produtos/{slug}`. A página de produto traz galeria por cor, variante no URL, prazo e barra fixa no telemóvel.
- [x] SEO: canonical, hreflang, Open Graph e JSON-LD (Product, BreadcrumbList e Organization), mais o `/sitemap.xml`. Há páginas 404, 500 e 503 com o layout da montra.
- [x] `StoreTranslationKeysTest` confirma que cada `t('chave')` existe.

### Fase 4: Editor de páginas — `feat/montra-fase-4` ✅

- [x] `/admin/montra/paginas` para páginas de sistema e landings, com editor de blocos arrastável (também por teclado). O editor traz inspetor por idioma, autosave em rascunho, publicar, histórico e SEO por idioma.
- [x] Pré-visualização ao vivo num iframe, com `postMessage`. É a única rota que aceita moldura.
- [x] Menus, biblioteca de imagens (sem SVG) e tema. O tema é só para o dono e recusa contraste abaixo de 3:1.

### Fase 5: Conversas com clientes — `feat/montra-fase-5` ✅

- [x] Tabelas `conversations`, `conversation_tokens`, `conversation_messages` e `conversation_attachments`. Os anexos ficam no disco privado e são validados pelos bytes.
- [x] Pontos de entrada: "Fala connosco", bloco de contacto, "Tenho interesse"/"Fazer uma pergunta" e peça personalizada em 3 passos.
- [x] Backoffice `/admin/conversas` com caixa de entrada, notas internas, fechar e reabrir, e "Criar rascunho de encomenda".
- [x] Alerta no canal `#conversas` do Discord (`DISCORD_WEBHOOK_CONVERSAS`).
- [x] Prazos do RGPD:
  - conversas: 24 meses;
  - anexos: 6 meses;
  - IP: 30 dias.

### Fase 6: Traduções do conteúdo — `feat/montra-fase-6` ✅

- [x] Migração `create_content_translations`, com `source_hash` para marcar as traduções desatualizadas.
- [x] Separador "Traduções da montra" no produto e na categoria.
- [x] Ferramentas novas no MCP: `product_translation_set`, `category_translation_set`, `translations_missing`, `pages_list`, `page_get` e `page_content_translate`. O `color_update` e o `material_update` passam a aceitar nomes nos outros idiomas.

### Fase 7: Lançamento — `feat/montra-fase-7` ✅

- [x] Auditoria axe (WCAG 2.1 AA) em telemóvel e computador, sem violações. O texto dourado ganha o token `--color-gold-text`.
- [x] `docker/nginx-performance.conf` liga o gzip e a cache longa do `/build/assets/`, e o `Dockerfile` copia-o para a imagem.
- [x] Correções da revisão final:
  - anexos das notas internas;
  - markdown seguro nos emails;
  - fila de gravações do editor;
  - `ESCAPE` no `LIKE`;
  - traduções parciais.

---

## O que falta

### Task 8: Integrar no `main`

**Migrações:** são 7, revistas a 2026-10-27, e todas aditivas.

| Migração | O que faz | Risco |
|---|---|---|
| `2026_10_24_000010_add_variants_to_product_images_table` | 3 colunas nulas em `product_images` | Nenhum |
| `2026_10_24_000020_create_menus_table` | Tabela nova | Nenhum |
| `2026_10_24_000030_create_media_table` | Tabela nova | Nenhum |
| `2026_10_24_000040_create_pages_table` | `pages` + `page_revisions` | Nenhum |
| `2026_10_25_000010_add_store_columns_to_products_table` | `search_text` nula e `sort_order` 0 em `products`, e **escreve o `search_text` em todos os produtos** | Baixo: é texto derivado do nome e da descrição, não apaga nada e repete-se sem estragos |
| `2026_10_25_000020_create_conversations_tables` | 4 tabelas novas, com FK para `products`, `variants`, `order_drafts` e `users` (todas `nullOnDelete`) | Nenhum |
| `2026_10_25_000030_create_content_translations` | 2 tabelas novas e `name_i18n` nula em `colors` e `materials` | Nenhum |

O Jenkins já corre `db:backup` antes do `migrate --force`. O rollback do Jenkins repõe a imagem mas não as migrações. Para voltar atrás, a cópia de segurança é o caminho.

- [x] **Step 1:** Correr os portões no `feat/montra-fase-7` (ver Global Constraints). Resultado a 2026-10-27:
  - Pint, PHPStan, tsc e ESLint verdes. O tsc e o ESLint só passam depois de `php artisan wayfinder:generate --with-form`, porque as rotas geradas não estão no git.
  - PHPUnit: a primeira corrida deu 1626 de 1628, com 1 saltado. Falhou o `BrandSettingsTest::test_emails_are_signed_with_the_brand`, só por causa do `APP_NAME=12studio` do `.env` local (no Jenkins vale "Laravel"). Por trás estava um problema real: o `<title>`, o cabeçalho e o rodapé dos emails usavam o `config('app.name')`, não a marca das definições, e o rodapé dizia "All rights reserved." em inglês.
  - Corrigido no próprio ramo: o teste fixa o `app.name` e o idioma, e os layouts `resources/views/vendor/mail/html/{message,layout}.blade.php` e `text/message.blade.php` usam a `BrandSettings` e o `store.footer.rights`. Depois da correção: Pint verde e PHPUnit com 1627 de 1628, 1 saltado.
- [x] **Step 2:** Confirmar que o `composer.lock` só junta o `intervention/image` 3.11.10 e o `intervention/gif` 4.2.4, e que o `laravel/framework` fica na v13.34.0. Confirmado a 2026-10-27.
- [x] **Step 2b:** Ensaio local a 2026-10-27, numa BD de desenvolvimento com dados (cópia feita antes):
  - as 11 migrações pendentes correram limpas, incluindo as 7 da montra, e o `search_text` ficou preenchido;
  - `/`, `/en`, `/es/produtos`, `/fr/produtos/{slug}` e `/sitemap.xml` dão 200, e o hreflang leva o `x-default`;
  - uma pergunta sobre um produto abriu a conversa, e o email saiu da fila com o link privado e o rodapé no idioma certo;
  - o `images:variants` pôs a fotografia na fila. A de demonstração (70 bytes) não é uma imagem válida, e o job deixou um aviso sem rebentar, como está desenhado;
  - `/termos`, `/privacidade` e `/envios` dão 404 enquanto forem rascunho, mas o rodapé já tem links para elas. Por isso a Task 11 publica-as antes de abrir a loja.
- [ ] **Step 3:** Fazer as verificações da Task 9 **antes** do merge, porque o merge faz deploy.
- [ ] **Step 4:** `git switch main && git merge --no-ff feat/montra-fase-7`, com a mensagem `Merge feat/montra-fase-7: a montra publica (fases 0 a 7)`, e `git push`. Só com o dono a pedir.
- [ ] **Step 5:** Apagar os ramos `feat/montra-fase-0` a `feat/montra-fase-7`, no local e no remoto.

### Task 9: Preparar a produção (antes do merge)

O `.env` de produção vive no servidor (`/home/cinhos/projects/12studio/app/12studio/.env`, montado só de leitura). Daqui não se vê.

- [ ] **`STORE_OPEN`:** tem de estar `false` ou não existir. Enquanto ninguém gravar nada em Definições → Montra, é ele que decide. Com `true`, a montra nova ficava pública logo no deploy, com as páginas legais ainda em rascunho.
- [ ] **`DISCORD_WEBHOOK_CONVERSAS`:** criar o canal `#conversas` no Discord e pôr o webhook no `.env`.
- [ ] **Email:** confirmar que o `MAIL_*` de produção envia, porque as conversas mandam emails ao cliente com o link privado. O nome do remetente é o `MAIL_FROM_NAME`, que no `.env.example` é o `${APP_NAME}` e não a marca das definições. Convém que diga o nome da loja.
- [ ] **GD com WebP:** o `intervention/image` precisa disso na imagem `webdevops/php-nginx:8.4`. Para confirmar, no contentor:

```bash
php -r 'var_dump(extension_loaded("gd") && (gd_info()["WebP Support"] ?? false));'
```

### Task 10: Deploy

- [ ] O merge em `main` dispara o Jenkins. Na ordem: `db:backup`, `migrate --force`, `mcp:install`, `db:seed --force`, `optimize`, `queue:restart`.
- [ ] Confirmar no log do Jenkins as 7 migrações a correr, e o `/up` a responder.
- [ ] Gerar as versões das fotografias que já existem, sem esperar pelas 04:45. O worker das filas faz o resto.

```bash
php artisan images:variants
```

### Task 11: Pôr a loja no ar

- [ ] Definições → Montra: email, telefone, morada, horário, tempo de resposta e redes sociais.
- [ ] Escrever e publicar as páginas legais em `/admin/montra/paginas`: termos (`terms`), privacidade (`privacy`) e envios (`shipping`).
- [ ] Rever as páginas de origem (início, sobre, personalizado, contacto e FAQ), os menus e o tema.
- [ ] Traduções ES, EN e FR dos produtos, categorias, cores e materiais. O `translations_missing` do MCP diz o que falta.
- [ ] Com a loja ainda fechada, ver a montra como admin (faixa de pré-visualização): telemóvel e computador, `/sitemap.xml`, uma conversa de ponta a ponta com o email recebido, e o alerta no Discord.
- [ ] Abrir a loja em Definições → Montra.
- [ ] Submeter o `/sitemap.xml` no Google Search Console.
