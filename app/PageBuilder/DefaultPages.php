<?php

namespace App\PageBuilder;

/**
 * O conteudo com que cada pagina de sistema nasce: um site completo desde o
 * primeiro dia, que o dono depois muda no construtor. Textos genericos de
 * proposito — nao falam pela marca, e cada loja escreve os seus.
 *
 * As paginas legais nascem em rascunho e so com um esqueleto: o texto legal
 * tem de ser escrito por quem responde por ele.
 */
class DefaultPages
{
    /** Paginas que nascem em rascunho (nao aparecem ao publico). */
    public const DRAFTS = ['terms', 'privacy', 'shipping'];

    /** Nome no backoffice. */
    public const NAMES = [
        'home' => 'Início',
        'catalog' => 'Catálogo',
        'product' => 'Página de produto',
        'about' => 'Sobre',
        'custom' => 'Peça personalizada',
        'contact' => 'Contacto',
        'faq' => 'Perguntas frequentes',
        'terms' => 'Termos e condições',
        'privacy' => 'Privacidade',
        'shipping' => 'Envios e devoluções',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function blocks(string $key): array
    {
        return match ($key) {
            'home' => self::home(),
            'catalog' => self::catalog(),
            'product' => self::product(),
            'about' => self::about(),
            'custom' => self::custom(),
            'contact' => self::contact(),
            'faq' => self::faq(),
            'terms', 'privacy', 'shipping' => self::legal($key),
            default => [],
        };
    }

    /**
     * Titulos de SEO por idioma (o <title> de cada pagina).
     *
     * @return array<string, array{title: string}>
     */
    public static function seo(string $key): array
    {
        $titles = [
            'home' => ['pt' => '', 'es' => '', 'en' => '', 'fr' => ''],
            'catalog' => ['pt' => 'Catálogo', 'es' => 'Catálogo', 'en' => 'Shop', 'fr' => 'Catalogue'],
            'about' => ['pt' => 'Sobre nós', 'es' => 'Sobre nosotros', 'en' => 'About us', 'fr' => 'À propos'],
            'custom' => ['pt' => 'Peça personalizada', 'es' => 'Pieza personalizada', 'en' => 'Custom piece', 'fr' => 'Pièce sur mesure'],
            'contact' => ['pt' => 'Contacto', 'es' => 'Contacto', 'en' => 'Contact', 'fr' => 'Contact'],
            'faq' => ['pt' => 'Perguntas frequentes', 'es' => 'Preguntas frecuentes', 'en' => 'FAQ', 'fr' => 'Questions fréquentes'],
            'terms' => ['pt' => 'Termos e condições', 'es' => 'Términos y condiciones', 'en' => 'Terms and conditions', 'fr' => 'Conditions générales'],
            'privacy' => ['pt' => 'Política de privacidade', 'es' => 'Política de privacidad', 'en' => 'Privacy policy', 'fr' => 'Politique de confidentialité'],
            'shipping' => ['pt' => 'Envios e devoluções', 'es' => 'Envíos y devoluciones', 'en' => 'Shipping and returns', 'fr' => 'Livraisons et retours'],
        ];

        return collect($titles[$key] ?? [])
            ->filter()
            ->map(fn (string $title): array => ['title' => $title])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $content
     * @param  array<string, string>  $style
     * @return array<string, mixed>
     */
    private static function block(string $type, ?string $variant = null, array $settings = [], array $content = [], array $style = []): array
    {
        return array_filter([
            'type' => $type,
            'variant' => $variant,
            'settings' => $settings,
            'content' => $content,
            'style' => $style,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{label: string, href: string}
     */
    private static function link(string $label, string $href): array
    {
        return ['label' => $label, 'href' => $href];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function home(): array
    {
        return [
            self::block('hero', 'split', [], [
                'pt' => [
                    'eyebrow' => 'Feito à mão em Portugal',
                    'title' => 'Peças impressas em 3D, desenhadas com cuidado.',
                    'subtitle' => 'Decoração, gadgets e peças personalizadas — impressas peça a peça no nosso estúdio.',
                    'primary' => self::link('Ver catálogo', '/produtos'),
                    'secondary' => self::link('Pedir peça personalizada', '/personalizado'),
                ],
                'es' => [
                    'eyebrow' => 'Hecho a mano en Portugal',
                    'title' => 'Piezas impresas en 3D, diseñadas con cuidado.',
                    'subtitle' => 'Decoración, gadgets y piezas personalizadas, impresas una a una en nuestro estudio.',
                    'primary' => self::link('Ver catálogo', '/produtos'),
                    'secondary' => self::link('Pedir una pieza personalizada', '/personalizado'),
                ],
                'en' => [
                    'eyebrow' => 'Handmade in Portugal',
                    'title' => '3D-printed pieces, designed with care.',
                    'subtitle' => 'Home decor, gadgets and custom pieces — printed one by one in our studio.',
                    'primary' => self::link('Browse the shop', '/produtos'),
                    'secondary' => self::link('Request a custom piece', '/personalizado'),
                ],
                'fr' => [
                    'eyebrow' => 'Fait main au Portugal',
                    'title' => 'Des pièces imprimées en 3D, conçues avec soin.',
                    'subtitle' => 'Déco, gadgets et pièces sur mesure — imprimés pièce par pièce dans notre atelier.',
                    'primary' => self::link('Voir le catalogue', '/produtos'),
                    'secondary' => self::link('Demander une pièce sur mesure', '/personalizado'),
                ],
            ]),
            self::block('trust', null, [], [
                'pt' => ['items' => [
                    ['icon' => 'printer', 'title' => 'Impresso no nosso estúdio', 'text' => 'Peça a peça, com controlo de qualidade'],
                    ['icon' => 'palette', 'title' => 'Cores à escolha', 'text' => 'Vários materiais e acabamentos'],
                    ['icon' => 'message-circle', 'title' => 'Falamos contigo', 'text' => 'Sem pagamentos às cegas'],
                    ['icon' => 'package', 'title' => 'Embalado com cuidado', 'text' => 'Protegido até chegar a ti'],
                ]],
                'es' => ['items' => [
                    ['icon' => 'printer', 'title' => 'Impreso en nuestro estudio', 'text' => 'Pieza a pieza, con control de calidad'],
                    ['icon' => 'palette', 'title' => 'Colores a elegir', 'text' => 'Varios materiales y acabados'],
                    ['icon' => 'message-circle', 'title' => 'Hablamos contigo', 'text' => 'Sin pagos a ciegas'],
                    ['icon' => 'package', 'title' => 'Embalado con cuidado', 'text' => 'Protegido hasta tu casa'],
                ]],
                'en' => ['items' => [
                    ['icon' => 'printer', 'title' => 'Printed in our studio', 'text' => 'One by one, quality-checked'],
                    ['icon' => 'palette', 'title' => 'Choose your colour', 'text' => 'Several materials and finishes'],
                    ['icon' => 'message-circle', 'title' => 'We talk to you first', 'text' => 'No blind payments'],
                    ['icon' => 'package', 'title' => 'Carefully packed', 'text' => 'Protected all the way to you'],
                ]],
                'fr' => ['items' => [
                    ['icon' => 'printer', 'title' => 'Imprimé dans notre atelier', 'text' => 'Pièce par pièce, contrôlée'],
                    ['icon' => 'palette', 'title' => 'Couleurs au choix', 'text' => 'Plusieurs matières et finitions'],
                    ['icon' => 'message-circle', 'title' => 'On en parle d’abord', 'text' => 'Pas de paiement à l’aveugle'],
                    ['icon' => 'package', 'title' => 'Emballé avec soin', 'text' => 'Protégé jusqu’à chez vous'],
                ]],
            ]),
            self::block('categories', 'mosaic', ['limit' => 6], [
                'pt' => ['title' => 'Explora por categoria'],
                'es' => ['title' => 'Explora por categoría'],
                'en' => ['title' => 'Shop by category'],
                'fr' => ['title' => 'Explorer par catégorie'],
            ]),
            self::block('product_grid', null, ['source' => 'featured', 'columns' => '4', 'limit' => 8], [
                'pt' => ['title' => 'Destaques', 'link' => self::link('Ver tudo', '/produtos')],
                'es' => ['title' => 'Destacados', 'link' => self::link('Ver todo', '/produtos')],
                'en' => ['title' => 'Featured', 'link' => self::link('View all', '/produtos')],
                'fr' => ['title' => 'À la une', 'link' => self::link('Tout voir', '/produtos')],
            ]),
            self::howItWorks(),
            self::block('banner', null, [], [
                'pt' => ['title' => 'Tens uma ideia?', 'text' => 'Desenhamos e imprimimos peças à medida: presentes, decoração, peças de substituição.', 'link' => self::link('Pedir peça personalizada', '/personalizado')],
                'es' => ['title' => '¿Tienes una idea?', 'text' => 'Diseñamos e imprimimos piezas a medida: regalos, decoración, piezas de repuesto.', 'link' => self::link('Pedir una pieza personalizada', '/personalizado')],
                'en' => ['title' => 'Got an idea?', 'text' => 'We design and print made-to-measure pieces: gifts, home decor, replacement parts.', 'link' => self::link('Request a custom piece', '/personalizado')],
                'fr' => ['title' => 'Une idée ?', 'text' => 'Nous concevons et imprimons des pièces sur mesure : cadeaux, déco, pièces de rechange.', 'link' => self::link('Demander une pièce sur mesure', '/personalizado')],
            ], ['bg' => 'dark', 'spacing' => 'lg', 'width' => 'contained', 'align' => 'center']),
            self::contactBlock(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function howItWorks(): array
    {
        return self::block('steps', null, [], [
            'pt' => [
                'title' => 'Como funciona',
                'subtitle' => 'Ainda não há pagamentos no site: combinamos tudo contigo, por mensagem.',
                'steps' => [
                    ['icon' => 'sparkles', 'title' => 'Escolhe ou descreve', 'text' => 'Escolhe uma peça do catálogo ou conta-nos a tua ideia.'],
                    ['icon' => 'message-circle', 'title' => 'Falamos contigo', 'text' => 'Confirmamos cor, tamanho, prazo e preço — sem compromisso.'],
                    ['icon' => 'printer', 'title' => 'Imprimimos e enviamos', 'text' => 'Produzimos a tua peça e enviamo-la bem embalada.'],
                ],
            ],
            'es' => [
                'title' => 'Cómo funciona',
                'subtitle' => 'Todavía no hay pagos en la web: lo acordamos todo contigo por mensaje.',
                'steps' => [
                    ['icon' => 'sparkles', 'title' => 'Elige o describe', 'text' => 'Elige una pieza del catálogo o cuéntanos tu idea.'],
                    ['icon' => 'message-circle', 'title' => 'Hablamos contigo', 'text' => 'Confirmamos color, tamaño, plazo y precio, sin compromiso.'],
                    ['icon' => 'printer', 'title' => 'Imprimimos y enviamos', 'text' => 'Fabricamos tu pieza y te la enviamos bien embalada.'],
                ],
            ],
            'en' => [
                'title' => 'How it works',
                'subtitle' => 'There is no online checkout yet: we sort everything out with you by message.',
                'steps' => [
                    ['icon' => 'sparkles', 'title' => 'Choose or describe', 'text' => 'Pick a piece from the shop or tell us your idea.'],
                    ['icon' => 'message-circle', 'title' => 'We get in touch', 'text' => 'We confirm colour, size, lead time and price — no strings attached.'],
                    ['icon' => 'printer', 'title' => 'We print and ship', 'text' => 'We make your piece and send it well packed.'],
                ],
            ],
            'fr' => [
                'title' => 'Comment ça marche',
                'subtitle' => 'Pas encore de paiement en ligne : on règle tout avec vous par message.',
                'steps' => [
                    ['icon' => 'sparkles', 'title' => 'Choisissez ou décrivez', 'text' => 'Choisissez une pièce du catalogue ou racontez-nous votre idée.'],
                    ['icon' => 'message-circle', 'title' => 'On vous répond', 'text' => 'Nous confirmons couleur, taille, délai et prix — sans engagement.'],
                    ['icon' => 'printer', 'title' => 'On imprime et on expédie', 'text' => 'Nous fabriquons votre pièce et l’envoyons bien emballée.'],
                ],
            ],
        ], ['bg' => 'muted', 'spacing' => 'lg', 'width' => 'contained', 'align' => 'center']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function contactBlock(): array
    {
        return self::block('contact', null, ['show_form' => true, 'show_details' => true], [
            'pt' => ['title' => 'Fala connosco', 'text' => 'Dúvidas sobre uma peça, um pedido especial ou só curiosidade? Escreve-nos.'],
            'es' => ['title' => 'Háblanos', 'text' => '¿Dudas sobre una pieza, un pedido especial o simple curiosidad? Escríbenos.'],
            'en' => ['title' => 'Talk to us', 'text' => 'Questions about a piece, a special request or just curious? Write to us.'],
            'fr' => ['title' => 'Contactez-nous', 'text' => 'Une question sur une pièce, une demande spéciale ou simple curiosité ? Écrivez-nous.'],
        ]);
    }

    /**
     * @param  array<string, string>  $titles
     * @param  array<string, string>  $subtitles
     * @return array<string, mixed>
     */
    private static function pageHeader(array $titles, array $subtitles = []): array
    {
        $content = [];

        foreach ($titles as $locale => $title) {
            $content[$locale] = array_filter(['title' => $title, 'subtitle' => $subtitles[$locale] ?? '']);
        }

        return self::block('hero', 'centered', [], $content, ['bg' => 'default', 'spacing' => 'md', 'width' => 'contained', 'align' => 'center']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function catalog(): array
    {
        return [
            self::block('catalog_header', null, [], [
                'pt' => ['title' => 'Catálogo', 'text' => 'Todas as nossas peças, impressas por encomenda ou prontas a enviar.'],
                'es' => ['title' => 'Catálogo', 'text' => 'Todas nuestras piezas, impresas bajo pedido o listas para enviar.'],
                'en' => ['title' => 'Shop', 'text' => 'All our pieces, printed to order or ready to ship.'],
                'fr' => ['title' => 'Catalogue', 'text' => 'Toutes nos pièces, imprimées à la commande ou prêtes à partir.'],
            ], ['bg' => 'default', 'spacing' => 'sm', 'width' => 'contained', 'align' => 'start']),
            self::block('catalog_grid', 'filters_side', [], [
                'pt' => ['empty_title' => 'Nenhuma peça com estes filtros', 'empty_text' => 'Experimenta limpar os filtros — ou pede-nos uma peça personalizada.'],
                'es' => ['empty_title' => 'Ninguna pieza con estos filtros', 'empty_text' => 'Prueba a quitar los filtros, o pídenos una pieza personalizada.'],
                'en' => ['empty_title' => 'No pieces match these filters', 'empty_text' => 'Try clearing the filters — or ask us for a custom piece.'],
                'fr' => ['empty_title' => 'Aucune pièce avec ces filtres', 'empty_text' => 'Essayez d’effacer les filtres — ou demandez-nous une pièce sur mesure.'],
            ]),
            self::block('banner', null, [], [
                'pt' => ['title' => 'Não encontras o que procuras?', 'text' => 'Fazemos peças à medida.', 'link' => self::link('Pedir peça personalizada', '/personalizado')],
                'es' => ['title' => '¿No encuentras lo que buscas?', 'text' => 'Hacemos piezas a medida.', 'link' => self::link('Pedir una pieza personalizada', '/personalizado')],
                'en' => ['title' => 'Can’t find what you are looking for?', 'text' => 'We make pieces to measure.', 'link' => self::link('Request a custom piece', '/personalizado')],
                'fr' => ['title' => 'Vous ne trouvez pas votre bonheur ?', 'text' => 'Nous fabriquons des pièces sur mesure.', 'link' => self::link('Demander une pièce sur mesure', '/personalizado')],
            ], ['bg' => 'accent', 'spacing' => 'md', 'width' => 'contained', 'align' => 'center']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function product(): array
    {
        return [
            self::block('breadcrumb'),
            self::block('product_main', 'gallery_left', ['thumbnails' => 'bottom', 'show_specs' => true], [
                'pt' => [
                    'shipping_title' => 'Envio e devoluções',
                    'shipping_text' => '<p>Cada peça é embalada com cuidado. Os custos e o prazo de envio são combinados contigo antes do pagamento.</p>',
                    'custom_note' => 'Peça feita à medida para ti: por ser personalizada, não tem direito de livre resolução.',
                    'interest_note' => 'Sem compromisso — respondemos com disponibilidade, prazo e formas de pagamento.',
                ],
                'es' => [
                    'shipping_title' => 'Envíos y devoluciones',
                    'shipping_text' => '<p>Cada pieza se embala con cuidado. Los gastos y el plazo de envío se acuerdan contigo antes del pago.</p>',
                    'custom_note' => 'Pieza hecha a medida para ti: al ser personalizada, no tiene derecho de desistimiento.',
                    'interest_note' => 'Sin compromiso: te respondemos con disponibilidad, plazo y formas de pago.',
                ],
                'en' => [
                    'shipping_title' => 'Shipping and returns',
                    'shipping_text' => '<p>Every piece is carefully packed. Shipping costs and times are agreed with you before payment.</p>',
                    'custom_note' => 'Made to measure for you: as a personalised item, it is not covered by the right of withdrawal.',
                    'interest_note' => 'No obligation — we reply with availability, lead time and payment options.',
                ],
                'fr' => [
                    'shipping_title' => 'Livraison et retours',
                    'shipping_text' => '<p>Chaque pièce est emballée avec soin. Les frais et délais de livraison sont convenus avec vous avant le paiement.</p>',
                    'custom_note' => 'Pièce faite sur mesure pour vous : étant personnalisée, elle n’ouvre pas droit à la rétractation.',
                    'interest_note' => 'Sans engagement — nous répondons avec la disponibilité, le délai et les moyens de paiement.',
                ],
            ]),
            self::block('product_related', null, ['limit' => 4], [
                'pt' => ['title' => 'Também podes gostar'],
                'es' => ['title' => 'También te puede gustar'],
                'en' => ['title' => 'You may also like'],
                'fr' => ['title' => 'Vous aimerez aussi'],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function about(): array
    {
        return [
            self::pageHeader(
                ['pt' => 'Sobre nós', 'es' => 'Sobre nosotros', 'en' => 'About us', 'fr' => 'À propos'],
                [
                    'pt' => 'Um pequeno estúdio de impressão 3D, onde cada peça é desenhada, impressa e acabada à mão.',
                    'es' => 'Un pequeño estudio de impresión 3D donde cada pieza se diseña, imprime y acaba a mano.',
                    'en' => 'A small 3D printing studio where every piece is designed, printed and finished by hand.',
                    'fr' => 'Un petit atelier d’impression 3D où chaque pièce est conçue, imprimée et finie à la main.',
                ],
            ),
            self::block('text_image', 'image_right', [], [
                'pt' => ['title' => 'Do ficheiro à peça', 'body' => '<p>Cada peça começa num desenho e passa horas na impressora, camada a camada. Depois é verificada, limpa e acabada à mão antes de seguir para ti.</p><p>Como imprimimos por encomenda, conseguimos ajustar cores, tamanhos e pormenores ao que precisas.</p>'],
                'es' => ['title' => 'Del archivo a la pieza', 'body' => '<p>Cada pieza empieza en un diseño y pasa horas en la impresora, capa a capa. Después se revisa, se limpia y se acaba a mano antes de enviártela.</p><p>Como imprimimos bajo pedido, podemos ajustar colores, tamaños y detalles a lo que necesitas.</p>'],
                'en' => ['title' => 'From file to piece', 'body' => '<p>Every piece starts as a design and spends hours on the printer, layer by layer. It is then checked, cleaned and finished by hand before it ships.</p><p>Because we print to order, we can adjust colours, sizes and details to what you need.</p>'],
                'fr' => ['title' => 'Du fichier à la pièce', 'body' => '<p>Chaque pièce naît d’un dessin et passe des heures dans l’imprimante, couche après couche. Elle est ensuite vérifiée, nettoyée et finie à la main avant de partir.</p><p>Comme nous imprimons à la commande, nous pouvons adapter couleurs, tailles et détails à vos besoins.</p>'],
            ]),
            self::block('text', 'narrow', [], [
                'pt' => ['title' => 'Os materiais', 'body' => '<ul><li><strong>PLA</strong> — feito a partir de fontes vegetais, com um acabamento bonito; ideal para decoração.</li><li><strong>PETG</strong> — mais resistente ao calor, à humidade e a pancadas; bom para peças de uso diário.</li><li><strong>TPU</strong> — flexível, para peças que dobram ou amortecem.</li></ul>'],
                'es' => ['title' => 'Los materiales', 'body' => '<ul><li><strong>PLA</strong> — de origen vegetal, con un acabado bonito; ideal para decoración.</li><li><strong>PETG</strong> — más resistente al calor, la humedad y los golpes; bueno para piezas de uso diario.</li><li><strong>TPU</strong> — flexible, para piezas que se doblan o amortiguan.</li></ul>'],
                'en' => ['title' => 'Our materials', 'body' => '<ul><li><strong>PLA</strong> — plant-based with a lovely finish; ideal for home decor.</li><li><strong>PETG</strong> — tougher against heat, moisture and knocks; good for everyday parts.</li><li><strong>TPU</strong> — flexible, for parts that bend or cushion.</li></ul>'],
                'fr' => ['title' => 'Les matières', 'body' => '<ul><li><strong>PLA</strong> — d’origine végétale, avec une belle finition ; idéal pour la déco.</li><li><strong>PETG</strong> — plus résistant à la chaleur, à l’humidité et aux chocs ; parfait pour l’usage quotidien.</li><li><strong>TPU</strong> — flexible, pour les pièces qui plient ou amortissent.</li></ul>'],
            ]),
            self::howItWorks(),
            self::contactBlock(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function custom(): array
    {
        return [
            self::pageHeader(
                ['pt' => 'Peça personalizada', 'es' => 'Pieza personalizada', 'en' => 'Custom piece', 'fr' => 'Pièce sur mesure'],
                [
                    'pt' => 'Tens uma ideia, um desenho ou um ficheiro 3D? Conta-nos e fazemos um orçamento sem compromisso.',
                    'es' => '¿Tienes una idea, un dibujo o un archivo 3D? Cuéntanoslo y te hacemos un presupuesto sin compromiso.',
                    'en' => 'Got an idea, a sketch or a 3D file? Tell us and we will send you a free, no-obligation quote.',
                    'fr' => 'Une idée, un croquis ou un fichier 3D ? Racontez-nous et nous vous faisons un devis sans engagement.',
                ],
            ),
            self::block('steps', null, [], [
                'pt' => ['title' => 'Como funciona', 'steps' => [
                    ['icon' => 'pencil', 'title' => 'Descreve a ideia', 'text' => 'Uma frase, um esboço, uma fotografia ou um ficheiro STL — tudo ajuda.'],
                    ['icon' => 'message-circle', 'title' => 'Recebes um orçamento', 'text' => 'Confirmamos medidas, material, cor, prazo e preço.'],
                    ['icon' => 'printer', 'title' => 'Produzimos', 'text' => 'Imprimimos, acabamos e enviamos a tua peça.'],
                ]],
                'es' => ['title' => 'Cómo funciona', 'steps' => [
                    ['icon' => 'pencil', 'title' => 'Describe la idea', 'text' => 'Una frase, un boceto, una foto o un archivo STL: todo ayuda.'],
                    ['icon' => 'message-circle', 'title' => 'Recibes un presupuesto', 'text' => 'Confirmamos medidas, material, color, plazo y precio.'],
                    ['icon' => 'printer', 'title' => 'Lo fabricamos', 'text' => 'Imprimimos, acabamos y enviamos tu pieza.'],
                ]],
                'en' => ['title' => 'How it works', 'steps' => [
                    ['icon' => 'pencil', 'title' => 'Describe your idea', 'text' => 'A sentence, a sketch, a photo or an STL file — it all helps.'],
                    ['icon' => 'message-circle', 'title' => 'Get a quote', 'text' => 'We confirm size, material, colour, lead time and price.'],
                    ['icon' => 'printer', 'title' => 'We make it', 'text' => 'We print, finish and ship your piece.'],
                ]],
                'fr' => ['title' => 'Comment ça marche', 'steps' => [
                    ['icon' => 'pencil', 'title' => 'Décrivez l’idée', 'text' => 'Une phrase, un croquis, une photo ou un fichier STL — tout aide.'],
                    ['icon' => 'message-circle', 'title' => 'Recevez un devis', 'text' => 'Nous confirmons dimensions, matière, couleur, délai et prix.'],
                    ['icon' => 'printer', 'title' => 'On fabrique', 'text' => 'Nous imprimons, finissons et expédions votre pièce.'],
                ]],
            ], ['bg' => 'muted', 'spacing' => 'lg', 'width' => 'contained', 'align' => 'center']),
            self::block('custom_order', null, [], [
                'pt' => ['title' => 'Conta-nos a tua ideia', 'text' => 'Quanto mais detalhes, mais depressa te respondemos.'],
                'es' => ['title' => 'Cuéntanos tu idea', 'text' => 'Cuantos más detalles, antes te respondemos.'],
                'en' => ['title' => 'Tell us your idea', 'text' => 'The more detail you give, the faster we can reply.'],
                'fr' => ['title' => 'Racontez-nous votre idée', 'text' => 'Plus vous donnez de détails, plus vite nous répondons.'],
            ]),
            self::block('faq', null, [], [
                'pt' => ['title' => 'Dúvidas comuns', 'items' => [
                    ['question' => 'Que ficheiros aceitam?', 'answer' => '<p>STL, 3MF, OBJ e STEP. Se não tens um ficheiro 3D, fotografias e esboços também servem.</p>'],
                    ['question' => 'Quanto custa?', 'answer' => '<p>Depende do tamanho, do material e do tempo de impressão. O orçamento é gratuito e sem compromisso.</p>'],
                    ['question' => 'Quanto tempo demora?', 'answer' => '<p>Depende da peça e da quantidade. Dizemos-te o prazo no orçamento.</p>'],
                ]],
                'es' => ['title' => 'Dudas comunes', 'items' => [
                    ['question' => '¿Qué archivos aceptáis?', 'answer' => '<p>STL, 3MF, OBJ y STEP. Si no tienes un archivo 3D, también sirven fotos y bocetos.</p>'],
                    ['question' => '¿Cuánto cuesta?', 'answer' => '<p>Depende del tamaño, el material y el tiempo de impresión. El presupuesto es gratuito y sin compromiso.</p>'],
                    ['question' => '¿Cuánto tarda?', 'answer' => '<p>Depende de la pieza y la cantidad. Te indicamos el plazo en el presupuesto.</p>'],
                ]],
                'en' => ['title' => 'Common questions', 'items' => [
                    ['question' => 'Which files do you accept?', 'answer' => '<p>STL, 3MF, OBJ and STEP. No 3D file? Photos and sketches work too.</p>'],
                    ['question' => 'How much does it cost?', 'answer' => '<p>It depends on size, material and print time. Quotes are free and with no obligation.</p>'],
                    ['question' => 'How long does it take?', 'answer' => '<p>It depends on the piece and quantity. We give you the lead time with the quote.</p>'],
                ]],
                'fr' => ['title' => 'Questions courantes', 'items' => [
                    ['question' => 'Quels fichiers acceptez-vous ?', 'answer' => '<p>STL, 3MF, OBJ et STEP. Pas de fichier 3D ? Des photos et des croquis suffisent.</p>'],
                    ['question' => 'Combien ça coûte ?', 'answer' => '<p>Cela dépend de la taille, de la matière et du temps d’impression. Le devis est gratuit et sans engagement.</p>'],
                    ['question' => 'Combien de temps ?', 'answer' => '<p>Cela dépend de la pièce et de la quantité. Nous indiquons le délai avec le devis.</p>'],
                ]],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function contact(): array
    {
        return [
            self::pageHeader(
                ['pt' => 'Contacto', 'es' => 'Contacto', 'en' => 'Contact', 'fr' => 'Contact'],
                [
                    'pt' => 'Escreve-nos — respondemos o mais depressa possível.',
                    'es' => 'Escríbenos: te respondemos lo antes posible.',
                    'en' => 'Write to us — we reply as soon as we can.',
                    'fr' => 'Écrivez-nous — nous répondons au plus vite.',
                ],
            ),
            self::block('contact', null, ['show_form' => true, 'show_details' => true], [
                'pt' => ['title' => '', 'text' => ''],
            ], ['bg' => 'default', 'spacing' => 'sm', 'width' => 'contained', 'align' => 'start']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function faq(): array
    {
        return [
            self::pageHeader(['pt' => 'Perguntas frequentes', 'es' => 'Preguntas frecuentes', 'en' => 'Frequently asked questions', 'fr' => 'Questions fréquentes']),
            self::block('faq', null, [], [
                'pt' => ['items' => [
                    ['question' => 'Como posso encomendar?', 'answer' => '<p>Ainda não há pagamentos no site. Escolhe a peça, carrega em <strong>Tenho interesse</strong> e falamos contigo para combinar os detalhes e o pagamento.</p>'],
                    ['question' => 'Quanto tempo demora?', 'answer' => '<p>As peças prontas a enviar seguem em poucos dias. As feitas por encomenda dependem do tamanho e da quantidade — o prazo aparece na página de cada produto.</p>'],
                    ['question' => 'Posso escolher a cor?', 'answer' => '<p>Sim. Cada produto mostra as cores disponíveis; se procuras outra, pergunta-nos.</p>'],
                    ['question' => 'Fazem peças à medida?', 'answer' => '<p>Sim. Conta-nos a ideia na página de peça personalizada e enviamos-te um orçamento.</p>'],
                    ['question' => 'Que materiais usam?', 'answer' => '<p>Sobretudo PLA e PETG; para peças flexíveis, TPU.</p>'],
                ]],
                'es' => ['items' => [
                    ['question' => '¿Cómo puedo hacer un pedido?', 'answer' => '<p>Todavía no hay pagos en la web. Elige la pieza, pulsa <strong>Me interesa</strong> y te contactamos para acordar los detalles y el pago.</p>'],
                    ['question' => '¿Cuánto tarda?', 'answer' => '<p>Las piezas listas para enviar salen en pocos días. Las fabricadas bajo pedido dependen del tamaño y la cantidad: el plazo aparece en la página de cada producto.</p>'],
                    ['question' => '¿Puedo elegir el color?', 'answer' => '<p>Sí. Cada producto muestra los colores disponibles; si buscas otro, pregúntanos.</p>'],
                    ['question' => '¿Hacéis piezas a medida?', 'answer' => '<p>Sí. Cuéntanos tu idea en la página de pieza personalizada y te enviamos un presupuesto.</p>'],
                    ['question' => '¿Qué materiales usáis?', 'answer' => '<p>Sobre todo PLA y PETG; para piezas flexibles, TPU.</p>'],
                ]],
                'en' => ['items' => [
                    ['question' => 'How do I order?', 'answer' => '<p>There is no online checkout yet. Choose a piece, press <strong>I’m interested</strong> and we will get in touch to agree the details and payment.</p>'],
                    ['question' => 'How long does it take?', 'answer' => '<p>Ready-to-ship pieces leave within a few days. Made-to-order ones depend on size and quantity — the lead time is shown on each product page.</p>'],
                    ['question' => 'Can I choose the colour?', 'answer' => '<p>Yes. Each product shows the colours available; if you are after another one, just ask.</p>'],
                    ['question' => 'Do you make custom pieces?', 'answer' => '<p>Yes. Tell us your idea on the custom piece page and we will send you a quote.</p>'],
                    ['question' => 'What materials do you use?', 'answer' => '<p>Mostly PLA and PETG; TPU for flexible parts.</p>'],
                ]],
                'fr' => ['items' => [
                    ['question' => 'Comment commander ?', 'answer' => '<p>Il n’y a pas encore de paiement en ligne. Choisissez la pièce, cliquez sur <strong>Ça m’intéresse</strong> et nous vous contactons pour régler les détails et le paiement.</p>'],
                    ['question' => 'Quels sont les délais ?', 'answer' => '<p>Les pièces prêtes à expédier partent en quelques jours. Celles fabriquées à la commande dépendent de la taille et de la quantité — le délai figure sur chaque fiche produit.</p>'],
                    ['question' => 'Puis-je choisir la couleur ?', 'answer' => '<p>Oui. Chaque produit indique les couleurs disponibles ; si vous en cherchez une autre, demandez-nous.</p>'],
                    ['question' => 'Faites-vous du sur-mesure ?', 'answer' => '<p>Oui. Décrivez votre idée sur la page pièce sur mesure et nous vous envoyons un devis.</p>'],
                    ['question' => 'Quelles matières utilisez-vous ?', 'answer' => '<p>Surtout du PLA et du PETG ; du TPU pour les pièces flexibles.</p>'],
                ]],
            ], ['bg' => 'default', 'spacing' => 'sm', 'width' => 'contained', 'align' => 'center']),
            self::block('banner', null, [], [
                'pt' => ['title' => 'Não encontraste a resposta?', 'link' => self::link('Fala connosco', '/contacto')],
                'es' => ['title' => '¿No encuentras la respuesta?', 'link' => self::link('Háblanos', '/contacto')],
                'en' => ['title' => 'Can’t find your answer?', 'link' => self::link('Talk to us', '/contacto')],
                'fr' => ['title' => 'Vous ne trouvez pas la réponse ?', 'link' => self::link('Contactez-nous', '/contacto')],
            ], ['bg' => 'accent', 'spacing' => 'md', 'width' => 'contained', 'align' => 'center']),
        ];
    }

    /**
     * Um esqueleto, so em portugues. O texto legal escreve-o o dono — com o
     * NIF, a morada e as regras da loja — antes de publicar a pagina.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function legal(string $key): array
    {
        $title = self::NAMES[$key];

        $sections = match ($key) {
            'terms' => ['Quem somos', 'Encomendas e pagamento', 'Preços', 'Prazos de produção', 'Direito de livre resolução', 'Garantia', 'Resolução de litígios'],
            'privacy' => ['Responsável pelo tratamento', 'Que dados recolhemos', 'Para que os usamos', 'Durante quanto tempo', 'Os teus direitos', 'Cookies', 'Contacto'],
            default => ['Para onde enviamos', 'Custos de envio', 'Prazos', 'Devoluções', 'Peças personalizadas', 'Peças danificadas no transporte'],
        };

        $body = '<p><em>Rascunho: substitui cada secção pelo texto da tua loja (com o NIF, a morada e as tuas regras) antes de publicar esta página.</em></p>'
            .implode('', array_map(fn (string $section): string => "<h2>{$section}</h2><p>…</p>", $sections));

        return [
            self::pageHeader(['pt' => $title]),
            self::block('text', 'narrow', [], ['pt' => ['body' => $body]]),
        ];
    }
}
