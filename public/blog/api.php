<?php
// api.php — read-only JSON feed consumed by the FT Funding site.
//   /blog/api.php             -> { "posts": [ …all posts, newest first… ] }
//   /blog/api.php?slug=<slug> -> one post object (404 if unknown)
// Cookie-free and uncached, so a post published in the admin panel shows up
// on /resources immediately.
require_once __DIR__ . '/admin/core.php';   // helpers only — no session for visitors
no_store_cache();
header('Content-Type: application/json; charset=utf-8');

$slug = isset($_GET['slug']) ? basename((string)$_GET['slug']) : '';

if ($slug !== '') {
    $post = load_post($slug);
    if (!$post) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }
    echo json_encode($post, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['posts' => all_posts()], JSON_UNESCAPED_UNICODE);
