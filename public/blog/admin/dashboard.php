<?php
require_once __DIR__ . '/init.php';
require_auth();

// Sort comes from the URL; per-tab memory (sessionStorage, restored by the
// small script in <head>) carries it across edit/save/back and resets on tab close.
$sort = valid_sort($_GET['sort'] ?? 'newest');
$all  = sort_posts(all_posts(), $sort);
$per   = ADMIN_PER_PAGE;
$pages = max(1, (int)ceil(count($all) / $per));
$page  = max(1, min((int)($_GET['page'] ?? 1), $pages));
$posts = array_slice($all, ($page - 1) * $per, $per);
$pageUrl = fn(int $n) => 'dashboard.php' . build_query(['sort' => $sort !== 'newest' ? $sort : null, 'page' => $n > 1 ? $n : null]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Posts — Admin</title>
  <link rel="stylesheet" href="admin.css">
  <script>
    // Tab-scoped sort memory: remembers the chosen sort for THIS tab only and
    // restores it after editing. Runs in <head> so the restore happens before
    // the list paints (no flash). Cleared automatically when the tab closes.
    (function () {
      try {
        var p = new URLSearchParams(location.search);
        if (p.has('sort')) {
          sessionStorage.setItem('adminSort', p.get('sort'));
        } else {
          var s = sessionStorage.getItem('adminSort');
          if (s && s !== 'newest') location.replace('dashboard.php?sort=' + encodeURIComponent(s));
        }
      } catch (e) {}
    })();
  </script>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <h1>Posts</h1>
      <div class="right">
        <a class="btn btn-primary" href="editor.php">+ Add new post</a>
        <a class="btn" href="logout.php">Sign out</a>
      </div>
    </div>

    <?php if (!empty($all)): ?>
      <div class="list-tools">
        <label for="sort-select" class="list-tools-label">Sort:</label>
        <select id="sort-select" class="sort-select"><?= sort_options_html($sort) ?></select>
      </div>
    <?php endif; ?>

    <div class="panel">
      <?php if (empty($posts)): ?>
        <div class="empty-state">
          No posts yet.<br>
          Click <strong>“Add new post”</strong> to create your first one.
        </div>
      <?php else: ?>
        <?php foreach ($posts as $p): ?>
          <div class="post-row">
            <?php if (!empty($p['image'])): ?>
              <img class="thumb" src="<?= e(UPLOADS_URL . '/' . $p['image']) ?>" alt="">
            <?php else: ?>
              <div class="thumb empty">no image</div>
            <?php endif; ?>

            <div class="meta">
              <p class="title"><?= e($p['title'] ?? '(untitled)') ?></p>
              <p class="sub">
                <?= e(date('M j, Y', strtotime($p['date'] ?? 'now'))) ?>
                <?php if (!empty($p['updated']) && $p['updated'] !== ($p['date'] ?? '')): ?>
                  · edited <?= e(date('M j, Y', strtotime($p['updated']))) ?>
                <?php endif; ?>
              </p>
            </div>

            <div class="actions">
              <a class="btn btn-sm" href="<?= e(BASE_URL) ?>/post/<?= e(rawurlencode($p['slug'])) ?>" target="_blank">View</a>
              <a class="btn btn-sm" href="editor.php?slug=<?= e(urlencode($p['slug'])) ?>">Edit</a>
              <!-- duplicate clones the post (and its images) and opens the editor on the copy -->
              <form method="post" action="duplicate-post.php" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
                <button type="submit" class="btn btn-sm">Duplicate</button>
              </form>
              <!-- delete is a POST form (not a link) so it can't be triggered by accident or prefetch -->
              <form method="post" action="delete-post.php"
                    onsubmit="return confirm('Delete this post permanently? This cannot be undone.');"
                    style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?= pagination_html($page, $pages, $pageUrl) ?>
  </div>

  <script>
    // Sort dropdown — reload the dashboard in the chosen order.
    (function () {
      var s = document.getElementById('sort-select');
      if (!s) return;
      s.addEventListener('change', function () {
        var u = new URL(window.location.href);
        u.searchParams.set('sort', s.value);   // always explicit — incl. 'newest' (sort is session-backed)
        u.searchParams.delete('page');
        window.location = u.toString();
      });
    })();
  </script>
</body>
</html>
