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

    'preview' => [
        'closed' => 'Montra fechada — só tu vês isto. Os visitantes veem a página “em breve”.',
        'open_store' => 'Abrir a montra',
    ],
];
