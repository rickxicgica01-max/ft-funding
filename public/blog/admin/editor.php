<?php
require_once __DIR__ . '/init.php';
require_auth();

/* If a slug is present in the URL, we're EDITING that post.
   If not, we're creating a NEW one. Same form either way. */
$slug    = isset($_GET['slug']) ? basename($_GET['slug']) : '';
$editing = $slug !== '';
$post    = $editing ? load_post($slug) : null;

if ($editing && !$post) {
    http_response_code(404);
    exit('Post not found.');
}

$title  = $post['title'] ?? '';
$image  = $post['image'] ?? '';                 // featured image filename
$blocks = is_array($post['blocks'] ?? null) ? $post['blocks'] : [];
$categories = is_array($post['categories'] ?? null) ? $post['categories'] : [];
$tags       = is_array($post['tags'] ?? null) ? $post['tags'] : [];
$err    = (string)($_GET['err'] ?? '');

// If we just bounced back from a failed save, refill the form with exactly what
// was typed (PRG flash) instead of the last-saved version — so no work is lost.
// (A freshly chosen image file can't be restored — browsers forbid pre-filling
//  file inputs — but all text, captions, and existing images come back.)
$flash = $_SESSION['form_flash'] ?? null;
unset($_SESSION['form_flash']);
if ($flash && $err !== '') {
    $title = (string)($flash['title'] ?? $title);
    $flashBlocks = json_decode((string)($flash['payload'] ?? '[]'), true);
    if (is_array($flashBlocks)) {
        foreach ($flashBlocks as &$fb) {
            // The submit payload stores an image's filename under 'existing';
            // the editor's JS builder reads it as 'src'.
            if (($fb['type'] ?? '') === 'image') $fb['src'] = $fb['existing'] ?? '';
        }
        unset($fb);
        $blocks = $flashBlocks;
    }
    $fc = json_decode((string)($flash['categories'] ?? '[]'), true);
    if (is_array($fc)) $categories = $fc;
    $ft = json_decode((string)($flash['tags'] ?? '[]'), true);
    if (is_array($ft)) $tags = $ft;
}

// Every slug already in use (except this post's own), so the page can warn the
// moment you type a title that would collide with an existing post.
$takenSlugs = [];
foreach (all_posts() as $pp) {
    if (($pp['slug'] ?? '') !== $slug) $takenSlugs[] = $pp['slug'];
}

// The existing blocks are handed to the JavaScript builder as JSON so it can
// re-create the same widgets when you re-open a post to edit it.
// HEX flags so block content can never break out of the <script> tag below
// (e.g. a literal "</script>" inside a paragraph). Public pages already escape
// via e(); this protects the admin editor's inline JSON.
$jsonFlags  = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$blocksJson = json_encode($blocks, $jsonFlags);
$takenJson  = json_encode($takenSlugs, $jsonFlags);
$categoriesJson = json_encode($categories, $jsonFlags);
$tagsJson       = json_encode($tags, $jsonFlags);
$uploadsUrl = UPLOADS_URL;

// Live upload limits from PHP config, shown in hints and error messages so they
// always reflect the real server settings (e.g. "128M" -> "128 MB").
$fmt       = fn($v) => str_ireplace(['K', 'M', 'G'], [' KB', ' MB', ' GB'], (string)$v);
$maxUpload = $fmt(ini_get('upload_max_filesize'));  // per-image limit
$maxPost   = $fmt(ini_get('post_max_size'));        // whole-submission limit
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $editing ? 'Edit post' : 'New post' ?> — Admin</title>
  <link rel="stylesheet" href="admin.css">
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <h1><?= $editing ? 'Edit post' : 'New post' ?></h1>
      <div class="right">
        <a class="btn" href="dashboard.php">← Back to posts</a>
      </div>
    </div>

    <div class="panel">
      <?php if ($err === 'dup'): ?>
        <div class="notice">A post with that title already exists. Please choose a different title.</div>
      <?php elseif ($err === 'image'): ?>
        <div class="notice">An image couldn't be uploaded — each image must be a JPG, PNG, or WebP under <?= e($maxUpload) ?>. Please use a smaller file and try again.</div>
      <?php elseif ($err === 'toolarge'): ?>
        <div class="notice">Your images were too large, so the post couldn't be saved. Keep the whole upload under <?= e($maxPost) ?> (and each image under <?= e($maxUpload) ?>), then try again.</div>
      <?php elseif ($err !== ''): ?>
        <div class="notice">A title and at least one content block are required.</div>
      <?php endif; ?>

      <form method="post" action="save-post.php" enctype="multipart/form-data" id="post-form">
        <?= csrf_field() ?>
        <input type="hidden" name="original_slug" value="<?= e($slug) ?>">
        <!-- Filled in by JavaScript the moment you submit: the full ordered list of blocks. -->
        <input type="hidden" name="payload" id="payload">
        <!-- Filled in by the chip inputs below (JSON arrays). -->
        <input type="hidden" name="categories" id="categories-field">
        <input type="hidden" name="tags" id="tags-field">

        <div class="field">
          <label for="title">Title</label>
          <input type="text" id="title" name="title" value="<?= e($title) ?>" required>
          <p class="dup-warn" id="dup-warn" hidden>⚠ A post with this title already exists — please choose a different title.</p>
        </div>

        <div class="field">
          <label for="image">Featured image</label>
          <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
          <p class="hint">JPG, PNG, or WebP · max <?= e($maxUpload) ?> per image.</p>
          <img id="featured-preview" alt="Preview">
          <?php if ($editing && $image): ?>
            <div class="current-image">
              <p class="hint">Current featured image:</p>
              <img src="<?= e(UPLOADS_URL . '/' . $image) ?>" alt="">
            </div>
          <?php endif; ?>
        </div>

        <div class="field">
          <label for="cat-entry">Categories</label>
          <div class="taginput" id="cat-input">
            <span class="tag-chips"></span>
            <input type="text" class="tag-entry" id="cat-entry" placeholder="Type a category and press Enter">
          </div>
        </div>

        <div class="field">
          <label for="tag-entry">Tags</label>
          <div class="taginput" id="tag-input">
            <span class="tag-chips"></span>
            <input type="text" class="tag-entry" id="tag-entry" placeholder="Type a tag and press Enter">
          </div>
        </div>

        <hr class="rule">

        <div class="field">
          <label>Content blocks</label>
          <p class="hint">Build the body of your post. Add as many blocks as you like, in any order.</p>
          <div id="blocks"></div>
        </div>

        <!-- Buttons to add a new block of each type. -->
        <div class="add-bar">
          <span class="add-bar-label">Add block:</span>
          <button type="button" class="btn btn-sm" data-add="heading">＋ Heading</button>
          <button type="button" class="btn btn-sm" data-add="paragraph">＋ Paragraph</button>
          <button type="button" class="btn btn-sm" data-add="list">＋ List</button>
          <button type="button" class="btn btn-sm" data-add="image">＋ Image</button>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Publish post' ?></button>
          <button type="button" class="btn" id="preview-btn">Preview</button>
          <a class="btn" href="dashboard.php">Cancel</a>
        </div>
      </form>
    </div>
  </div>

  <!-- ====================  BLOCK BUILDER  ==================== -->
  <template id="tpl-heading">
    <div class="block-item" data-type="heading">
      <div class="block-head">
        <span class="block-tag">Heading</span>
        <select class="b-level">
          <option value="h2">Heading (large)</option>
          <option value="h3">Subheading (small)</option>
        </select>
        <span class="block-controls"></span>
      </div>
      <input type="text" class="b-text" placeholder="Heading text">
    </div>
  </template>

  <template id="tpl-paragraph">
    <div class="block-item" data-type="paragraph">
      <div class="block-head">
        <span class="block-tag">Paragraph</span>
        <span class="block-controls"></span>
      </div>
      <textarea class="b-text" rows="4" placeholder="Write a paragraph. Line breaks are kept."></textarea>
    </div>
  </template>

  <template id="tpl-list">
    <div class="block-item" data-type="list">
      <div class="block-head">
        <span class="block-tag">List</span>
        <select class="b-style">
          <option value="bullet">Bulleted • </option>
          <option value="number">Numbered 1. </option>
        </select>
        <span class="block-controls"></span>
      </div>
      <textarea class="b-text" rows="4" placeholder="One item per line."></textarea>
      <p class="hint">Each line becomes one list item.</p>
    </div>
  </template>

  <template id="tpl-image">
    <div class="block-item" data-type="image">
      <div class="block-head">
        <span class="block-tag">Image</span>
        <span class="block-controls"></span>
      </div>
      <input type="file" class="b-file" accept="image/jpeg,image/png,image/webp">
      <p class="hint">JPG, PNG, or WebP · max <?= e($maxUpload) ?> per image.</p>
      <img class="b-preview" alt="Preview">
      <div class="b-current" hidden>
        <p class="hint">Current image:</p>
        <img class="b-current-img" alt="">
      </div>
      <input type="text" class="b-caption" placeholder="Caption (optional)">
    </div>
  </template>

  <script>
    const UPLOADS_URL = <?= json_encode($uploadsUrl) ?>;
    const blocksRoot  = document.getElementById('blocks');
    const form        = document.getElementById('post-form');
    const payloadEl   = document.getElementById('payload');
    let imgCounter    = 0;   // gives every image block its own unique file-input name

    // Build the small move-up / move-down / remove controls shared by every block.
    function makeControls() {
      const wrap = document.createElement('span');
      wrap.className = 'block-controls';
      wrap.innerHTML =
        '<button type="button" class="icon-btn" data-act="up"    title="Move up">&#9650;</button>' +
        '<button type="button" class="icon-btn" data-act="down"  title="Move down">&#9660;</button>' +
        '<button type="button" class="icon-btn danger" data-act="del" title="Remove">&times;</button>';
      return wrap;
    }

    // Create a block element from a template, optionally pre-filled with saved data.
    function addBlock(type, data) {
      data = data || {};
      const tpl = document.getElementById('tpl-' + type);
      if (!tpl) return;
      const el = tpl.content.firstElementChild.cloneNode(true);
      el.querySelector('.block-controls').replaceWith(makeControls());

      if (type === 'heading') {
        el.querySelector('.b-level').value = data.level || 'h2';
        el.querySelector('.b-text').value  = data.text  || '';
      } else if (type === 'paragraph') {
        el.querySelector('.b-text').value = data.text || '';
      } else if (type === 'list') {
        el.querySelector('.b-style').value = data.style || 'bullet';
        el.querySelector('.b-text').value  = (data.items || []).join('\n');
      } else if (type === 'image') {
        const key = 'img_' + (imgCounter++);
        el.dataset.key = key;
        const file = el.querySelector('.b-file');
        file.name = key;                       // <- this is how the upload reaches PHP
        el.querySelector('.b-caption').value = data.caption || '';
        const current = el.querySelector('.b-current');
        if (data.src) {
          el.dataset.existing = data.src;
          current.hidden = false;
          el.querySelector('.b-current-img').src = UPLOADS_URL + '/' + data.src;
        }
        const preview = el.querySelector('.b-preview');
        file.addEventListener('change', () => {
          // Only update on a real pick. Cancelling the picker leaves the
          // previous choice untouched instead of wiping the preview.
          if (file.files[0]) {
            preview.src = URL.createObjectURL(file.files[0]);
            preview.style.display = 'block';
            if (current) current.hidden = true;   // new image replaces the old one in view
          }
        });
      }

      blocksRoot.appendChild(el);
    }

    // One click handler for every block control (move / delete).
    blocksRoot.addEventListener('click', (ev) => {
      const btn = ev.target.closest('[data-act]');
      if (!btn) return;
      const item = btn.closest('.block-item');
      if (btn.dataset.act === 'del')  item.remove();
      if (btn.dataset.act === 'up'   && item.previousElementSibling) item.parentNode.insertBefore(item, item.previousElementSibling);
      if (btn.dataset.act === 'down' && item.nextElementSibling)     item.parentNode.insertBefore(item.nextElementSibling, item);
    });

    // The "Add block" buttons.
    document.querySelectorAll('[data-add]').forEach(b =>
      b.addEventListener('click', () => addBlock(b.dataset.add)));

    // Featured image live preview.
    const fImg = document.getElementById('image');
    const fPrev = document.getElementById('featured-preview');
    const fCurrent = document.querySelector('.current-image');
    fImg.addEventListener('change', () => {
      // Only update on a real pick — cancelling no longer clears the preview.
      if (fImg.files[0]) {
        fPrev.src = URL.createObjectURL(fImg.files[0]);
        fPrev.style.display = 'block';
        if (fCurrent) fCurrent.style.display = 'none';   // new image replaces the old one in view
      }
    });

    // Live duplicate-title guard. The slug (and JSON filename) is built from the
    // title, so two posts can't share one. Mirrors slugify() in init.php.
    const takenSlugs = <?= $takenJson ?>;
    const titleEl    = document.getElementById('title');
    const dupWarn    = document.getElementById('dup-warn');
    const submitBtn  = form.querySelector('button[type="submit"]');
    function slugifyJS(t) {
      return t.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }
    function checkTitle() {
      const s = slugifyJS(titleEl.value);
      const clash = s !== '' && takenSlugs.indexOf(s) !== -1;
      dupWarn.hidden = !clash;
      submitBtn.disabled = clash;
    }
    titleEl.addEventListener('input', checkTitle);
    checkTitle();

    // On submit, serialize every block into the hidden "payload" field as JSON.
    // (The actual image files travel separately as normal file uploads.)
    form.addEventListener('submit', () => {
      const out = [];
      blocksRoot.querySelectorAll('.block-item').forEach(el => {
        const type = el.dataset.type;
        if (type === 'heading') {
          out.push({ type: type, level: el.querySelector('.b-level').value, text: el.querySelector('.b-text').value });
        } else if (type === 'paragraph') {
          out.push({ type: type, text: el.querySelector('.b-text').value });
        } else if (type === 'list') {
          out.push({ type: type, style: el.querySelector('.b-style').value,
                     items: el.querySelector('.b-text').value.split('\n') });
        } else if (type === 'image') {
          out.push({ type: type, key: el.dataset.key, existing: el.dataset.existing || '',
                     caption: el.querySelector('.b-caption').value });
        }
      });
      payloadEl.value = JSON.stringify(out);
    });

    // ----- Preview: opens a NEW TAB rendered from the current editor state
    //       (incl. just-picked images). Saves nothing. Uses a Blob URL with
    //       absolute resource URLs so the page + CSS + images reliably load. -----
    const previewBtn       = document.getElementById('preview-btn');
    const featuredExisting = <?= json_encode($image) ?>;
    const ORIGIN           = window.location.origin;
    const BLOG_CSS         = ORIGIN + <?= json_encode(BASE_URL . '/blog.css') ?>;

    function esc(s) {
      return String(s || '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function uploadUrl(name) { return ORIGIN + UPLOADS_URL + '/' + name; }

    // Build the post HTML from the CURRENT editor state, mirroring post.php.
    function buildPreviewHtml() {
      const title = titleEl.value.trim() || '(untitled)';
      let body = '';
      body += '<h1 class="article-title">' + esc(title) + '</h1>';
      body += '<p class="article-meta">Preview</p>';

      let featSrc = '';
      if (fImg.files[0]) featSrc = URL.createObjectURL(fImg.files[0]);
      else if (featuredExisting) featSrc = uploadUrl(featuredExisting);
      if (featSrc) body += '<img class="article-featured" src="' + featSrc + '" alt="">';

      blocksRoot.querySelectorAll('.block-item').forEach(el => {
        const type = el.dataset.type;
        if (type === 'heading') {
          const tag = el.querySelector('.b-level').value === 'h3' ? 'h3' : 'h2';
          const t = el.querySelector('.b-text').value.trim();
          if (t) body += '<' + tag + '>' + esc(t) + '</' + tag + '>';
        } else if (type === 'paragraph') {
          const t = el.querySelector('.b-text').value;
          if (t.trim()) body += '<p>' + esc(t).replace(/\n/g, '<br>') + '</p>';
        } else if (type === 'list') {
          const tag = el.querySelector('.b-style').value === 'number' ? 'ol' : 'ul';
          const items = el.querySelector('.b-text').value.split('\n').map(s => s.trim()).filter(Boolean);
          if (items.length) body += '<' + tag + '>' + items.map(i => '<li>' + esc(i) + '</li>').join('') + '</' + tag + '>';
        } else if (type === 'image') {
          const file = el.querySelector('.b-file');
          let src = '';
          if (file.files[0]) src = URL.createObjectURL(file.files[0]);
          else if (el.dataset.existing) src = uploadUrl(el.dataset.existing);
          if (src) {
            const cap = el.querySelector('.b-caption').value.trim();
            body += '<figure class="post-image"><img src="' + src + '" alt="' + esc(cap) + '">'
                 + (cap ? '<figcaption>' + esc(cap) + '</figcaption>' : '') + '</figure>';
          }
        }
      });

      // Categories & tags — mirror post.php's .post-terms (read the chip inputs).
      let terms = '';
      try { JSON.parse(document.getElementById('categories-field').value || '[]').forEach(c => terms += '<span class="term">' + esc(c) + '</span>'); } catch (e) {}
      try { JSON.parse(document.getElementById('tags-field').value || '[]').forEach(t => terms += '<span class="term">#' + esc(t) + '</span>'); } catch (e) {}
      if (terms) body += '<div class="post-terms">' + terms + '</div>';

      return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' +
        '<meta name="viewport" content="width=device-width, initial-scale=1">' +
        '<title>' + esc(title) + ' — Preview</title>' +
        '<link rel="stylesheet" href="' + esc(BLOG_CSS) + '">' +
        '</head><body><article class="article">' + body + '</article></body></html>';
    }

    previewBtn.addEventListener('click', () => {
      let url;
      try {
        url = URL.createObjectURL(new Blob([buildPreviewHtml()], { type: 'text/html' }));
      } catch (err) {
        alert('Could not build preview: ' + err.message);
        return;
      }
      const win = window.open(url, '_blank');
      if (!win) alert('Please allow pop-ups for this site to use Preview.');
    });

    // ----- Categories & Tags: WordPress-style chip inputs (type + Enter) -----
    function setupTagInput(containerId, hiddenId, initial) {
      const container = document.getElementById(containerId);
      const hidden    = document.getElementById(hiddenId);
      const chipsEl   = container.querySelector('.tag-chips');
      const entry     = container.querySelector('.tag-entry');
      let terms = Array.isArray(initial) ? initial.slice() : [];

      function render() {
        chipsEl.textContent = '';
        terms.forEach((t, i) => {
          const chip = document.createElement('span');
          chip.className = 'tag-chip';
          chip.appendChild(document.createTextNode(t));         // textNode = safe, no HTML injection
          const x = document.createElement('button');
          x.type = 'button'; x.className = 'tag-x'; x.setAttribute('aria-label', 'Remove'); x.innerHTML = '&times;';
          x.addEventListener('click', () => { terms.splice(i, 1); render(); });
          chip.appendChild(x);
          chipsEl.appendChild(chip);
        });
        hidden.value = JSON.stringify(terms);
      }
      function add(val) {
        val = val.trim();
        if (val && !terms.some(t => t.toLowerCase() === val.toLowerCase())) { terms.push(val); render(); }
      }
      entry.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(entry.value); entry.value = ''; }
        else if (e.key === 'Backspace' && entry.value === '' && terms.length) { terms.pop(); render(); }
      });
      entry.addEventListener('blur', () => { if (entry.value.trim()) { add(entry.value); entry.value = ''; } });
      container.addEventListener('click', e => { if (e.target === container || e.target === chipsEl) entry.focus(); });
      render();
    }
    setupTagInput('cat-input', 'categories-field', <?= $categoriesJson ?: '[]' ?>);
    setupTagInput('tag-input', 'tags-field', <?= $tagsJson ?: '[]' ?>);

    // When editing, rebuild the saved blocks. When creating, start with one paragraph.
    const existing = <?= $blocksJson ?: '[]' ?>;
    if (existing.length) existing.forEach(b => addBlock(b.type, b));
    else addBlock('paragraph');
  </script>
</body>
</html>
