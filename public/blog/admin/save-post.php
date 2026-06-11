<?php
require_once __DIR__ . '/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }

// If the request body exceeded post_max_size, PHP discards the ENTIRE POST
// (including the CSRF token), leaving $_POST empty. Catch that here and show a
// clear "too large" message instead of a confusing "Bad request token" 403.
// (The typed content is gone server-side in this case — it never arrived.)
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    header('Location: editor.php?err=toolarge');
    exit;
}

csrf_check();

$title    = trim((string)($_POST['title'] ?? ''));
$original = basename((string)($_POST['original_slug'] ?? '')); // empty => new post
$payload  = json_decode((string)($_POST['payload'] ?? '[]'), true);
if (!is_array($payload)) $payload = [];

/* Where uploaded images go. Returns the saved filename, or null if the upload
   isn't a real jpg/png/webp. Validates by the file's actual contents. */
function save_uploaded_image(array $file, string $slug): ?string {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    $info    = getimagesize($file['tmp_name']);            // false if not a real image
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if ($info === false || !isset($allowed[$info['mime']])) return null;

    $name = $slug . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $name)) return null;
    return $name;
}

/* True when a file was actually submitted in this field (vs. left empty). */
function image_uploaded(array $file): bool {
    return isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE;
}
/* Validate WITHOUT moving — used for the up-front check so a bad upload can be
   rejected before any file is written or deleted. */
function image_is_valid(array $file): bool {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return false;
    $info = getimagesize($file['tmp_name']);
    return $info !== false && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true);
}

/* Normalise a JSON array of category/tag strings: trim, cap length, de-dupe
   case-insensitively (keeping the first spelling), drop blanks. */
function clean_terms($raw): array {
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        if (mb_strlen($t) > 50) $t = mb_substr($t, 0, 50);
        $key = mb_strtolower($t);
        if (!isset($out[$key])) $out[$key] = $t;   // keep first spelling
    }
    return array_values($out);
}

/* Send the user back to the editor with a message.
   $code: 'dup' = title already taken, anything else = missing title/blocks. */
function bounce(string $original, string $code = 'err'): never {
    // Remember what was typed so the editor can refill it — no lost work.
    $_SESSION['form_flash'] = [
        'title'      => (string)($_POST['title'] ?? ''),
        'payload'    => (string)($_POST['payload'] ?? '[]'),
        'categories' => (string)($_POST['categories'] ?? '[]'),
        'tags'       => (string)($_POST['tags'] ?? '[]'),
    ];
    $q = 'err=' . $code;
    $back = $original !== '' ? 'editor.php?slug=' . urlencode($original) . '&' . $q : 'editor.php?' . $q;
    header("Location: $back");
    exit;
}

// Make sure the data folders exist.
if (!is_dir(POSTS_DIR))   mkdir(POSTS_DIR, 0755, true);
if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);

$existing = $original !== '' ? load_post($original) : null;

/* --- Slug now comes straight from the title -------------------------------
   "Test Post 02" -> test-post-02.json  ->  /post.php?slug=test-post-02 */
if ($title === '') {
    bounce($original);   // title is required
}
$slug = slugify($title);
if ($slug === '') $slug = 'post';

/* --- Enforce unique titles ------------------------------------------------
   One JSON file per slug. A clash is fine only when it's THIS post's own file
   (i.e. you edited a post without changing its title). */
$isOwnFile = $existing && ($existing['slug'] ?? '') === $slug;
if (is_file(POSTS_DIR . "/$slug.json") && !$isOwnFile) {
    bounce($original, 'dup');
}

/* --- Validate every submitted image up-front ------------------------------
   If anything was uploaded that isn't a real jpg/png/webp (or was too big),
   bail out NOW — before we move or delete any file — so we never orphan an
   upload or wipe the existing post's image on a failed save. */
$submitted = [];
if (image_uploaded($_FILES['image'] ?? [])) $submitted[] = $_FILES['image'];
foreach ($payload as $blk) {
    if (($blk['type'] ?? '') === 'image') {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($blk['key'] ?? ''));
        if ($key !== '' && image_uploaded($_FILES[$key] ?? [])) $submitted[] = $_FILES[$key];
    }
}
foreach ($submitted as $f) {
    if (!image_is_valid($f)) bounce($original, 'image');
}

/* --- Build the blocks from the JSON payload ------------------------------- */
$blocks      = [];
$keptImages  = [];   // every image filename the saved post still points at

foreach ($payload as $blk) {
    $type = $blk['type'] ?? '';

    if ($type === 'heading') {
        $text = trim((string)($blk['text'] ?? ''));
        if ($text === '') continue;
        $level = (($blk['level'] ?? 'h2') === 'h3') ? 'h3' : 'h2';
        $blocks[] = ['type' => 'heading', 'level' => $level, 'text' => $text];

    } elseif ($type === 'paragraph') {
        $text = rtrim((string)($blk['text'] ?? ''));
        if (trim($text) === '') continue;
        $blocks[] = ['type' => 'paragraph', 'text' => $text];

    } elseif ($type === 'list') {
        $items = array_values(array_filter(
            array_map(fn($x) => trim((string)$x), (array)($blk['items'] ?? [])),
            fn($x) => $x !== ''
        ));
        if (!$items) continue;
        $style = (($blk['style'] ?? 'bullet') === 'number') ? 'number' : 'bullet';
        $blocks[] = ['type' => 'list', 'style' => $style, 'items' => $items];

    } elseif ($type === 'image') {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($blk['key'] ?? ''));
        $src = basename((string)($blk['existing'] ?? ''));   // keep the old one by default

        if ($key !== '' && isset($_FILES[$key])) {
            $saved = save_uploaded_image($_FILES[$key], $slug);
            if ($saved !== null) {
                if ($src !== '' && $src !== $saved) @unlink(UPLOADS_DIR . '/' . $src);
                $src = $saved;
            }
        }
        if ($src === '') continue;   // an image block with no picture is dropped

        $block = ['type' => 'image', 'src' => $src];
        $caption = trim((string)($blk['caption'] ?? ''));
        if ($caption !== '') $block['caption'] = $caption;
        $blocks[] = $block;
        $keptImages[] = $src;
    }
}

// A post needs at least one real block (title was already checked above).
if (empty($blocks)) {
    bounce($original);
}

/* --- Featured image -------------------------------------------------------
   New upload replaces it; otherwise keep whatever the post already had. */
$featured = $existing['image'] ?? '';
if (isset($_FILES['image'])) {
    $saved = save_uploaded_image($_FILES['image'], $slug);
    if ($saved !== null) {
        if ($featured !== '' && $featured !== $saved) @unlink(UPLOADS_DIR . '/' . $featured);
        $featured = $saved;
    }
}
if ($featured !== '') $keptImages[] = $featured;

/* --- Delete images that were removed during this edit --------------------- */
if ($existing) {
    $oldImages = [];
    if (!empty($existing['image'])) $oldImages[] = $existing['image'];
    foreach (($existing['blocks'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'image' && !empty($b['src'])) $oldImages[] = $b['src'];
    }
    foreach (array_diff($oldImages, $keptImages) as $orphan) {
        @unlink(UPLOADS_DIR . '/' . basename($orphan));
    }
}

/* --- Write the post data (raw text; escaping happens at render time) ------ */
$record = [
    'slug'       => $slug,
    'title'      => $title,
    'image'      => $featured,
    'categories' => clean_terms($_POST['categories'] ?? '[]'),
    'tags'       => clean_terms($_POST['tags'] ?? '[]'),
    'blocks'     => $blocks,
    'date'       => $existing['date'] ?? date('c'),  // keep original publish date when editing
    'updated'    => date('c'),
];

file_put_contents(POSTS_DIR . "/$slug.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

/* If the title (and therefore the slug) changed while editing, remove the old
   file so the post lives at exactly one filename/URL. */
if ($existing && $original !== '' && $original !== $slug) {
    @unlink(POSTS_DIR . "/$original.json");
}

header('Location: dashboard.php');
exit;
