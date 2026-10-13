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

    // Minimal public homepage (HTML, no login)
    $r->get('/', function (Request $req, array $p): void {
        Response::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Sonatola Upazila Digital Platform</title></head><body style="font-family:system-ui;margin:2rem">'
            . '<h1>Sonatola Upazila Digital Platform</h1>'
            . '<p>Backend API is running. Public endpoints under <code>/api/v1</code>.</p>'
            . '<ul><li><a href="/api/v1/health">Health</a></li><li><a href="/api/v1/news">News</a></li>'
            . '<li><a href="/api/v1/notices">Notices</a></li><li><a href="/api/v1/services">Services</a></li></ul>'
            . '<p>No registration required to view public information.</p></body></html>'
        );
    });
}
