<?php
require_once __DIR__ . '/init.php';
require_auth();

// POST + valid token only, so it can't fire from a link or prefetch.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
csrf_check();

$slug = basename((string)($_POST['slug'] ?? ''));
$post = load_post($slug);
if (!$post) { header('Location: dashboard.php'); exit; }

/* Title + slug for the copy. Both stay unique: "X (Copy)", then "X (Copy 2)"… */
$origTitle = (string)($post['title'] ?? 'Untitled');
$copyTitle = trim($origTitle . ' (Copy)');
$newSlug   = slugify($copyTitle);
if ($newSlug === '') $newSlug = 'post';
$n = 2;
while (is_file(POSTS_DIR . "/$newSlug.json")) {
    $copyTitle = trim($origTitle . " (Copy $n)");
    $newSlug   = slugify($copyTitle);
    if ($newSlug === '') $newSlug = 'post-' . $n;
    $n++;
}

/* Copy an image file to a new, uniquely named file so the duplicate never
   shares pixels with the original (editing/deleting one won't affect the other). */
function copy_image(string $name, string $slug): string {
    $name = basename($name);
    $src  = UPLOADS_DIR . '/' . $name;
    if ($name === '' || !is_file($src)) return '';
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $new  = $slug . '-' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
    return @copy($src, UPLOADS_DIR . '/' . $new) ? $new : '';
}

// Featured image.
$featured = !empty($post['image']) ? copy_image($post['image'], $newSlug) : '';

// Block images.
$blocks = [];
foreach (($post['blocks'] ?? []) as $b) {
    if (($b['type'] ?? '') === 'image' && !empty($b['src'])) {
        $copied = copy_image($b['src'], $newSlug);
        if ($copied === '') continue;          // skip if the source image is missing
        $b['src'] = $copied;
    }
    $blocks[] = $b;
}

$now = date('c');
$record = [
    'slug'       => $newSlug,
    'title'      => $copyTitle,
    'image'      => $featured,
    'categories' => $post['categories'] ?? [],
    'tags'       => $post['tags'] ?? [],
    'blocks'     => $blocks,
    'date'       => $now,
    'updated'    => $now,
];

if (!is_dir(POSTS_DIR)) mkdir(POSTS_DIR, 0755, true);
file_put_contents(POSTS_DIR . "/$newSlug.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Drop straight into the editor for the new copy so you can tweak it right away.
header('Location: editor.php?slug=' . urlencode($newSlug));
exit;
