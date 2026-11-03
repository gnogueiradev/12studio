# Construtor de páginas à Elementor

**Data:** 2026-10-28
**Ramo:** `staging`, ou um ramo a partir dele, porque a montra ainda não está no `main`.
**Esboço aprovado:** `.superpowers/brainstorm/705-1793224516/content/editor-elementor-v2.html`

## Porquê

O dono acha o construtor atual fraco. Hoje uma página é uma lista de blocos
prontos, cada um com o seu desenho fixo. Não dá para pôr duas coisas lado a
lado à escolha, nem montar uma secção à medida. O dono quer "o que o Elementor
tem de builder":

- contentores com linhas e colunas metidos uns dentro dos outros;
- elementos pequenos para largar lá dentro;
- uma árvore que abre e fecha (o Navegador);
- estilo por elemento, diferente em computador, tablet e telemóvel.

Fica bem feito quando o dono consegue montar sozinho uma secção do género
"título e texto à esquerda, imagem à direita, três ícones por baixo" sem pedir
um bloco novo a ninguém.

## Objetivos

1. **Contentores aninhados.** Um contentor tem filhos que se dispõem em linha
   ou em coluna. As colunas também são contentores. Assim fazem-se linhas
   dentro de colunas, como no Elementor com Flexbox Containers.
2. **Elementos básicos novos:** Título, Texto, Imagem, Botão, Ícone, Divisor,
   Vídeo, Lista com ícones e Citação. O Espaço já existe.
3. **Os blocos que já existem passam a ser elementos "Da loja"** e entram em
   qualquer coluna: destaque, grelha de produtos, categorias, texto e imagem,
   faixa, galeria, como funciona, perguntas, testemunhos, destaques de
   confiança, contacto, pedido personalizado. Os blocos dos modelos (cabeçalho
   e grelha do catálogo, migalhas, produto principal, relacionados) continuam
   a ser exclusivos do seu modelo.
4. **O editor tem três zonas:**
   - **Painel esquerdo** com dois separadores:
     - "Elementos": grelha com pesquisa, agrupada em Estrutura, Básicos e Da
       loja.
     - "Editar": o elemento selecionado, com os separadores Conteúdo, Estilo e
       Avançado.
   - **A página ao centro**, ao vivo e no tamanho real do dispositivo (como
     já está).
   - **O Navegador**, um painel à direita que se abre e fecha, com a árvore.
5. **Arrastar e largar:**
   - da grelha de elementos para a página: largar entre elementos ou dentro de
     uma coluna, com uma linha a mostrar onde vai cair;
   - dentro da página: pela pega na etiqueta do elemento;
   - no Navegador: mudar a ordem e meter um elemento noutra coluna.
6. **Escolher estrutura:** o "+ ▥" no fim da página e entre contentores abre
   as estruturas 1 coluna, 50/50, 66/33, 33/66, 3 colunas e 4 colunas. Cria
   um contentor em linha com as colunas lá dentro.
7. **Valores por dispositivo.** Em Estilo e em Avançado, cada valor pode ser
   diferente em computador, tablet e telemóvel. O que não se muda herda do
   tamanho acima, como no Elementor. O seletor de dispositivo do topo muda
   também o dispositivo que se está a editar.
8. **As páginas que já existem convertem-se sozinhas e não se perde nada:**
   cada bloco atual passa a ser um contentor com esse elemento lá dentro, e o
   contentor herda o fundo, o espaçamento, a largura e o alinhamento do bloco.
9. **Continua tudo o que já existe:**
   - gravar sozinho em rascunho;
   - publicar e tirar do ar;
   - histórico e repor;
   - desfazer e refazer;
   - atalhos;
   - idiomas;
   - SEO;
   - landing pages com datas;
   - os modelos de catálogo e de produto;
   - as miniaturas da lista de páginas.

## Não-objetivos (o "Elementor Pro" que fica de fora)

- Animações e efeitos de movimento.
- Formulários à medida (o Contacto e o Pedido personalizado continuam como
  elementos prontos).
- Guardar secções como modelos reutilizáveis, e elementos globais.
- Editar o texto diretamente na página. Edita-se no painel, e a página
  atualiza ao vivo.
- CSS próprio, código HTML livre e scripts.
- Construir o cabeçalho e o rodapé da loja: continuam nos Menus e no Tema.
- Popups.

## Anti-objetivos (falha mesmo que funcione)

- **Cores livres.** As cores vêm sempre da paleta do Tema (tokens), como hoje.
  Cores à vontade estragavam o contraste: neste projeto o WCAG ganha à marca.
- **Uma página publicada que mude ou parta por causa da conversão.** Depois
  de convertida, cada página tem de ficar igual ao que era.
- **Dados de clientes ou HTML sem purificar a sair de um elemento.** O
  sanitizador continua a ser a única porta de entrada.
- **Um editor lento.** Uma página típica (até ~60 elementos) tem de responder
  sem se notar.

## Restrições

- Laravel 13.34 preso. Não há `composer update` nem pacotes novos de PHP. Do
  lado do npm só entra um pacote novo se for indispensável e com motivo.
- As páginas guardam-se nas colunas JSON que já existem: `draft_blocks`,
  `published_blocks` e `blocks` das versões. Não há migração de esquema.
- A montra ainda não está em produção, por isso não há dados reais a
  proteger. A conversão serve para as BDs locais e para as páginas de origem.
- O CSP mantém-se apertado. O Vídeo obriga a abrir `frame-src` só para
  `https://www.youtube-nocookie.com` e `https://player.vimeo.com`, e só nas
  páginas da montra.
- Tailwind só com tokens, e o DesignTokensTest tem de passar. Os valores
  numéricos livres (espaçamentos, larguras) vão por variáveis CSS e não por
  classes geradas.
- O backoffice está em português e a montra em quatro idiomas: os textos dos
  elementos continuam traduzíveis campo a campo.

## Como fica — o que o dono decidiu

1. Um construtor ao estilo do Elementor, como no esboço aprovado: elementos à
   esquerda, a página ao centro e o Navegador em árvore à direita.
2. Contentores com 1 a 4 colunas escolhidos por estrutura, e colunas que
   levam elementos ou outros contentores.
3. Os blocos atuais continuam e passam a elementos "Da loja".
4. Painel "Editar" com Conteúdo, Estilo e Avançado, e valores por
   dispositivo.
5. As páginas atuais convertem-se sozinhas.

## Como fica — decisões minhas (o construtor pode afiná-las)

### O documento da página

Uma página passa a ser uma árvore de **nós**, guardada como `version: 2`:

```
Nó = {
  id, type, v, visible,
  settings: {...},          // como hoje: campos não traduzíveis
  content: {locale: {...}}, // como hoje: campos traduzíveis
  style: { [prop]: { desktop?, tablet?, mobile? } },  // por dispositivo
  advanced: { [prop]: { desktop?, tablet?, mobile? } },
  children?: Nó[]           // só nos contentores
}
```

- **`container`** é o único nó com filhos. As propriedades por dispositivo
  são:
  - direção (linha ou coluna);
  - largura no pai (%, ou automática);
  - espaço entre filhos;
  - alinhamento e distribuição;
  - se passa à linha seguinte;
  - preenchimento e margem;
  - largura máxima: contida (max-w-6xl) ou ecrã inteiro;
  - altura mínima;
  - fundo: um token do tema, ou uma imagem com sobreposição;
  - cantos arredondados;
  - esconder neste dispositivo.
- **Profundidade máxima: 4 contentores.** Chega para secção > coluna >
  contentor interior > coluna.
- **Limites:** até 400 nós por página e até 6 filhos diretos num contentor em
  linha. O servidor recusa o resto com um erro que se perceba.
- **Na raiz** só há contentores. Um elemento largado na raiz ganha um
  contentor à volta automaticamente, como faz o Elementor.
- **Responsivo, por omissão:** um contentor em linha passa a coluna no
  telemóvel, a não ser que o dono mude isso no telemóvel.

### Elementos

- Cada elemento é uma classe `Block`, como hoje, com:
  - `fields()` para o Conteúdo;
  - um `styleFields()` novo, com os controlos de Estilo próprios do elemento,
    por exemplo o tamanho do título, a cor do texto (token) ou a variante do
    botão.
- O separador Avançado é igual para todos os elementos: margem, preenchimento
  e esconder por dispositivo.
- Elementos novos:

  | Elemento | O que tem |
  |---|---|
  | `heading` | Nível h1 a h4, tamanho e alinhamento |
  | `paragraph` | Rich text, com o purificador que já existe |
  | `image` | Imagem da biblioteca, legenda, link e proporção |
  | `button` | Texto, link e variante: principal, contorno ou link |
  | `icon` | Os ícones do `Field::ICONS`, com tamanho e cor por token |
  | `divider` | Linha, com espessura e largura |
  | `video` | URL do YouTube ou do Vimeo, transformada em embed nocookie; proporção |
  | `icon_list` | Repeater de ícone + texto + link opcional |
  | `quote` | Texto e autor |

- **Quem desenha a secção num elemento "Da loja" (decisão minha, depois da
  revisão).** Cada elemento "Da loja" mantém o seu `style` v1 (fundo,
  espaçamento, largura e alinhamento) e um interruptor novo, "Casca de
  secção", no separador Estilo.
  - **Ligado:** o elemento desenha-se exatamente como hoje, com o
    `BlockSection`, a variante hero `background`, o banner com imagem, o
    `align` a mandar no layout interno e o espaçador sem casca. É o estado
    dos elementos convertidos e dos que se largam sozinhos na raiz.
  - **Desligado:** o elemento vem sem casca (`nested`) e quem dá fundo e
    espaçamento é o contentor. É o estado com que nasce um elemento largado
    numa coluna.
  - **Na conversão, o contentor à volta fica transparente:** largura total,
    sem preenchimento, sem fundo e com uma só coluna. A página converte-se
    para ficar *visualmente* igual, e não só com os mesmos dados.
  - O `align` continua no elemento, porque comanda o layout interno de vários
    blocos. O contentor tem o seu próprio alinhamento, que só se aplica aos
    elementos básicos.
- **Os elementos "Da loja" adaptam-se à largura da coluna e não ao ecrã
  (decisão minha).** As grelhas e os layouts internos passam de `md:`/`lg:`
  para container queries do Tailwind v4 (`@container` com `@md:`/`@lg:`).
  Abrange a grelha de produtos, categorias, galeria, testemunhos, como
  funciona, destaques de confiança, texto e imagem, destaque e faixa.
  - Os pontos de quebra escolhem-se para que, na largura de hoje (contida em
    `max-w-6xl`, ou ecrã inteiro), o resultado seja o mesmo que hoje.
  - O `sizes` das imagens fica aproximado pela largura do ecrã. Não muda o
    que se vê, só o peso.
- **Modelos:**
  - `product_main`, `catalog_grid`, `catalog_header`, `breadcrumb` e
    `product_related` ficam só na raiz: cada um sozinho num contentor de raiz
    e sem entrar em colunas.
  - `product_main` e `catalog_grid` continuam obrigatórios e únicos. Movem-se
    entre contentores de raiz, mas não se apagam.
  - O sanitizador valida as duas regras.

### Conversão (v1 → v2)

- Uma função pura, `PageDocument::upgrade(array): array`, transforma cada
  bloco v1 num `container` de raiz transparente (largura total, sem
  preenchimento nem fundo) com o bloco lá dentro. O bloco guarda o seu
  `style` v1, e a "Casca de secção" fica ligada. Ver a decisão sobre "quem
  desenha a secção" na secção dos elementos.
- Corre ao ler (sanitizador, renderizador e editor), por isso as revisões
  antigas continuam a repor-se bem.
- O `DefaultPages` passa a escrever v2.
- Um teste prova que cada página de origem convertida gera, para cada bloco,
  os mesmos dados no renderizador.
- Também é preciso uma verificação **visual**: cada página de origem (início,
  catálogo, produto, sobre, personalizado, contacto, perguntas, termos,
  privacidade e envios) em computador e em telemóvel, antes e depois,
  comparadas por captura de ecrã.

### Renderizar

- O `PageRenderer` torna-se recursivo e devolve a árvore, só com os nós
  visíveis, já resolvidos no idioma e com as imagens pré-carregadas na árvore
  inteira.
- Do lado da loja:
  - um `NodeTree` desenha os contentores com `display:flex`;
  - os valores por dispositivo saem como variáveis CSS
    (`--pb-gap-d`, `--pb-gap-t`, `--pb-gap-m`…), lidas por uma folha de
    estilos fixa com as media queries `md` e `lg` do Tailwind;
  - cada elemento usa o componente com o mesmo `type`, e o
    `BlockComponentsTest` continua a obrigar a que haja um componente por
    tipo.

### O editor

- **O estado** é a árvore. Seleção, desfazer e refazer, gravação automática,
  fila de gravações, `flush` e a proteção ao sair mantêm-se. Só passam a
  trabalhar sobre nós com caminho.
- **Painel esquerdo:** separador "Elementos" (grelha com pesquisa) e "Editar"
  (Conteúdo, Estilo e Avançado). Ao selecionar um elemento passa para
  "Editar"; com Esc, ou com o botão "Elementos", volta à grelha. O inspetor
  que está hoje à direita desaparece.
- **Navegador:** é um painel à direita que se abre e fecha (como hoje, quando
  há um bloco selecionado). Tem:
  - a árvore com ▾/▸;
  - ícone e nome de cada nó; os contentores com as colunas resumidas
    ("2 colunas"; os nomes dos nós podem ser mudados);
  - o olho para esconder e o menu "…" com duplicar e apagar;
  - arrastar com o dnd-kit (entre irmãos e para dentro de um contentor, com
    indicação de profundidade).
- **A página, em modo de edição dentro do iframe:**
  - um contorno quando o rato passa por cima;
  - quando um nó está selecionado: um contorno azul e uma etiqueta com o
    nome, uma pega para arrastar, duplicar e apagar (como no esboço);
  - nos contentores vazios, a zona "+ ▥ Arrasta um elemento para aqui".
- **Arrastar para a página:** HTML5 drag and drop nativo.
  - O editor guarda o tipo que está a ser arrastado.
  - O iframe (mesma origem) trata o `dragover`, calcula o alvo (nó e posição:
    antes, depois ou dentro) e desenha a linha.
  - No `drop`, o iframe manda `{kind:'drop', target, position}` por
    postMessage.
  - Arrastar um nó já existente dentro do iframe manda `{kind:'move', …}`.
  - O protocolo continua com `source: 'studio-preview' | 'studio-editor'`.
- **Escolher estrutura:** um popover com as 6 estruturas, no "+ ▥" do fim da
  página e do Navegador.
- **Valores por dispositivo:** cada controlo de Estilo e de Avançado tem um
  pequeno ícone do dispositivo que se está a editar. Se o valor vem herdado,
  mostra-o esbatido com "herdado do computador", e há um botão para limpar o
  valor deste dispositivo.
- **Atalhos:** Delete/Backspace e Ctrl+D (duplicar) atuam sobre o nó
  selecionado, com o foco no Navegador ou na página. Ctrl+C e Ctrl+V copiam e
  colam o nó, mesmo entre páginas, através do `localStorage`.
- **A lista de páginas e as miniaturas** não mudam. A pré-visualização
  desenha v2.

### Servidor

- Um `BlockSanitizer` recursivo:
  - valida o tipo e o modelo, a profundidade e os limites;
  - garante ids únicos em toda a árvore;
  - limpa as propriedades por dispositivo contra uma lista branca, com
    limites numéricos (por exemplo, preenchimento de 0 a 200 px);
  - deixa as cores só como tokens;
  - verifica os campos de cada elemento como hoje.

  Os erros voltam com caminhos `nodes.0.children.1.content.pt.title`.
- O `BlockRegistry::schema()` passa a incluir os elementos novos e o
  `styleFields`, e um `containerSchema()`.
- **MCP:** o `PageGetTool` e o `PagesListTool` devolvem v2. Se houver
  ferramentas MCP que escrevem páginas, ficam a recusar v1 ou passam por
  `upgrade`.

## Fica para o construtor

- Os nomes exatos das propriedades e as escalas (por exemplo, se o "espaço
  entre filhos" é livre em px ou em passos).
- Como se divide o código do editor por ficheiros.
- A ordem de trabalho (está no plano).
- Os ícones de cada elemento.

## Testes

- PHP:
  - sanitizador: árvore, limites, profundidade, tokens e caminhos de erro;
  - conversão v1 → v2 nas páginas de origem e nas revisões;
  - renderizador recursivo;
  - cada elemento novo: campos e purificação;
  - CSP com o vídeo;
  - MCP.
- Frontend: tsc, ESLint e Prettier. Uma verificação no browser de:
  - criar uma secção 66/33 com título, texto, botão e imagem;
  - arrastar da grelha para a página;
  - mudar o preenchimento no telemóvel;
  - desfazer;
  - publicar;
  - ver a loja.
