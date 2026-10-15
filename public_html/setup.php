<?php
// One-time installer: creates tables and the first Owner account.
// It refuses to run once an admin exists, so it is safe to leave, but you may delete it after setup.
require __DIR__ . '/api/_lib.php';
require __DIR__ . '/api/_schema.php';

$msg = ''; $ok = false;
try {
  $drv = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
  foreach (schema_sql($drv) as $sql) { try { db()->exec($sql); } catch (PDOException $e) { if (stripos($e->getMessage(), 'exist') === false && stripos($e->getMessage(), 'Duplicate') === false) throw $e; } }
  seed_products();
  $has = (int)q('SELECT COUNT(*) FROM admins')->fetchColumn();
} catch (Throwable $e) { $has = -1; $msg = 'Tak dapat sambung ke database. Semak DB_DSN, DB_USER dan DB_PASS dalam config.php. (' . htmlspecialchars($e->getMessage()) . ')'; }

if ($has === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim($_POST['email'] ?? '')); $name = trim($_POST['name'] ?? ''); $pw = (string)($_POST['pw'] ?? '');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($pw) < 8) $msg = 'Isi nama, email yang sah dan password sekurang-kurangnya 8 aksara.';
  else { q('INSERT INTO admins (email,pass_hash,name,role,created_at) VALUES (?,?,?,?,?)', [$email, password_hash($pw, PASSWORD_DEFAULT), $name, 'owner', date('Y-m-d H:i:s')]); $ok = true; $has = 1; }
}
?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup 100 Blooms</title>
<style>body{font:15px/1.5 system-ui,sans-serif;background:#fbeef0;color:#3b2732;margin:0;padding:24px 16px}main{max-width:420px;margin:0 auto;background:#fff;border-radius:18px;padding:22px}
label{display:block;font-weight:600;font-size:13px;margin:12px 0 4px}input{width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #ecd6dc;border-radius:10px;font:inherit}
button{margin-top:16px;width:100%;padding:13px;border:0;border-radius:12px;background:#d94f74;color:#fff;font:600 16px system-ui}.e{background:#f8e0e3;color:#b6404f;padding:10px;border-radius:10px}.k{background:#e3f4ea;color:#2f8f63;padding:10px;border-radius:10px}</style></head>
<body><main><h1 style="margin-top:0">Setup 100 Blooms</h1>
<?php if ($msg): ?><p class="e"><?= $msg ?></p><?php endif; ?>
<?php if ($ok || $has > 0): ?>
  <p class="k">Database siap<?= $ok ? ' dan akaun Owner dah dicipta' : '' ?>.</p>
  <p>Log masuk di <a href="admin/">halaman admin</a>. Untuk keselamatan, padam fail <b>setup.php</b> dari File Manager.</p>
<?php elseif ($has === 0): ?>
  <p>Database dah disediakan. Cipta akaun <b>Owner</b> pertama:</p>
  <form method="post"><label>Nama</label><input name="name" required><label>Email</label><input name="email" type="email" required>
  <label>Password (min 8 aksara)</label><input name="pw" type="password" minlength="8" required><button>Cipta akaun Owner</button></form>
<?php endif; ?>
</main></body></html>
