<?php
// ===== Certificate manager (hardened) =====
// The login password is stored as a hash in data/config.php (never in this file).
// After logging in you can change it with the "Change password" box.
// Needs PHP 7.4 or newer.

$dir      = __DIR__ . '/certs';
$db       = __DIR__ . '/certs.json';
$dataDir  = __DIR__ . '/data';
$cfgFile  = $dataDir . '/config.php';
$lockFile = $dataDir . '/attempts.php';
$allowed  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$max      = 5 * 1024 * 1024; // 5 MB per file
$maxItems = 50;              // most certificates allowed
$maxTries = 5;               // wrong passwords before lockout
$lockSecs = 900;             // lockout length (15 minutes)
$idleSecs = 1800;            // auto logout after 30 minutes idle

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function load_items($db) { $d = json_decode((string)@file_get_contents($db), true); return is_array($d) ? $d : []; }
function save_items($db, $d) { return @file_put_contents($db, json_encode(array_values($d), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false; }
function load_php($f) { if (!is_file($f)) return []; $v = @include $f; return is_array($v) ? $v : []; }
function save_php($f, $a) {
  $ok = @file_put_contents($f, "<?php\nreturn " . var_export($a, true) . ";\n", LOCK_EX) !== false;
  if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
  return $ok;
}

// ----- session (HttpOnly, SameSite, Secure on HTTPS) -----
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('portfolio_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => $base, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$now = time();
if (!empty($_SESSION['admin']) && isset($_SESSION['last']) && ($now - (int)$_SESSION['last']) > $idleSecs) {
  $_SESSION = [];
  session_regenerate_id(true);
}
if (!empty($_SESSION['admin'])) $_SESSION['last'] = $now;
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// ----- password hash + login attempt tracking -----
$cfg  = load_php($cfgFile);
$hash = (isset($cfg['hash']) && is_string($cfg['hash'])) ? $cfg['hash'] : '';
$att  = load_php($lockFile);
foreach ($att as $k => $v) {
  if ((int)($v['until'] ?? 0) < $now && (int)($v['first'] ?? 0) < $now - $lockSecs) unset($att[$k]);
}
$key = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$rec = $att[$key] ?? ['n' => 0, 'first' => $now, 'until' => 0];

$msg    = '';
$in     = !empty($_SESSION['admin']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('Bad request. Go back and reload the page.');
  }
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;

  } elseif ($action === 'login' && !$in) {
    if ($hash === '') {
      $msg = 'Admin is not set up (data/config.php is missing).';
    } elseif ((int)$rec['until'] > $now) {
      $msg = 'Too many failed attempts. Try again in ' . max(1, (int)ceil(((int)$rec['until'] - $now) / 60)) . ' minute(s).';
    } elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
      unset($att[$key]);
      save_php($lockFile, $att);
      session_regenerate_id(true);
      $_SESSION['admin'] = true;
      $_SESSION['last']  = $now;
      $_SESSION['csrf']  = bin2hex(random_bytes(16));
      $in = true;
    } else {
      if ((int)$rec['first'] < $now - $lockSecs) $rec = ['n' => 0, 'first' => $now, 'until' => 0];
      $rec['n'] = (int)$rec['n'] + 1;
      if ($rec['n'] >= $maxTries) { $rec['until'] = $now + $lockSecs; $rec['n'] = 0; }
      $att[$key] = $rec;
      save_php($lockFile, $att);
      usleep(600000);
      $msg = 'Wrong password.';
    }

  } elseif ($in) {
    if ($action === 'password') {
      $cur  = (string)($_POST['current'] ?? '');
      $new  = (string)($_POST['new'] ?? '');
      $new2 = (string)($_POST['new2'] ?? '');
      if (!password_verify($cur, $hash)) $msg = 'Current password is incorrect.';
      elseif (strlen($new) < 12) $msg = 'New password must be at least 12 characters.';
      elseif (!hash_equals($new, $new2)) $msg = 'The two new passwords do not match.';
      else {
        $cfg['hash'] = password_hash($new, PASSWORD_DEFAULT);
        if (save_php($cfgFile, $cfg)) { $hash = $cfg['hash']; $msg = 'Password changed.'; }
        else $msg = 'Could not save. Make the data folder writable.';
      }

    } elseif ($action === 'upload') {
      $items = load_items($db);
      $f     = $_FILES['file'] ?? null;
      $title = trim((string)($_POST['title'] ?? ''));
      if (count($items) >= $maxItems) $msg = 'Limit reached (' . $maxItems . ' files). Delete one first.';
      elseif (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $msg = 'Upload failed. Choose a file (max 5 MB).';
      elseif ($title === '') $msg = 'Please enter the certificate title.';
      elseif ($f['size'] > $max) $msg = 'File is too large (max 5 MB).';
      else {
        $ext  = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $good = isset($allowed[$ext]) && $allowed[$ext] === $mime;
        if ($good && $ext === 'pdf') $good = (@file_get_contents($f['tmp_name'], false, null, 0, 5) === '%PDF-');
        if ($good && $ext !== 'pdf') $good = (@getimagesize($f['tmp_name']) !== false);
        if (!$good) $msg = 'Only real JPG, PNG, WEBP or PDF files are allowed.';
        else {
          if (!is_dir($dir)) @mkdir($dir, 0755, true);
          $name = bin2hex(random_bytes(8)) . '.' . $ext;
          if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            @chmod($dir . '/' . $name, 0644);
            $items[] = [
              'id' => $name, 'file' => 'certs/' . $name, 'type' => $ext === 'pdf' ? 'pdf' : 'image',
              'title'  => mb_substr($title, 0, 120),
              'issuer' => mb_substr(trim((string)($_POST['issuer'] ?? '')), 0, 120),
              'year'   => mb_substr(trim((string)($_POST['year'] ?? '')), 0, 10),
            ];
            $msg = save_items($db, $items) ? 'Uploaded!' : 'File saved but certs.json is not writable.';
          } else $msg = 'Could not save the file. Check folder permissions.';
        }
      }

    } elseif ($action === 'delete') {
      $items = load_items($db);
      $id = (string)($_POST['id'] ?? '');
      foreach ($items as $i => $it) {
        if (($it['id'] ?? '') === $id) {
          @unlink($dir . '/' . basename((string)$it['id']));
          if (!empty($it['thumb'])) @unlink($dir . '/' . basename((string)$it['thumb']));
          unset($items[$i]);
        }
      }
      $msg = save_items($db, $items) ? 'Deleted.' : 'Could not update certs.json.';

    } elseif ($action === 'edit') {
      $items = load_items($db);
      $id    = (string)($_POST['id'] ?? '');
      $title = trim((string)($_POST['title'] ?? ''));
      if ($title === '') $msg = 'Title cannot be empty.';
      else {
        foreach ($items as $i => $it) {
          if (($it['id'] ?? '') === $id) {
            $items[$i]['title']  = mb_substr($title, 0, 120);
            $items[$i]['issuer'] = mb_substr(trim((string)($_POST['issuer'] ?? '')), 0, 120);
            $items[$i]['year']   = mb_substr(trim((string)($_POST['year'] ?? '')), 0, 20);
          }
        }
        $msg = save_items($db, $items) ? 'Saved.' : 'Could not update certs.json.';
      }
    }
  }
}
$items = $in ? load_items($db) : [];
$csrf  = $_SESSION['csrf'];
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Certificate Manager</title>
<link rel="stylesheet" href="admin.css">
<script src="admin.js" defer></script>
</head><body>
<h1>Certificate Manager</h1>
<?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>

<?php if (!$in): ?>
  <div class="card"><form method="post" autocomplete="off">
    <input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Admin password<input type="password" name="password" required autofocus autocomplete="current-password"></label>
    <button type="submit">Log in</button>
  </form></div>
<?php else: ?>
  <div class="card"><h2>Upload a certificate</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Title<input name="title" maxlength="120" required></label>
    <label>Issuer (optional)<input name="issuer" maxlength="120"></label>
    <label>Year (optional)<input name="year" maxlength="10"></label>
    <label>File (JPG, PNG, WEBP or PDF, max 5 MB)<input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required></label>
    <button type="submit">Upload</button>
  </form></div>

  <div class="card"><h2>Your certificates</h2>
  <?php if (!$items): ?><p>No certificates yet.</p><?php endif; ?>
  <?php foreach ($items as $it): ?>
    <div class="row">
      <span><b><?= h($it['title'] ?? '') ?></b><br><small><?= h(trim(($it['issuer'] ?? '') . ' ' . ($it['year'] ?? ''))) ?></small></span>
      <form method="post" class="inline" data-confirm="Delete this certificate?">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= h($it['id'] ?? '') ?>">
        <button class="del" type="submit">Delete</button>
      </form>
    </div>
    <details class="edit">
      <summary>Edit details</summary>
      <form method="post">
        <input type="hidden" name="action" value="edit"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= h($it['id'] ?? '') ?>">
        <label>Title<input name="title" value="<?= h($it['title'] ?? '') ?>" maxlength="120" required></label>
        <label>Issuer<input name="issuer" value="<?= h($it['issuer'] ?? '') ?>" maxlength="120"></label>
        <label>Year<input name="year" value="<?= h($it['year'] ?? '') ?>" maxlength="20"></label>
        <button type="submit">Save changes</button>
      </form>
    </details>
  <?php endforeach; ?>
  </div>

  <div class="card"><h2>Change password</h2>
  <form method="post" autocomplete="off">
    <input type="hidden" name="action" value="password"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
    <label>New password (12+ characters)<input type="password" name="new" minlength="12" required autocomplete="new-password"></label>
    <label>Repeat new password<input type="password" name="new2" minlength="12" required autocomplete="new-password"></label>
    <button type="submit">Change password</button>
  </form></div>

  <form method="post" class="inline">
    <input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <a href="index.html">← Back to portfolio</a> · <button class="linkbtn" type="submit">Log out</button>
  </form>
<?php endif; ?>
</body></html>
