<?php
require_once __DIR__ . '/init.php';
require_auth();

// Delete only via POST (with a valid token) so it can never fire from a link or prefetch.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
csrf_check();

$slug = basename((string)($_POST['slug'] ?? ''));
$post = load_post($slug);

if ($post) {
    @unlink(POSTS_DIR . "/$slug.json");
    // Remove the featured image and every image used inside a block.
    if (!empty($post['image'])) {
        @unlink(UPLOADS_DIR . '/' . basename($post['image']));
    }
    foreach (($post['blocks'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'image' && !empty($b['src'])) {
            @unlink(UPLOADS_DIR . '/' . basename($b['src']));
        }
    }
}

header('Location: dashboard.php');
exit;
