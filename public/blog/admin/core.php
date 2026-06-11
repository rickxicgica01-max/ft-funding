<?php
// core.php — paths + pure helpers shared by BOTH the public site and the admin.
// Deliberately contains NO session, auth, or secrets, so public pages
// (index.php, post.php) can include it without starting a session — which keeps
// those pages cacheable and cookie-free for visitors.
declare(strict_types=1);

/* ----------------------------------------------------------------------
   CONFIG + PATHS
   All per-site settings live in /config.php (one level up from /admin).
   BASE_URL lets the blog sit at a web root OR inside a subfolder like /blog.
---------------------------------------------------------------------- */
$cfg = require dirname(__DIR__) . '/config.php';

define('SITE_NAME',       (string)($cfg['site_name'] ?? 'Blog'));
define('MAIN_SITE_URL',   (string)($cfg['main_site_url'] ?? ''));
define('MAIN_SITE_LABEL', (string)($cfg['main_site_label'] ?? 'Main site'));

// Normalise base_url to '' (root) or '/sub' (leading slash, no trailing slash).
$__base = '/' . trim((string)($cfg['base_url'] ?? ''), '/');
define('BASE_URL', $__base === '/' ? '' : $__base);

define('POSTS_DIR',   dirname(__DIR__) . '/posts');   // one JSON file per post
define('UPLOADS_DIR', dirname(__DIR__) . '/uploads'); // the actual image files
define('UPLOADS_URL', BASE_URL . '/uploads');         // public web path for <img src="">

define('POSTS_PER_PAGE', max(1, (int)($cfg['posts_per_page'] ?? 6)));   // public homepage
define('ADMIN_PER_PAGE', max(1, (int)($cfg['admin_per_page'] ?? 12)));  // admin dashboard

/* ----------------------------------------------------------------------
   HELPERS
---------------------------------------------------------------------- */
function e(string $v): string {           // escape for safe HTML output
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function slugify(string $text): string {  // "My Post!" -> "my-post"
    $s = strtolower(trim($text));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    // Titles with no Latin letters/digits (e.g. Chinese) would otherwise become
    // empty and collide. Derive a STABLE slug from the title so each distinct
    // title still gets its own unique, repeatable URL.
    if ($s === '' && trim($text) !== '') {
        $s = 'post-' . substr(sha1(trim($text)), 0, 8);
    }
    return $s;
}
function load_post(string $slug): ?array {
    $slug = basename($slug);              // basename() blocks ../ path tricks
    $f = POSTS_DIR . "/$slug.json";
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function all_posts(): array {
    $files = glob(POSTS_DIR . '/*.json') ?: [];
    $posts = [];
    foreach ($files as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) $posts[] = $d;
    }
    // newest first
    usort($posts, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    return $posts;
}

/* Lower-cased haystack of everything searchable in a post (title, categories,
   tags, and all block text), used by the homepage search box. */
function post_search_text(array $p): string {
    $parts = [$p['title'] ?? ''];
    foreach (($p['categories'] ?? []) as $c) $parts[] = $c;
    foreach (($p['tags'] ?? []) as $t)       $parts[] = $t;
    foreach (($p['blocks'] ?? []) as $b) {
        if (!empty($b['text']))    $parts[] = $b['text'];
        if (!empty($b['caption'])) $parts[] = $b['caption'];
        if (!empty($b['items']))   $parts[] = implode(' ', $b['items']);
    }
    return mb_strtolower(implode(' ', $parts));
}

/* Build a "?a=b&c=d" query string, dropping null/empty values. */
function build_query(array $params): string {
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return $params ? '?' . http_build_query($params) : '';
}

/* Normalise a ?category[]= / ?tag[]= GET value into a clean string list:
   accepts a string or array, ignores non-scalars, trims, drops blanks, dedupes. */
function clean_query_terms($raw): array {
    $out = [];
    foreach ((array)$raw as $v) {
        if (!is_scalar($v)) continue;
        $v = trim((string)$v);
        if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
    }
    return $out;
}

/* Re-order posts. Valid keys: newest (default), oldest, updated, title, title_desc. */
function sort_posts(array $posts, string $sort): array {
    switch ($sort) {
        case 'oldest':     usort($posts, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? '')); break;
        case 'updated':    usort($posts, fn($a, $b) => strcmp($b['updated'] ?? ($b['date'] ?? ''), $a['updated'] ?? ($a['date'] ?? ''))); break;
        case 'title':      usort($posts, fn($a, $b) => strnatcasecmp($a['title'] ?? '', $b['title'] ?? '')); break;
        case 'title_desc': usort($posts, fn($a, $b) => strnatcasecmp($b['title'] ?? '', $a['title'] ?? '')); break;
        default:           usort($posts, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? '')); break; // newest
    }
    return $posts;
}

/* Clamp a sort key to a known value. */
function valid_sort(?string $s): string {
    return in_array($s, ['newest', 'oldest', 'updated', 'title', 'title_desc'], true) ? $s : 'newest';
}

/* <option> tags for a sort <select>, with $current pre-selected. */
function sort_options_html(string $current): string {
    $opts = [
        'newest'     => 'Newest first',
        'oldest'     => 'Oldest first',
        'updated'    => 'Recently updated',
        'title'      => 'Title A–Z',
        'title_desc' => 'Title Z–A',
    ];
    $h = '';
    foreach ($opts as $v => $label) {
        $h .= '<option value="' . $v . '"' . ($v === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h;
}

/* Tell caches (the browser AND host-level caches like SiteGround's Dynamic
   Cache) not to store the public pages, so a newly published post appears
   immediately instead of only after a manual cache flush. */
function no_store_cache(): void {
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/* Reusable pagination bar. $urlFor(int $n) returns the link for page $n.
   Returns '' when there's only one page. Used by both the public homepage
   and the admin dashboard (each styles .pager in its own stylesheet). */
function pagination_html(int $page, int $totalPages, callable $urlFor): string {
    if ($totalPages <= 1) return '';
    $page = max(1, min($page, $totalPages));

    // Page numbers to show; 0 marks an ellipsis gap.
    if ($totalPages <= 7) {
        $nums = range(1, $totalPages);
    } else {
        $nums  = [1];
        $start = max(2, $page - 1);
        $end   = min($totalPages - 1, $page + 1);
        if ($start > 2) $nums[] = 0;
        for ($i = $start; $i <= $end; $i++) $nums[] = $i;
        if ($end < $totalPages - 1) $nums[] = 0;
        $nums[] = $totalPages;
    }

    $h = '<nav class="pager" aria-label="Pagination">';
    $h .= $page > 1
        ? '<a class="pager-link" href="' . e($urlFor($page - 1)) . '">‹ Prev</a>'
        : '<span class="pager-link disabled">‹ Prev</span>';
    foreach ($nums as $n) {
        if ($n === 0) { $h .= '<span class="pager-gap">…</span>'; continue; }
        $h .= $n === $page
            ? '<span class="pager-link current">' . $n . '</span>'
            : '<a class="pager-link" href="' . e($urlFor($n)) . '">' . $n . '</a>';
    }
    $h .= $page < $totalPages
        ? '<a class="pager-link" href="' . e($urlFor($page + 1)) . '">Next ›</a>'
        : '<span class="pager-link disabled">Next ›</span>';
    $h .= '</nav>';
    return $h;
}
