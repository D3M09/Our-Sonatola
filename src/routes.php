<?php
declare(strict_types=1);

/** Route table for /api/v1. Business logic lives in controllers/services. */
function registerRoutes(Router $r, PDO $pdo): void
{
    $pub = new PublicController($pdo);
    $auth = new AuthController($pdo);
    $comp = new ComplaintController($pdo);
    $adminC = new AdminContentController($pdo);
    $adminU = new AdminUserController($pdo);

    // Health + public (no login required)
    $r->get('/api/v1/health', [$pub, 'health']);
    $r->get('/api/v1/news', [$pub, 'news']);
    $r->get('/api/v1/news/{slug}', [$pub, 'newsShow']);
    $r->get('/api/v1/notices', [$pub, 'notices']);
    $r->get('/api/v1/unions', [$pub, 'unions']);
    $r->get('/api/v1/services', [$pub, 'services']);
    $r->get('/api/v1/categories', [$pub, 'categories']);

    // Auth
    $r->post('/api/v1/register', [$auth, 'register']);
    $r->post('/api/v1/login', [$auth, 'login']);
    $r->post('/api/v1/logout', [$auth, 'logout']);
    $r->get('/api/v1/me', [$auth, 'me']);
    $r->put('/api/v1/me', [$auth, 'updateProfile']);
    $r->post('/api/v1/password/request', [$auth, 'requestReset']);
    $r->post('/api/v1/password/reset', [$auth, 'performReset']);

    // Complaints
    $r->post('/api/v1/complaints', [$comp, 'submit']);
    $r->get('/api/v1/complaints/mine', [$comp, 'mine']);
    $r->get('/api/v1/complaints', [$comp, 'listAll']);
    $r->get('/api/v1/complaints/{id}', [$comp, 'show']);
    $r->post('/api/v1/complaints/{id}/assign', [$comp, 'assign']);
    $r->post('/api/v1/complaints/{id}/status', [$comp, 'status']);

    // Admin: news & notices
    $r->get('/api/v1/admin/news', [$adminC, 'newsList']);
    $r->post('/api/v1/admin/news', [$adminC, 'newsCreate']);
    $r->put('/api/v1/admin/news/{id}', [$adminC, 'newsUpdate']);
    $r->delete('/api/v1/admin/news/{id}', [$adminC, 'newsDelete']);
    $r->get('/api/v1/admin/notices', [$adminC, 'noticeList']);
    $r->post('/api/v1/admin/notices', [$adminC, 'noticeCreate']);
    $r->put('/api/v1/admin/notices/{id}', [$adminC, 'noticeUpdate']);
    $r->delete('/api/v1/admin/notices/{id}', [$adminC, 'noticeDelete']);

    // Admin: users / roles / audit
    $r->get('/api/v1/admin/users', [$adminU, 'listUsers']);
    $r->put('/api/v1/admin/users/{id}/status', [$adminU, 'setStatus']);
    $r->post('/api/v1/admin/users/{id}/roles', [$adminU, 'assignRole']);
    $r->get('/api/v1/admin/roles', [$adminU, 'listRoles']);
    $r->get('/api/v1/admin/permissions', [$adminU, 'listPermissions']);
    $r->get('/api/v1/admin/audit-logs', [$adminU, 'auditLogs']);

    // Static SPA shell (no PHP templates, no DB read; data via /api/v1 JSON).
    // Security headers for local dev: no HSTS (HTTP), no 'unsafe-inline'.
    $r->get('/', function (Request $req, array $p): void {
        if (!headers_sent()) {
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        }
        Response::html(
            '<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title data-i18n="site.name">Sonatola</title>'
            . '<link rel="stylesheet" href="/css/site.css?v=4C1">'
            . '<script type="module" src="/js/app.js?v=4C1"></script>'
            . '<!-- CAPACITOR: admin panel JS is web-only (Phase C) — do NOT bundle any future public/js/admin*.js into the Capacitor Public+Citizen build -->'
            . '</head><body>'
            . '<a class="skip-link" href="#main" data-i18n="a11y.skip"></a>'
            . '<header class="site-header"><div class="container header-inner">'
            . '<a class="brand" href="#/" data-route="home"><img class="brand-logo" src="/img/logo.svg" alt="" data-i18n-alt="site.logo_alt" aria-hidden="true"><span class="brand-name" data-i18n="site.name"></span></a>'
            . '<nav class="site-nav" aria-label="primary"><ul>'
            . '<li><a href="#/news" data-route="news" data-i18n="nav.news"></a></li>'
            . '<li><a href="#/notices" data-route="notices" data-i18n="nav.notices"></a></li>'
            . '<li><a href="#/services" data-route="services" data-i18n="nav.services"></a></li>'
            . '<li><a href="#/unions" data-route="unions" data-i18n="nav.unions"></a></li>'
            . '</ul></nav>'
            . '<nav class="auth-nav" aria-label="account"><ul id="auth-nav"></ul></nav>'
            . '<button id="locale-toggle" class="locale-toggle" type="button" data-i18n="locale.toggle_label" data-i18n-aria-label="locale.toggle_aria"></button>'
            . '</div></header>'
            . '<main id="main"><div id="view-home"><section class="hero"><div class="container">'
            . '<h1 data-i18n="home.hero_title"></h1><p class="hero-sub" data-i18n="home.hero_subtitle"></p>'
            . '</div></section>'
            . '<section id="news" class="section"><div class="container"><h2 data-i18n="news.title"></h2>'
            . '<p class="section-status" data-section="news" role="status"></p><div class="cards" data-list="news"></div></div></section>'
            . '<section id="notices" class="section"><div class="container"><h2 data-i18n="notices.title"></h2>'
            . '<p class="section-status" data-section="notices" role="status"></p><ul class="notice-list" data-list="notices"></ul></div></section>'
            . '<section id="services" class="section"><div class="container"><h2 data-i18n="links.services_title"></h2>'
            . '<div class="cards"><article class="card"><p data-i18n="links.services_text"></p></article></div></div></section>'
            . '<section id="unions" class="section"><div class="container"><h2 data-i18n="links.unions_title"></h2>'
            . '<div class="cards"><article class="card"><p data-i18n="links.unions_text"></p></article></div></div></section>'
            . '</div><div id="view" hidden></div></main>'
            . '<footer class="site-footer"><div class="container"><p data-i18n="footer.copyright"></p><p data-i18n="footer.contact"></p></div></footer>'
            . '</body></html>'
        );
    });
}
