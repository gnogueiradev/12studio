<?php

/*
| Textos da montra publica. Chegam ao React pela prop `i18n` (SetStoreLocale)
| e leem-se com o useT() de resources/js/lib/i18n.ts — t('nav.catalog').
| As mesmas chaves nos quatro idiomas: o StoreTranslationsTest falha quando
| falta uma.
*/

return [
    'nav' => [
        'home' => 'Início',
        'catalog' => 'Catálogo',
        'custom' => 'Personalizado',
        'about' => 'Sobre',
        'contact' => 'Contacto',
    ],

    'header' => [
        'main_nav' => 'Navegação principal',
        'open_menu' => 'Abrir menu',
        'menu' => 'Menu',
        'search' => 'Pesquisar',
        'search_placeholder' => 'Procurar peças…',
        'language' => 'Idioma',
        'skip_to_content' => 'Saltar para o conteúdo',
    ],

    'footer' => [
        'store' => 'Loja',
        'catalog' => 'Catálogo',
        'help' => 'Ajuda',
        'legal' => 'Legal',
        'faq' => 'Perguntas frequentes',
        'shipping' => 'Envios e devoluções',
        'terms' => 'Termos e condições',
        'privacy' => 'Privacidade',
        'complaints_book' => 'Livro de Reclamações',
        'rights' => 'Todos os direitos reservados.',
        'follow_us' => 'Segue-nos',
    ],

    'contact' => [
        'fab' => 'Fala connosco',
        'title' => 'Fala connosco',
        'intro' => 'Tens uma dúvida, uma ideia ou queres encomendar? Escreve-nos.',
        'response_time' => '{1} Respondemos normalmente em :count hora.|[2,*] Respondemos normalmente em :count horas.',
        'email' => 'Email',
        'phone' => 'Telefone',
        'hours' => 'Horário',
        'address' => 'Morada',
        'social' => 'Redes sociais',
        'form_soon' => 'Em breve vais poder escrever-nos diretamente aqui. Até lá, usa o email ou as redes.',
    ],

    'product' => [
        'from' => 'desde :price',
        'no_image' => 'Sem imagem',
        'colors' => 'Cores disponíveis',
        'badge' => [
            'new' => 'Novo',
            'sale' => 'Promoção',
            'made_to_order' => 'Por encomenda',
            'custom' => 'Personalizado',
            'sold_out' => 'Esgotado',
        ],
    ],

    'catalog' => [
        'results' => '{0} Nenhuma peça|{1} :count peça|[2,*] :count peças',
        'search_placeholder' => 'Pesquisar no catálogo…',
        'filters' => 'Filtros',
        'clear' => 'Limpar filtros',
        'apply' => 'Ver resultados',
        'categories' => 'Categorias',
        'all' => 'Todas',
        'colors' => 'Cor',
        'materials' => 'Material',
        'price' => 'Preço (€)',
        'price_min' => 'Mínimo',
        'price_max' => 'Máximo',
        'availability' => 'Disponibilidade',
        'any' => 'Todas',
        'ready' => 'Pronto a enviar',
        'to_order' => 'Feito por encomenda',
        'sort' => 'Ordenar',
        'sort_options' => [
            'destaques' => 'Destaques',
            'novidades' => 'Novidades',
            'preco-asc' => 'Preço: mais baixo',
            'preco-desc' => 'Preço: mais alto',
        ],
        'load_more' => 'Carregar mais',
        'loading' => 'A carregar…',
        'remove_filter' => 'Remover filtro: :name',
        'search_for' => 'Pesquisa: “:q”',
    ],

    'detail' => [
        'interest' => 'Tenho interesse',
        'ask' => 'Fazer uma pergunta',
        'color' => 'Cor',
        'material' => 'Material',
        'size' => 'Tamanho',
        'unavailable' => 'Indisponível nesta combinação',
        'availability' => [
            'ready' => 'Pronto a enviar',
            'made_to_order' => 'Feito por encomenda',
            'custom' => 'Peça personalizada',
            'sold_out' => 'Esgotado',
        ],
        'lead' => [
            'ready' => 'Envio em :min–:max dias úteis',
            'made_to_order' => 'Produzido em :min–:max dias úteis',
        ],
        'personalizable' => 'Personalizável: :fields',
        'surcharge' => '+ :price pela personalização',
        'description' => 'Descrição',
        'specs' => 'Especificações',
        'dimensions' => 'Dimensões',
        'weight' => 'Peso',
        'preview' => 'Pré-visualização: este produto não está à venda na montra.',
        'image' => 'Imagem :n de :total',
        'interest_intro' => 'Diz-nos que peça queres e respondemos com a disponibilidade, o prazo e o preço final.',
        'interest_soon' => 'Em breve vais poder enviar o pedido aqui mesmo. Até lá, escreve-nos por email — já vai com a peça escolhida.',
        'write_email' => 'Escrever email',
        'mail_subject' => 'Interesse: :product',
        'mail_body' => 'Olá! Tenho interesse em :product (:variant).',
        'selected' => 'A tua escolha',
    ],

    'error' => [
        'title_404' => 'Página não encontrada',
        'text_404' => 'O endereço pode estar errado, ou a página já não existe.',
        'title_500' => 'Algo correu mal',
        'text_500' => 'Tivemos um problema do nosso lado. Tenta outra vez daqui a pouco.',
        'title_503' => 'Voltamos já',
        'text_503' => 'Estamos em manutenção. Volta daqui a uns minutos.',
        'home' => 'Voltar ao início',
        'catalog' => 'Ver catálogo',
    ],

    'breadcrumb' => 'Caminho',

    'gallery' => [
        'title' => 'Galeria',
        'open' => 'Ampliar imagem :n de :total',
        'previous' => 'Imagem anterior',
        'next' => 'Imagem seguinte',
    ],

    'preview' => [
        'draft' => 'Rascunho — esta página ainda não está publicada. Só tu a vês.',
        'closed' => 'Montra fechada — só tu vês isto. Os visitantes veem a página “em breve”.',
        'open_store' => 'Abrir a montra',
    ],
];
