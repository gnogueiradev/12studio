# Tamanhos como cidadãos de primeira — produtos com vários tamanhos

## Contexto

O dono vende peças como a **"Escultura familia 3 pessoas"** (produto 17) em vários tamanhos
(10cm, 20cm), cada um com gramas, tempo de impressão e preço próprios, e cores × materiais
por cima (5 cores, o Dourado em PLA Slik a +1 €). Sentiu que "isso não dá para fazer agora".

Exploração: **a BD já suporta** — `variants.size_label` (texto livre) é o 3.º eixo
(cor × material × tamanho) e o produto 17 já tem as 10 variantes certas. O que falha é o
**fluxo**, que assume "a peça é a mesma, só muda a cor":

1. **Impressão/preços** — `VariantProductionService::updateProduction()` (`app/Services/VariantProductionService.php:38`)
   grava o MESMO tempo/gramas em todas as variantes (`$product->variants()->update($values)`);
   `variant-production-table.tsx` mostra um par só ("Guardar em todas"). No produto 17 isto
   esmagava 40g/128min vs 189g/411min, e o "Aplicar preços calculados" a seguir estragava os preços.
2. **Criar é trabalhoso** — o gerador só existe a criar o produto, tem `SIZES = ['Pequeno','Médio','Grande']`
   fixo (`variant-matrix.tsx:29`) e dá os mesmos 3 preços a todas as combinações
   (`ProductService::generateVariants`, `:164`). Acrescentar "30cm" a um produto existente = 5 variantes à mão.
3. **Ver/organizar** — lista plana de 10 variantes.
4. **Etsy** — peso/medidas vêm da *primeira* variante (`EtsyListingBuilder::measures()`, `:211`);
   no produto 17 variam cor, material E tamanho → 3 propriedades, e a 3.ª (id 516,
   `config/etsy.php:148`) nunca foi confirmada.

Extra encontrado: `order_items.variant_label` guarda só o tamanho (`OrderService.php:241` e `:843`),
e nada impede duas variantes iguais (cor+material+tamanho) no mesmo produto.

**Decisões do dono (2026-10-14):** abordagem A — *agrupar por tamanho sem migração de dados*
(nada de tabela nova; o `size_label` existente é a chave do grupo); preços *base por tamanho
+ ajuste à mão* numa variante (ex.: Dourado).

## Princípios

- **Sem migrações de dados** ([[bd-tem-dados-em-producao]]): nenhuma coluna nova, nenhum backfill.
  O produto 17 passa a funcionar tal como está.
- **Grupo = `size_label` com `trim()`**, comparação case-insensitive; `null` é o grupo
  "sem tamanho". Um produto sem tamanhos tem um grupo só → UI igual à de hoje.
- Uma helper única para agrupar, reutilizada em PHP e TS:
  - PHP: `App\Support\VariantSizes` (`groups(Collection $variants): array<key, Collection>`, `key(?string): string`).
  - TS: `resources/js/lib/production.ts` passa de `productProduction()` a `productionBySize()`.
- Cada fase é um ramo próprio, mergeável sozinho, com os 5 portões verdes.

## Fase 1 — Impressão por tamanho (`feat/tamanhos-impressao`) — a que evita estragos

- **Request** (o `FormRequest` usado por `Admin\VariantProductionController::update`):
  passa a aceitar `sizes: [{size_label: string|null, hours, minutes, weight_grams}]`.
  Cada `size_label` tem de existir no produto.
- **Service** `updateProduction(Product, array $sizes)`: um `UPDATE` por grupo
  (`where size_label = ?` / `whereNull`, com o mesmo trim/case dos `VariantSizes`), dentro de uma
  transação; devolve a contagem. Atualizar o docblock ("a peça é a mesma" deixa de ser verdade entre tamanhos).
- **UI** `variant-production-table.tsx`: um bloco Horas/Min/Gramas **por tamanho** (título
  "10cm · 5 variantes"); com um grupo só fica como hoje. A tabela "Sugerido/Atual" fica
  agrupada pelos mesmos tamanhos. O botão "Guardar em todas" passa a "Guardar". Em `form.tsx:512`
  a nota passa a "Igual em todas as variantes do mesmo tamanho".
- `applyCalculatedPrices` não muda: já calcula variante a variante a partir do que está gravado.
- Testes (`tests/Feature/Admin/VariantProductionTest.php`): dois tamanhos ficam com valores
  diferentes; um produto sem tamanho continua a funcionar; um `size_label` que não existe é recusado; as arquivadas
  também são escritas (regra atual).

## Fase 2 — Criar tamanhos de uma vez (`feat/tamanhos-gerador`)

**a) "Novo tamanho" num produto que já existe.** Botão na lista de variantes que abre um
diálogo com: designação ("30cm"), preço normal/promo/revenda, gramas e tempo.
- Cria uma variante por cada combinação (cor, material) **ativa** que o produto já tem,
  com SKUs de `VariantSku::series()` (`app/Support/VariantSku.php`) e stock 0, através do
  `VariantService::store()` (a tradução normal/promo vive lá).
- Recusa um tamanho que o produto já tenha (com trim e sem distinguir maiúsculas).
- Rota `POST produtos/{product}/tamanhos`, ao lado de `produtos.variantes.producao` em `routes/web.php:365`,
  e um novo `VariantSizeController` + `StoreVariantSizeRequest`.
- Também se pode "copiar de" um tamanho existente: os mesmos pares cor/material desse tamanho,
  em vez de todos os do produto.

**b) O gerador na criação do produto** (`variant-matrix.tsx`, `StoreProductRequest.php:100-125`,
`ProductService::generateVariants`):
- Os chips fixos dão lugar a tamanhos livres. Cada tamanho é uma linha com designação, preço normal e revenda
  (promoção opcional), gramas e tempo.
- O formulário envia `variants.size_rows[]`. Os preços de topo ficam como valor por omissão para os
  tamanhos que não tenham preço próprio, e é isso que deixa o MCP continuar a funcionar.
- `ColorMaterialMatrix::combos` mantém-se. O `generateVariants` passa a ir buscar o preço e os dados de produção
  à linha do tamanho de cada combinação.

**c) MCP:**
- `product_create`: `variants.sizes` aceita, além de strings, objetos `{label, normal_price, wholesale_price, sale_price, filament_weight_grams, printing_time_minutes}`.
- Ferramenta nova `variants_add_size` (product_id, size_label, preços, produção, `copy_from_size?`), que reutiliza
  o serviço de (a).
- Atualizar `StudioServer::$tools`, as contagens em `McpSecurityTest` e a anotação de escrita.

Testes: `ProductCrudTest` (preços por tamanho), testes novos em `VariantSizeTest` (gera N variantes, recusa
duplicados, SKUs seguidos, copiar de um tamanho) e `CatalogWriteToolsTest`.

## Fase 3 — Organizar e proteger (`feat/tamanhos-lista`)

- **Lista agrupada** (`variant-rows.tsx` e `ProductController::variantRows` por volta de `:350`): quando há
  mais de um tamanho aparece um cabeçalho por grupo, com o nome, o número de variantes e o preço base. Com um só tamanho, nada muda.
- **"Mudar preço do tamanho"** no cabeçalho de cada grupo. O **preço base** é o preço normal mais comum
  entre as variantes ativas desse tamanho. Uma opção, ligada por omissão, faz com que só mudem as variantes
  que estão ao preço base, e as ajustadas (o Dourado) ficam como estão. O diálogo lista as que ficam de fora. A escrita passa
  pelo `VariantService::update()`.
- **Sem variantes repetidas**: `StoreVariantRequest` e `UpdateVariantRequest` recusam uma variante **ativa**
  com a mesma cor, material e tamanho (trim e sem distinguir maiúsculas) que outra variante ativa do produto. Fica só
  na validação, sem índice único na BD, porque podem já existir repetidas em produção e as arquivadas não contam.
- **Etiqueta completa na encomenda**: um `Variant::label()` que dá "Branco · PLA Mate · 20cm" (deixa de fora
  o que for null). Passa a ser usado em `OrderService.php:241` e `:843` e em `ManualOrderOptions.php:91-98`.
  As encomendas antigas ficam como estão, porque o snapshot não muda.

Testes: `VariantCrudTest` (repetida recusada, arquivada não conta), `ManualOrderTest`/`EtsyOrderImportTest`
(etiqueta), `ProductFormPageTest` (grupos).

## Fase 4 — Etsy com tamanhos (`fix/etsy-tamanhos`)

- **Peso e medidas** (`EtsyListingBuilder::measures()`): usar o **maior** peso e as **maiores** medidas
  das variantes ativas, em vez dos da primeira. Assim os portes nunca ficam abaixo do que custam. Quando variam, o
  preview avisa: "A Etsy só aceita um peso por anúncio: vai o do tamanho maior (20cm)."
- **Fundir o material na cor quando um depende do outro**: se cada cor ativa tem um só material
  (no produto 17, o Dourado está sempre em Slik e as outras sempre em Mate), o material deixa de ser uma variação e
  passa a fazer parte do valor da cor ("Dourado (PLA Slik)"). Assim o produto 17 fica com 2 propriedades
  (Cor e Tamanho, ids 513/514, já confirmados) e não depende do 516. Isto vive em
  `inventory()` (`:252-262`), e o `propertyMap()` mantém o id da cor.
  - Risco: num anúncio que já esteja ligado, deixar de ter a dimensão `material` mexe no `property_map`
    guardado. É preciso confirmar como o `propertyMap()` reage quando o conjunto de dimensões muda e cobrir isso com um teste.
- **Confirmar o 516** (do lado do dono, contra a Etsy a sério): enviar um rascunho de teste com 3 dimensões
  que não dependam umas das outras. Até lá fica como está. Lição de [[etsy-integracao-plano]]: o fake em
  `tests/Feature/Etsy/FakesEtsy.php` deve recusar o que a Etsy recusa assim que se souber.

Testes (`EtsyListingBuilderTest`): o material dependente funde-se na cor (fica com 2 propriedades), o material
independente continua com 3, o peso e as medidas são os máximos e o aviso aparece.

## Ficheiros centrais

- `app/Services/VariantProductionService.php`, `app/Http/Controllers/Admin/VariantProductionController.php` (+ request)
- `app/Support/VariantSizes.php` (novo), `app/Support/VariantSku.php`, `app/Support/ColorMaterialMatrix.php`
- `app/Services/ProductService.php`, `app/Services/VariantService.php`, `app/Services/OrderService.php`
- `app/Http/Requests/Product/StoreProductRequest.php`, `app/Http/Requests/Variant/{Store,Update}VariantRequest.php`
- `app/Etsy/EtsyListingBuilder.php`, `app/Mcp/Tools/Write/ProductCreateTool.php`, `app/Mcp/Tools/Write/Concerns/DescribesVariants.php`
- `resources/js/components/admin/{variant-production-table,variant-rows}.tsx`,
  `resources/js/components/admin/product-form/variant-matrix.tsx`, `resources/js/lib/production.ts`,
  `resources/js/pages/admin/produtos/form.tsx`
- `routes/web.php` (por volta de `:356-368`)

## Verificação

- Por fase, em PowerShell ([[portoes-de-qualidade-no-windows]]): `php artisan test` aos ficheiros tocados e
  depois à suite completa por partes, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run types:check`
  e `npm run lint:check`. Não fazer `composer update` ([[laravel-preso-na-13-34]]).
- No browser, com o dev server local (**BD local, nunca a de produção**), num produto igual ao 17 (2 tamanhos × 5 cores):
  - Fase 1: guardar tempos diferentes por tamanho → "Aplicar preços calculados" → os 10cm e os 20cm ficam com sugestões diferentes.
  - Fase 2: "Novo tamanho 30cm" cria 5 variantes; um produto novo com 10cm/20cm e preços próprios.
  - Fase 3: grupos na lista; "Mudar preço do tamanho" não mexe no Dourado; repetida recusada.
  - Fase 4: `etsy_listing_preview` ao produto 17 (só leitura) mostra Cor + Tamanho, e o peso é o do 20cm.
- Nada de push nem merge sem o dono pedir (merge em `main` faz deploy).
- Ordem de execução: 1 → 2 → 3 → 4. A Fase 1 é a única urgente (a UI atual pode estragar os dados do produto 17).
