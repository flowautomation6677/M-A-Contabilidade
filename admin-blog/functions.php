<?php
declare(strict_types=1);

const SITE_NAME = 'M&A Contabilidade Consultiva';
const SITE_URL = 'https://www.meacontabilidadeconsultiva.com.br';
const SITE_PHONE = '5521967640942';

function root_dir(): string { return dirname(__DIR__); }
function data_dir(): string { return root_dir() . '/blog-content'; }
function config_file(): string { return data_dir() . '/admin-config.php'; }

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function atomic_write(string $file, string $content): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta de destino.');
    }
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar o arquivo.');
    }
    chmod($tmp, 0644);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível finalizar a gravação.');
    }
}

function site_css(): string {
    $siteFile = data_dir() . '/site.json';
    if (!is_file($siteFile)) return '/assets/site.css';
    $data = json_decode((string) file_get_contents($siteFile), true);
    return is_array($data) && isset($data['cssPath']) ? (string) $data['cssPath'] : '/assets/site.css';
}

function slugify(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($converted !== false) $value = $converted;
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function blog_categories(): array {
    return [
        ['slug' => 'gestao-financeira', 'name' => 'Gestão financeira', 'description' => 'Fluxo de caixa, indicadores, rentabilidade, planejamento financeiro e decisões para clínicas mais previsíveis.'],
        ['slug' => 'tributacao-planejamento', 'name' => 'Tributação e planejamento', 'description' => 'Regimes tributários, Imposto de Renda, reforma tributária e organização patrimonial para profissionais da saúde.'],
        ['slug' => 'contabilidade-consultiva-abertura', 'name' => 'Contabilidade consultiva e abertura', 'description' => 'Abertura de clínicas, troca de contador e acompanhamento consultivo para estruturar decisões com segurança.'],
        ['slug' => 'fiscal-trabalhista-compliance', 'name' => 'Fiscal, trabalhista e compliance', 'description' => 'Notas fiscais, obrigações trabalhistas, conformidade e processos que reduzem riscos na operação da clínica.'],
    ];
}

function category_slug_for(string $category): string {
    $mapping = [
        'Gestão financeira' => 'gestao-financeira',
        'Planejamento financeiro' => 'gestao-financeira',
        'Contabilidade gerencial' => 'gestao-financeira',
        'Estratégia tributária' => 'tributacao-planejamento',
        'Estrutura tributária' => 'tributacao-planejamento',
        'Imposto de Renda' => 'tributacao-planejamento',
        'Reforma tributária' => 'tributacao-planejamento',
        'Planejamento patrimonial' => 'tributacao-planejamento',
        'Tributação e planejamento' => 'tributacao-planejamento',
        'Abertura de clínica' => 'contabilidade-consultiva-abertura',
        'Contabilidade consultiva' => 'contabilidade-consultiva-abertura',
        'Contabilidade consultiva e abertura' => 'contabilidade-consultiva-abertura',
        'Conformidade fiscal' => 'fiscal-trabalhista-compliance',
        'Operação e compliance' => 'fiscal-trabalhista-compliance',
        'Fiscal e compliance' => 'fiscal-trabalhista-compliance',
        'Departamento pessoal' => 'fiscal-trabalhista-compliance',
        'Fiscal, trabalhista e compliance' => 'fiscal-trabalhista-compliance',
    ];
    return $mapping[$category] ?? 'contabilidade-consultiva-abertura';
}

function article_category_slug(array $post): string {
    $saved = (string)($post['categorySlug'] ?? '');
    foreach (blog_categories() as $category) {
        if ($saved === $category['slug']) return $saved;
    }
    return category_slug_for((string)($post['category'] ?? ''));
}

function category_by_slug(string $slug): ?array {
    foreach (blog_categories() as $category) {
        if ($category['slug'] === $slug) return $category;
    }
    return null;
}

function clean_text(string $value, int $max = 300): string {
    $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');
    return mb_substr($value, 0, $max, 'UTF-8');
}

function clean_html(string $html): string {
    $allowed = '<p><h2><h3><h4><ul><ol><li><strong><em><a><blockquote><code><br>';
    // Remove dangerous elements together with their contents before allowing
    // the small set of formatting tags used by articles. `strip_tags()` alone
    // removes only the tag and would leave script/style source visible.
    $html = preg_replace('~<(script|style|iframe|object|embed|form|button)\b[^>]*>.*?</\1\s*>~isu', '', $html) ?? '';
    $html = preg_replace('~<(?:input|iframe|object|embed)\b[^>]*>~isu', '', $html) ?? '';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/iu', '', $html) ?? '';
    $html = preg_replace('/\s+style\s*=\s*(["\']).*?\1/iu', '', $html) ?? '';
    $html = preg_replace('/href\s*=\s*(["\'])\s*javascript:.*?\1/iu', 'href="#"', $html) ?? '';
    return trim($html);
}

function public_article_html(string $html): string {
    $html = clean_html($html);
    $internalHeading = '(?:Checklist editorial|Notas? internas?|Orientações? para publicação)';
    $html = trim(preg_replace('~<h[2-4][^>]*>\s*' . $internalHeading . '\s*</h[2-4]>.*$~isu', '', $html) ?? $html);
    $whatsapp = 'https://wa.me/' . SITE_PHONE . '?text=' . rawurlencode('Olá! Li um artigo no blog da M&A e quero conversar com a equipe.');
    return preg_replace('/href=([' . "\"'" . '])\/contato\\1/iu', 'href="' . $whatsapp . '"', $html) ?? $html;
}

function estimate_reading_time(string $html): string {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    $words = $text === '' ? 0 : str_word_count($text, 0, 'áàâãéêíóôõúüçÁÀÂÃÉÊÍÓÔÕÚÜÇ');
    return max(2, (int) round($words / 250)) . ' min de leitura';
}

function article_citations(string $html): array {
    preg_match_all('~href\s*=\s*(["\'])(https?://[^"\']+)\1~iu', $html, $matches);
    $citations = [];
    $ignoredHosts = [
        'meacontabilidadeconsultiva.com.br',
        'www.meacontabilidadeconsultiva.com.br',
        'wa.me',
        'www.instagram.com',
        'instagram.com',
        'www.facebook.com',
        'facebook.com',
        'share.google',
    ];
    foreach ($matches[2] ?? [] as $rawUrl) {
        $url = html_entity_decode((string)$rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $host = mb_strtolower((string)parse_url($url, PHP_URL_HOST), 'UTF-8');
        if ($host === '' || in_array($host, $ignoredHosts, true)) continue;
        $citations[] = $url;
    }
    return array_values(array_unique($citations));
}

function load_posts(): array {
    $posts = [];
    foreach (glob(data_dir() . '/*.json') ?: [] as $file) {
        if (basename($file) === 'site.json') continue;
        $post = json_decode((string) file_get_contents($file), true);
        if (is_array($post) && !empty($post['slug']) && !empty($post['title'])) $posts[] = $post;
    }
    usort($posts, fn(array $a, array $b) => strcmp((string)($b['publishedAt'] ?? ''), (string)($a['publishedAt'] ?? '')) ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0)));
    return $posts;
}

function load_post(string $slug): ?array {
    $slug = slugify($slug);
    $file = data_dir() . '/' . $slug . '.json';
    if (!is_file($file)) return null;
    $post = json_decode((string) file_get_contents($file), true);
    return is_array($post) ? $post : null;
}

function gtm_head(): string {
    return '<script data-mea-analytics>(function(w,d,i){var loaded=false;function load(){if(loaded)return;loaded=true;w.dataLayer=w.dataLayer||[];w.dataLayer.push({\'gtm.start\':Date.now(),event:\'gtm.js\'});var s=d.createElement(\'script\');s.async=true;s.src=\'https://www.googletagmanager.com/gtm.js?id=\'+i;d.head.appendChild(s)}w.addEventListener(\'load\',function(){w.setTimeout(load,3500)},{once:true});[\'pointerdown\',\'keydown\',\'touchstart\'].forEach(function(e){w.addEventListener(e,load,{once:true,passive:true})})})(window,document,\'GTM-TRK3ZS66\');</script>';
}

function gtm_body(): string {
    return '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TRK3ZS66" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>';
}

function page_head(string $title, string $description, string $canonical, string $image = '/images/site/fundadores-mea.webp', string $type = 'website', string $extraHead = ''): string {
    $css = site_css();
    $absoluteImage = str_starts_with($image, 'http') ? $image : SITE_URL . $image;
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title><meta name="description" content="' . e($description) . '">'
        . '<link rel="canonical" href="' . e($canonical) . '"><meta name="robots" content="index,follow">'
        . '<meta property="og:locale" content="pt_BR"><meta property="og:site_name" content="' . e(SITE_NAME) . '"><meta property="og:type" content="' . e($type) . '">'
        . '<meta property="og:title" content="' . e($title) . '"><meta property="og:description" content="' . e($description) . '"><meta property="og:url" content="' . e($canonical) . '"><meta property="og:image" content="' . e($absoluteImage) . '">'
        . '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . e($title) . '"><meta name="twitter:description" content="' . e($description) . '"><meta name="twitter:image" content="' . e($absoluteImage) . '">'
        . '<link rel="icon" href="/favicon.ico" sizes="256x256"><link rel="icon" type="image/png" href="/favicon.png" sizes="256x256"><link rel="apple-touch-icon" href="/favicon.png"><link rel="stylesheet" href="' . e($css) . '"><meta name="theme-color" content="#000000">' . $extraHead . gtm_head() . '</head><body>' . gtm_body();
}

function site_header(): string {
    $links = [['Início','/'],['Quem Somos','/sobre-nos/'],['Especialidades','/especialidades/'],['Conteúdos Exclusivos','/conteudos-exclusivos/'],['Case de Sucesso','/case-de-sucesso/'],['Blog','/blog/'],['Contato','/contato/']];
    $nav = '';
    foreach ($links as [$label,$href]) $nav .= '<a href="' . e($href) . '">' . e($label) . '</a>';
    return '<header class="site-header"><div class="container header-inner"><a class="brand" href="/" aria-label="M&A Contabilidade Consultiva — início"><img src="/images/site/logo-mea.webp" width="330" height="330" alt="M&A Contabilidade Consultiva"></a><nav class="desktop-nav" aria-label="Navegação principal">' . $nav . '</nav><a class="button button-small header-cta" href="https://wa.me/' . SITE_PHONE . '?text=' . rawurlencode('Olá! Quero falar com um especialista da M&A.') . '">Fale com um especialista</a><details class="mobile-menu"><summary aria-label="Abrir menu">Menu</summary><nav aria-label="Navegação móvel">' . $nav . '</nav></details></div></header>';
}

function site_footer(): string {
    return '<footer class="site-footer"><div class="container footer-panel"><div class="footer-main-card"><img class="footer-logo" src="/images/site/logo-mea.webp" width="330" height="330" alt="M&A Contabilidade Consultiva"><p>A M&A Contabilidade Consultiva atua com visão estratégica para clínicas que precisam organizar a operação, reduzir desperdícios e crescer com segurança tributária, financeira e societária.</p><div class="footer-primary-grid"><div><h2>Navegação</h2><a href="/">Início</a><a href="/sobre-nos/">Quem somos</a><a href="/especialidades/">Especialidades</a><a href="/conteudos-exclusivos/">Conteúdos exclusivos</a><a href="/case-de-sucesso/">Case de sucesso</a><a href="/blog/">Blog</a><a href="/contato/">Contato</a><a href="/politica-de-privacidade/">Política de privacidade</a></div><div><h2>Contato</h2><a href="tel:+' . SITE_PHONE . '">(21) 96764-0942</a><a href="mailto:contato@meacontabilidadeconsultiva.com.br">contato@meacontabilidadeconsultiva.com.br</a><p>Edifício Plaza Business — Rua Getúlio Vargas, 87, sala 308, Centro, Nova Iguaçu/RJ</p></div></div></div><div class="footer-social-card"><div class="footer-social-heading"><div><p class="eyebrow light">Instagram</p><h2>Siga a M&A no Instagram e acompanhe também nossos perfis nas outras redes.</h2></div><a class="footer-profile-link" href="https://www.instagram.com/meacontabilidadeconsultiva/">Ver perfil</a></div><p>Acompanhe a M&A nas redes sociais e acesse rapidamente nossos canais institucionais.</p><div class="footer-social-links"><a href="https://www.instagram.com/meacontabilidadeconsultiva/">Instagram</a><a href="https://www.facebook.com/macontabilidad">Facebook</a><a href="https://share.google/MFVj4UOwtDPqySOV3">Google</a></div><a class="footer-instagram" href="https://www.instagram.com/meacontabilidadeconsultiva/" aria-label="Ver o perfil da M&A no Instagram"><img src="/images/site/instagram-mea.webp" width="800" height="297" loading="lazy" alt="Print do perfil do Instagram da M&A Contabilidade Consultiva"></a></div></div><div class="container footer-bottom"><span>© 2026 M&A Contabilidade Consultiva — CNPJ 61.042.259/0001-90. Todos os direitos reservados.</span></div></footer><a class="whatsapp-float" href="https://wa.me/' . SITE_PHONE . '" aria-label="Conversar com a M&A pelo WhatsApp"><img src="/images/site/whatsapp.svg" width="20" height="20" alt="" aria-hidden="true"><span class="whatsapp-label">WhatsApp</span></a></body></html>';
}

function render_article(array $post): string {
    $slug = slugify((string)$post['slug']);
    $url = SITE_URL . '/blog/' . $slug . '/';
    $title = (string)$post['title'];
    $seoTitle = (string)($post['seoTitle'] ?: $title . ' | ' . SITE_NAME);
    $description = (string)$post['description'];
    $image = (string)$post['image'];
    $articleText = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$post['html'])) ?? '');
    $wordCount = $articleText === '' ? 0 : str_word_count($articleText, 0, 'áàâãéêíóôõúüçÁÀÂÃÉÊÍÓÔÕÚÜÇ');
    $citations = article_citations((string)$post['html']);
    $articleSchema = [
        '@type' => 'BlogPosting', '@id' => $url . '#article', 'url' => $url, 'headline' => $title,
        'description' => $description,
        'image' => ['@type'=>'ImageObject','url'=>SITE_URL . $image,'width'=>1200,'height'=>800],
        'datePublished' => $post['publishedAt'], 'dateModified' => $post['modifiedAt'],
        'mainEntityOfPage' => ['@type'=>'WebPage','@id'=>$url],
        'author' => ['@type'=>'Organization','name'=>SITE_NAME,'url'=>SITE_URL],
        'reviewedBy' => ['@type'=>'Organization','name'=>SITE_NAME,'url'=>SITE_URL],
        'publisher' => ['@type'=>'Organization','name'=>SITE_NAME,'url'=>SITE_URL,'logo'=>['@type'=>'ImageObject','url'=>SITE_URL.'/images/site/logo-mea.webp','width'=>330,'height'=>330]],
        'articleSection' => $post['category'], 'inLanguage' => 'pt-BR', 'isAccessibleForFree' => true,
        'wordCount' => $wordCount,
        'about' => [
            ['@type'=>'Thing','name'=>(string)$post['category']],
            ['@type'=>'Thing','name'=>$title],
        ],
    ];
    if ($citations) $articleSchema['citation'] = $citations;
    $graph = [$articleSchema, [
        '@type' => 'BreadcrumbList',
        '@id' => $url . '#breadcrumb',
        'itemListElement' => [
            ['@type'=>'ListItem','position'=>1,'name'=>'Início','item'=>SITE_URL . '/'],
            ['@type'=>'ListItem','position'=>2,'name'=>'Blog','item'=>SITE_URL . '/blog/'],
            ['@type'=>'ListItem','position'=>3,'name'=>$title,'item'=>$url],
        ],
    ]];
    if (preg_match('~<h2[^>]*>\s*Perguntas frequentes.*?</h2>(.*?)(?:<h2[^>]*>|$)~isu', (string)$post['html'], $faqSection)) {
        preg_match_all('~<h3[^>]*>(.*?)</h3>\s*<p[^>]*>(.*?)</p>~isu', $faqSection[1], $faqPairs, PREG_SET_ORDER);
        $questions = [];
        foreach ($faqPairs as $pair) {
            $question = trim(html_entity_decode(strip_tags($pair[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $answer = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($pair[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ($question !== '' && $answer !== '') $questions[] = ['@type'=>'Question','name'=>$question,'acceptedAnswer'=>['@type'=>'Answer','text'=>$answer]];
        }
        if ($questions) $graph[] = ['@type'=>'FAQPage','@id'=>$url.'#faq','url'=>$url.'#faq','mainEntity'=>$questions,'inLanguage'=>'pt-BR'];
    }
    $schema = json_encode(['@context'=>'https://schema.org','@graph'=>$graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $articleHead = '<meta name="author" content="' . e(SITE_NAME) . '"><meta property="article:published_time" content="' . e((string)$post['publishedAt']) . '"><meta property="article:modified_time" content="' . e((string)$post['modifiedAt']) . '"><meta property="article:section" content="' . e((string)$post['category']) . '"><style>.article-content a{color:#76520f;font-weight:700}.whatsapp-float{background:#0d7a3d}.whatsapp-float:hover{background:#075e32}</style>';
    return page_head($seoTitle, $description, $url, $image, 'article', $articleHead) . site_header()
        . '<main class="article-page"><header class="article-hero"><div class="container article-hero-grid"><div class="article-hero-media"><div class="article-cover"><img src="' . e($image) . '" width="1200" height="800" fetchpriority="high" alt="' . e((string)$post['imageAlt']) . '"></div><aside class="article-hero-cta"><p class="eyebrow light">Fale com a M&A</p><p>Se sua clínica quer transformar conteúdo em decisão prática, nossa equipe pode ajudar com estratégia tributária e financeira aplicada.</p><a class="button" href="https://wa.me/' . SITE_PHONE . '?text=' . rawurlencode('Olá! Li um artigo no blog da M&A e quero solicitar um diagnóstico.') . '">Solicitar diagnóstico</a></aside></div><div class="article-hero-copy"><a class="eyebrow light" href="/blog/">← Voltar ao blog</a><div class="article-meta-large"><span>' . e((string)$post['category']) . '</span><span>' . e((string)$post['readingTime']) . '</span><time datetime="' . e((string)$post['publishedAt']) . '">' . e(date('d/m/Y', strtotime((string)$post['publishedAt']))) . '</time></div><h1>' . e($title) . '</h1><p class="article-summary">' . e((string)$post['excerpt']) . '</p></div></div></header>'
        . '<div class="article-layout"><article class="article-content">' . (string)$post['html'] . '</article><aside class="article-aside"><h2>Diagnóstico consultivo</h2><p>O conteúdo é informativo. Regime, tributos e riscos precisam ser avaliados com os números e documentos de cada caso.</p><a class="button button-dark" href="https://wa.me/' . SITE_PHONE . '?text=' . rawurlencode('Olá! Li um artigo no blog da M&A e quero falar com a equipe.') . '">Falar com a M&A</a></aside></div></main>'
        . '<script type="application/ld+json">' . $schema . '</script>' . site_footer();
}

function render_blog_index(array $posts): string {
    $cards = '';
    foreach ($posts as $post) {
        $href = '/blog/' . slugify((string)$post['slug']) . '/';
        $cards .= '<article class="article-card"><a class="article-card-image" href="' . e($href) . '"><img src="' . e((string)$post['image']) . '" width="600" height="400" loading="lazy" alt="' . e((string)$post['imageAlt']) . '"></a><div class="article-card-body"><div class="article-meta"><span>' . e((string)$post['category']) . '</span><span>' . e((string)$post['readingTime']) . '</span></div><h2><a href="' . e($href) . '">' . e((string)$post['title']) . '</a></h2><p>' . e((string)$post['excerpt']) . '</p><a class="text-link" href="' . e($href) . '">Ler artigo <span>→</span></a></div></article>';
    }
    return page_head('Blog | ' . SITE_NAME, 'Conteúdo sobre contabilidade, tributação e gestão para clínicas.', SITE_URL . '/blog/') . site_header()
        . '<main class="blog-page"><header class="page-hero blog-hero"><div class="container"><p class="eyebrow light">Blog</p><h1>Conteúdo estratégico para clínicas que querem melhorar tributação, gestão e performance.</h1><p>Criamos artigos pensados para responder dúvidas reais do setor e ampliar a presença orgânica da M&A em temas de alta intenção de busca no nicho de contabilidade para clínicas médicas.</p></div></header><section class="section blog-listing-section"><div class="container"><div class="blog-toolbar"><p>' . count($posts) . ' artigos disponíveis</p><p>Revisão editorial e fontes oficiais</p></div>' . render_category_nav($posts) . '<div class="article-grid">' . $cards . '</div></div></section></main>' . site_footer();
}

function render_category_nav(array $posts, string $active = ''): string {
    $allClass = 'blog-category-link' . ($active === '' ? ' is-active' : '');
    $html = '<nav class="blog-category-nav" aria-label="Filtrar artigos por assunto"><a class="' . $allClass . '" href="/blog/"' . ($active === '' ? ' aria-current="page"' : '') . '>Todos <span>' . count($posts) . '</span></a>';
    foreach (blog_categories() as $category) {
        $count = count(array_filter($posts, fn(array $post) => article_category_slug($post) === $category['slug']));
        $class = 'blog-category-link' . ($active === $category['slug'] ? ' is-active' : '');
        $html .= '<a class="' . $class . '" href="/blog/categoria/' . e($category['slug']) . '/"' . ($active === $category['slug'] ? ' aria-current="page"' : '') . '>' . e($category['name']) . ' <span>' . $count . '</span></a>';
    }
    return $html . '</nav>';
}

function render_category_index(array $posts, array $category): string {
    $filtered = array_values(array_filter($posts, fn(array $post) => article_category_slug($post) === $category['slug']));
    $cards = '';
    foreach ($filtered as $post) {
        $href = '/blog/' . slugify((string)$post['slug']) . '/';
        $cards .= '<article class="article-card"><a class="article-card-image" href="' . e($href) . '"><img src="' . e((string)$post['image']) . '" width="600" height="400" loading="lazy" alt="' . e((string)$post['imageAlt']) . '"></a><div class="article-card-body"><div class="article-meta"><span>' . e((string)$post['category']) . '</span><span>' . e((string)$post['readingTime']) . '</span></div><h2><a href="' . e($href) . '">' . e((string)$post['title']) . '</a></h2><p>' . e((string)$post['excerpt']) . '</p><a class="text-link" href="' . e($href) . '">Ler artigo <span>→</span></a></div></article>';
    }
    $url = SITE_URL . '/blog/categoria/' . $category['slug'] . '/';
    $items = array_map(fn(array $post, int $index) => ['@type' => 'ListItem', 'position' => $index + 1, 'url' => SITE_URL . '/blog/' . slugify((string)$post['slug']) . '/', 'name' => (string)$post['title']], $filtered, array_keys($filtered));
    $schema = json_encode(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $category['name'] . ' para clínicas', 'description' => $category['description'], 'url' => $url, 'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($filtered), 'itemListElement' => $items]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return page_head($category['name'] . ' para clínicas | ' . SITE_NAME, $category['description'], $url) . site_header()
        . '<main class="blog-page"><header class="page-hero blog-hero"><div class="container"><p class="eyebrow light">Blog · ' . e($category['name']) . '</p><h1>' . e($category['name']) . ' para clínicas.</h1><p>' . e($category['description']) . '</p></div></header><section class="section blog-listing-section"><div class="container"><div class="blog-toolbar"><p>' . count($filtered) . ' artigos neste assunto</p><p>Revisão editorial e fontes oficiais</p></div>' . render_category_nav($posts, $category['slug']) . '<div class="article-grid">' . $cards . '</div></div></section><script type="application/ld+json">' . $schema . '</script></main>' . site_footer();
}

function regenerate_discovery(array $posts): void {
    $static = ['', '/sobre-nos/', '/especialidades/', '/especialidades/clinicas-medicas/', '/especialidades/clinicas-odontologicas/', '/especialidades/clinicas-veterinarias/', '/conteudos-exclusivos/', '/case-de-sucesso/', '/blog/', '/contato/', '/politica-de-privacidade/'];
    $urls = '';
    foreach ($static as $route) $urls .= '<url><loc>' . SITE_URL . $route . '</loc><lastmod>' . date('Y-m-d') . '</lastmod></url>';
    foreach (blog_categories() as $category) $urls .= '<url><loc>' . SITE_URL . '/blog/categoria/' . e($category['slug']) . '/</loc><lastmod>' . date('Y-m-d') . '</lastmod></url>';
    foreach ($posts as $post) $urls .= '<url><loc>' . SITE_URL . '/blog/' . e(slugify((string)$post['slug'])) . '/</loc><lastmod>' . e((string)$post['modifiedAt']) . '</lastmod></url>';
    atomic_write(root_dir() . '/sitemap.xml', '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>');

    $items = '';
    $llms = "# " . SITE_NAME . "\n\n> Contabilidade consultiva para clínicas médicas, odontológicas e veterinárias.\n\n## Categorias do blog\n\n";
    foreach (blog_categories() as $category) $llms .= '- [' . $category['name'] . '](' . SITE_URL . '/blog/categoria/' . $category['slug'] . '/): ' . $category['description'] . "\n";
    $llms .= "\n## Artigos\n\n";
    foreach ($posts as $post) {
        $url = SITE_URL . '/blog/' . slugify((string)$post['slug']) . '/';
        $items .= '<item><title>' . e((string)$post['title']) . '</title><link>' . $url . '</link><guid>' . $url . '</guid><pubDate>' . date(DATE_RSS, strtotime((string)$post['publishedAt'])) . '</pubDate><description>' . e((string)$post['excerpt']) . '</description></item>';
        $llms .= '- [' . clean_text((string)$post['title'], 160) . '](' . $url . '): ' . clean_text((string)$post['excerpt'], 300) . "\n";
    }
    atomic_write(root_dir() . '/rss.xml', '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . e(SITE_NAME) . '</title><link>' . SITE_URL . '/blog/</link><description>Conteúdo consultivo para clínicas.</description><language>pt-BR</language>' . $items . '</channel></rss>');
    atomic_write(root_dir() . '/llms.txt', $llms);
}

function publish_post(array $post): void {
    $slug = slugify((string)$post['slug']);
    if ($slug === '') throw new InvalidArgumentException('Slug inválido.');
    $post['slug'] = $slug;
    atomic_write(data_dir() . '/' . $slug . '.json', json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $posts = load_posts();
    // Rebuild every article so template, typography, metadata and navigation
    // stay consistent after editorial or design updates.
    foreach ($posts as $article) {
        $articleSlug = slugify((string)$article['slug']);
        atomic_write(root_dir() . '/blog/' . $articleSlug . '/index.html', render_article($article));
    }
    atomic_write(root_dir() . '/blog/index.html', render_blog_index($posts));
    foreach (blog_categories() as $category) atomic_write(root_dir() . '/blog/categoria/' . $category['slug'] . '/index.html', render_category_index($posts, $category));
    regenerate_discovery($posts);
}
