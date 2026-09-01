<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin-blog',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
require_once __DIR__ . '/functions.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return (string) $_SESSION['csrf'];
}

function valid_csrf(): bool {
    return isset($_POST['csrf']) && hash_equals(csrf_token(), (string) $_POST['csrf']);
}

function admin_config(): ?array {
    if (!is_file(config_file())) return null;
    $config = require config_file();
    return is_array($config) ? $config : null;
}

function save_admin_config(string $password): void {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $php = "<?php\nreturn ['password_hash' => " . var_export($hash, true) . "];\n";
    atomic_write(config_file(), $php);
}

function empty_post(): array {
    return [
        'id' => 0,
        'title' => '',
        'seoTitle' => '',
        'slug' => '',
        'description' => '',
        'excerpt' => '',
        'category' => 'Gestão financeira',
        'categorySlug' => 'gestao-financeira',
        'readingTime' => '',
        'image' => '',
        'imageAlt' => '',
        'publishedAt' => date('Y-m-d'),
        'modifiedAt' => date('Y-m-d'),
        'html' => '<p>Escreva a introdução do artigo aqui.</p><h2>Primeiro tópico</h2><p>Desenvolva o conteúdo com clareza, exemplos e orientação prática.</p>',
    ];
}

function upload_article_image(string $slug, string $current): string {
    if (empty($_FILES['image_file']) || (int) $_FILES['image_file']['error'] === UPLOAD_ERR_NO_FILE) return $current;
    $file = $_FILES['image_file'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('O upload da imagem não foi concluído.');
    if ((int) $file['size'] > 2 * 1024 * 1024) throw new RuntimeException('A imagem deve ter no máximo 2 MB.');

    $info = new finfo(FILEINFO_MIME_TYPE);
    $mime = $info->file((string) $file['tmp_name']);
    $extensions = ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($extensions[$mime]) || @getimagesize((string) $file['tmp_name']) === false) {
        throw new RuntimeException('Envie uma imagem WebP, JPEG ou PNG válida.');
    }

    $directory = root_dir() . '/images/blog';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar a pasta de imagens.');
    }
    $filename = $slug . '-' . date('Ymd-His') . '.' . $extensions[$mime];
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        throw new RuntimeException('Não foi possível salvar a imagem.');
    }
    chmod($destination, 0644);
    return '/images/blog/' . $filename;
}

$message = '';
$error = '';
$config = admin_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
    try {
        if ($config !== null) throw new RuntimeException('O painel já foi configurado.');
        if (!valid_csrf()) throw new RuntimeException('A sessão expirou. Atualize a página.');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (mb_strlen($password, 'UTF-8') < 12) throw new RuntimeException('Use uma senha com pelo menos 12 caracteres.');
        if (!hash_equals($password, $confirm)) throw new RuntimeException('As senhas não conferem.');
        save_admin_config($password);
        $config = admin_config();
        $_SESSION['authenticated'] = true;
        session_regenerate_id(true);
        $message = 'Painel configurado com sucesso.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    try {
        if (!valid_csrf()) throw new RuntimeException('A sessão expirou. Atualize a página.');
        $attempts = $_SESSION['login_attempts'] ?? [];
        $attempts = array_values(array_filter($attempts, fn($time) => (int) $time > time() - 900));
        if (count($attempts) >= 5) throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos.');
        $password = (string) ($_POST['password'] ?? '');
        if (!$config || !password_verify($password, (string) ($config['password_hash'] ?? ''))) {
            $attempts[] = time();
            $_SESSION['login_attempts'] = $attempts;
            throw new RuntimeException('Senha incorreta.');
        }
        $_SESSION['authenticated'] = true;
        $_SESSION['login_attempts'] = [];
        session_regenerate_id(true);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if (valid_csrf()) {
        $_SESSION = [];
        session_destroy();
        header('Location: /admin-blog/');
        exit;
    }
}

$authenticated = !empty($_SESSION['authenticated']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $authenticated) {
    try {
        if (!valid_csrf()) throw new RuntimeException('A sessão expirou. Atualize a página.');
        $title = clean_text((string) ($_POST['title'] ?? ''), 120);
        $slug = slugify((string) ($_POST['slug'] ?? $title));
        if ($title === '' || $slug === '') throw new RuntimeException('Informe um título válido.');
        $existing = load_post($slug);
        $all = load_posts();
        $maxId = array_reduce($all, fn(int $max, array $item) => max($max, (int) ($item['id'] ?? 0)), 0);
        $publishedAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['publishedAt'] ?? '')) ? (string) $_POST['publishedAt'] : date('Y-m-d');
        $image = upload_article_image($slug, clean_text((string) ($_POST['current_image'] ?? ''), 300));
        if ($image === '') throw new RuntimeException('Envie uma imagem de capa.');
        $articleHtml = public_article_html((string) ($_POST['html'] ?? ''));

        $categorySlug = clean_text((string) ($_POST['categorySlug'] ?? ''), 80);
        $selectedCategory = category_by_slug($categorySlug);
        if (!$selectedCategory) throw new RuntimeException('Selecione uma categoria válida.');
        $post = [
            'id' => (int) ($existing['id'] ?? ($maxId + 1)),
            'title' => $title,
            'seoTitle' => clean_text((string) ($_POST['seoTitle'] ?? ''), 160) ?: $title . ' | ' . SITE_NAME,
            'slug' => $slug,
            'description' => clean_text((string) ($_POST['description'] ?? ''), 160),
            'excerpt' => clean_text((string) ($_POST['excerpt'] ?? ''), 260),
            'category' => $selectedCategory['name'],
            'categorySlug' => $selectedCategory['slug'],
            'readingTime' => estimate_reading_time($articleHtml),
            'image' => $image,
            'imageAlt' => clean_text((string) ($_POST['imageAlt'] ?? ''), 180),
            'publishedAt' => $existing['publishedAt'] ?? $publishedAt,
            'modifiedAt' => date('Y-m-d'),
            'html' => $articleHtml,
        ];
        if ($post['description'] === '' || $post['excerpt'] === '' || $post['html'] === '') {
            throw new RuntimeException('Preencha descrição, resumo e conteúdo.');
        }
        if ($post['imageAlt'] === '') $post['imageAlt'] = $title;
        publish_post($post);
        $message = 'Artigo publicado. Blog, sitemap, RSS e arquivo de IA foram atualizados.';
        $_GET['edit'] = $slug;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

function admin_head(string $title): void {
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title><meta name="robots" content="noindex,nofollow"><style>';
    echo ':root{color-scheme:light;--black:#000;--gold:#c28f2d;--gold-light:#f3d781;--cream:#faf8f2;--ink:#181715;--line:#e5dfd2}*{box-sizing:border-box}body{margin:0;background:#f3f1ec;color:var(--ink);font:16px/1.5 Manrope,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:inherit}.login{width:min(460px,calc(100% - 32px));margin:10vh auto;background:#fff;padding:32px;border-radius:18px;box-shadow:0 16px 50px #0002}.login h1{margin:0 0 8px;color:var(--black);font-family:Georgia,serif}label{display:flex;justify-content:space-between;gap:12px;font-weight:700;margin:16px 0 6px}input,textarea,select{width:100%;border:1px solid var(--line);border-radius:9px;padding:11px 12px;font:inherit;background:#fff}textarea{min-height:110px;resize:vertical}button,.button{display:inline-flex;border:0;border-radius:999px;background:linear-gradient(135deg,var(--gold-light),var(--gold));color:var(--black);font-weight:800;padding:11px 18px;cursor:pointer;text-decoration:none}.muted,.counter{color:#68635b;font-size:14px}.counter{font-weight:600}.alert{padding:12px 14px;border-radius:9px;margin:14px 0}.success{background:#e5f5eb;color:#155b31}.error{background:#fff0ef;color:#8b2620}.admin-header{background:var(--black);color:#fff;border-bottom:1px solid #f3d78133}.admin-header>div{width:min(1180px,calc(100% - 32px));margin:auto;min-height:72px;display:flex;align-items:center;justify-content:space-between;gap:16px}.admin-header form{margin:0}.admin-header .secondary{background:transparent;border:1px solid #f3d78180;color:#fff}.admin-main{width:min(1180px,calc(100% - 32px));margin:30px auto 60px}.admin-grid{display:grid;grid-template-columns:300px 1fr;gap:24px}.panel{background:#fff;border-radius:16px;padding:22px;box-shadow:0 8px 35px #00000010}.post-list{list-style:none;padding:0;margin:18px 0}.post-list li{border-top:1px solid #e7e3dc;padding:10px 0}.post-list a{text-decoration:none;font-weight:700}.post-list small{display:block;color:#68635b}.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}.editor{min-height:460px;border:1px solid var(--line);border-radius:9px;padding:20px;outline:none;background:#fff}.editor:focus{border-color:var(--gold);box-shadow:0 0 0 3px #c28f2d30}.toolbar{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}.toolbar button{border-radius:7px;background:#eeeae1;padding:7px 10px;font-weight:700}.quality{background:var(--cream);border:1px solid var(--line);border-radius:14px;margin-top:22px;padding:18px}.quality h2{margin:0 0 5px;font:800 16px/1.3 Manrope,sans-serif}.quality ul{columns:2;gap:28px;margin:14px 0 0;padding:0;list-style:none}.quality li{break-inside:avoid;color:#8b2620;margin:7px 0;padding-left:22px;position:relative;font-size:13px}.quality li:before{content:"!";font-weight:900;position:absolute;left:2px}.quality li.ok{color:#155b31}.quality li.ok:before{content:"✓"}.actions{display:flex;align-items:center;gap:12px;margin-top:22px}.preview{font-weight:700;color:var(--black)}@media(max-width:820px){.admin-grid{grid-template-columns:1fr}.field-grid{grid-template-columns:1fr}.quality ul{columns:1}.admin-header>div{align-items:flex-start;padding:16px 0;flex-direction:column}.editor{min-height:350px}}';
    echo '</style></head><body>';
}

if ($config === null) {
    admin_head('Configurar painel — M&A');
    echo '<main class="login"><h1>Configure o painel do blog</h1><p>Crie a senha da pessoa responsável pelas publicações. Ela não será enviada por e-mail nem armazenada em texto aberto.</p>';
    if ($error) echo '<div class="alert error">' . e($error) . '</div>';
    echo '<form method="post"><input type="hidden" name="action" value="setup"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><label>Nova senha</label><input type="password" name="password" minlength="12" required autocomplete="new-password"><label>Confirme a senha</label><input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"><p class="muted">Use pelo menos 12 caracteres e guarde a senha em um gerenciador seguro.</p><button type="submit">Criar acesso</button></form></main></body></html>';
    exit;
}

if (!$authenticated) {
    admin_head('Entrar no painel — M&A');
    echo '<main class="login"><h1>Painel do blog</h1><p>Entre para cadastrar e atualizar artigos.</p>';
    if ($error) echo '<div class="alert error">' . e($error) . '</div>';
    echo '<form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><label>Senha</label><input type="password" name="password" required autofocus autocomplete="current-password"><p><button type="submit">Entrar</button></p></form><p class="muted">A área pública do site continua funcionando normalmente.</p></main></body></html>';
    exit;
}

$posts = load_posts();
$editSlug = isset($_GET['edit']) ? slugify((string) $_GET['edit']) : '';
$post = $editSlug !== '' ? (load_post($editSlug) ?? empty_post()) : empty_post();
admin_head('Editor do blog — M&A');
echo '<header class="admin-header"><div><strong>M&A · Editor do blog</strong><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><button class="secondary" type="submit">Sair</button></form></div></header><main class="admin-main">';
if ($message) echo '<div class="alert success">' . e($message) . '</div>';
if ($error) echo '<div class="alert error">' . e($error) . '</div>';
echo '<div class="admin-grid"><aside class="panel"><a class="button" href="/admin-blog/">+ Novo artigo</a><h2>Artigos publicados</h2><ul class="post-list">';
foreach ($posts as $item) {
    echo '<li><a href="?edit=' . e((string) $item['slug']) . '">' . e((string) $item['title']) . '</a><small>' . e((string) $item['category']) . ' · ' . e((string) $item['publishedAt']) . '</small></li>';
}
echo '</ul></aside><section class="panel"><h1>' . ($editSlug ? 'Editar artigo' : 'Novo artigo') . '</h1><form method="post" enctype="multipart/form-data" id="article-form"><input type="hidden" name="action" value="save"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="current_image" value="' . e((string) $post['image']) . '">';
echo '<label>Título do artigo</label><input name="title" maxlength="120" required value="' . e((string) $post['title']) . '"><div class="field-grid"><div><label>URL amigável (slug)</label><input name="slug" maxlength="120" value="' . e((string) $post['slug']) . '" placeholder="gerada pelo título"></div><div><label>Data de publicação</label><input type="date" name="publishedAt" value="' . e((string) $post['publishedAt']) . '"></div></div>';
echo '<label>Título para Google <span class="counter" id="seo-title-count"></span></label><input id="seo-title" name="seoTitle" maxlength="160" value="' . e((string) $post['seoTitle']) . '"><p class="muted">Recomendado: 30 a 60 caracteres, com o assunto principal.</p><label>Descrição para Google <span class="counter" id="description-count"></span></label><textarea id="description" name="description" maxlength="160" required>' . e((string) $post['description']) . '</textarea><label>Resumo exibido no blog <span class="counter" id="excerpt-count"></span></label><textarea id="excerpt" name="excerpt" maxlength="260" required>' . e((string) $post['excerpt']) . '</textarea>';
$selectedCategorySlug = article_category_slug($post);
$categoryOptions = '';
foreach (blog_categories() as $category) $categoryOptions .= '<option value="' . e($category['slug']) . '"' . ($selectedCategorySlug === $category['slug'] ? ' selected' : '') . '>' . e($category['name']) . '</option>';
echo '<div class="field-grid"><div><label>Categoria</label><select name="categorySlug" required>' . $categoryOptions . '</select></div><div><label>Tempo de leitura (automático)</label><input name="readingTime" maxlength="40" readonly value="' . e((string) $post['readingTime']) . '" placeholder="Calculado ao publicar"></div></div>';
echo '<div class="field-grid"><div><label>Imagem de capa</label><input type="file" name="image_file" accept="image/webp,image/jpeg,image/png"><p class="muted">WebP recomendado, 1200 × 800 px e até 2 MB.</p></div><div><label>Descrição acessível da imagem</label><input name="imageAlt" maxlength="180" value="' . e((string) $post['imageAlt']) . '"></div></div>';
if (!empty($post['image'])) echo '<p class="muted">Imagem atual: <a target="_blank" rel="noopener" href="' . e((string) $post['image']) . '">' . e((string) $post['image']) . '</a></p>';
echo '<label>Conteúdo</label><div class="toolbar" aria-label="Formatação"><button type="button" data-command="formatBlock" data-value="h2">Título 2</button><button type="button" data-command="formatBlock" data-value="h3">Título 3</button><button type="button" data-command="formatBlock" data-value="h4">Título 4</button><button type="button" data-command="formatBlock" data-value="p">Parágrafo</button><button type="button" data-command="formatBlock" data-value="blockquote">Resposta direta</button><button type="button" data-command="bold">Negrito</button><button type="button" data-command="insertUnorderedList">Lista</button><button type="button" data-command="insertOrderedList">Lista numerada</button><button type="button" data-command="createLink">Link</button></div><div class="editor" id="editor" contenteditable="true">' . (string) $post['html'] . '</div><textarea name="html" id="html" hidden></textarea><div class="quality"><h2>Checklist automático · SEO, AEO e GEO</h2><p class="muted">Os itens orientam a revisão; a publicação também gera BlogPosting, FAQPage quando aplicável, breadcrumbs, sitemap, RSS e llms.txt.</p><ul id="quality-list"><li data-check="seo">Título para Google entre 30 e 60 caracteres</li><li data-check="description">Descrição entre 120 e 160 caracteres</li><li data-check="excerpt">Resumo com pelo menos 80 caracteres</li><li data-check="h2">Seções organizadas com Título 2</li><li data-check="answer">Resposta direta no início</li><li data-check="faq">Perguntas frequentes estruturadas</li><li data-check="source">Link para ao menos uma fonte externa</li><li data-check="depth">Conteúdo aprofundado com 900+ palavras</li><li data-check="no-h1">Sem H1 dentro do corpo do artigo</li><li data-check="alt">Descrição acessível da imagem</li></ul></div><div class="actions"><button type="submit">Publicar e atualizar o site</button>';
if ($editSlug) echo '<a class="preview" target="_blank" rel="noopener" href="/blog/' . e($editSlug) . '">Ver artigo ↗</a>';
echo '</div></form></section></div></main><script>const form=document.getElementById("article-form"),editor=document.getElementById("editor"),html=document.getElementById("html"),seoTitle=document.getElementById("seo-title"),description=document.getElementById("description"),excerpt=document.getElementById("excerpt"),imageAlt=form.elements.imageAlt;const setCheck=(name,ok)=>document.querySelector(`[data-check="${name}"]`)?.classList.toggle("ok",!!ok);function updateQuality(){const body=editor.innerHTML,text=(editor.innerText||"").trim(),words=text?text.split(/\s+/).length:0;document.getElementById("seo-title-count").textContent=`${seoTitle.value.length}/60`;document.getElementById("description-count").textContent=`${description.value.length}/160`;document.getElementById("excerpt-count").textContent=`${excerpt.value.length}/260`;setCheck("seo",seoTitle.value.trim().length>=30&&seoTitle.value.trim().length<=60);setCheck("description",description.value.trim().length>=120&&description.value.trim().length<=160);setCheck("excerpt",excerpt.value.trim().length>=80);setCheck("h2",/<h2\b/i.test(body));setCheck("answer",/<blockquote\b/i.test(body));setCheck("faq",/perguntas frequentes/i.test(text)&&editor.querySelectorAll("h3").length>=3);setCheck("source",/href=["\x27]https?:\/\//i.test(body));setCheck("depth",words>=900);setCheck("no-h1",!/<h1\b/i.test(body));setCheck("alt",imageAlt.value.trim().length>=20)}document.querySelectorAll("[data-command]").forEach(button=>button.addEventListener("click",()=>{let value=button.dataset.value||null;if(button.dataset.command==="createLink")value=prompt("Cole a URL do link:","https://");if(value!==null){document.execCommand(button.dataset.command,false,value);editor.focus();updateQuality()}}));[editor,seoTitle,description,excerpt,imageAlt].forEach(element=>element.addEventListener("input",updateQuality));form.addEventListener("submit",()=>{html.value=editor.innerHTML});updateQuality();</script></body></html>';
