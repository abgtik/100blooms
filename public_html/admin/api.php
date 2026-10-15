<?php
// Admin JSON API. Session login (Owner / Staff); every POST needs the CSRF token from ?a=me.
require __DIR__ . '/../api/_lib.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('hbadmin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

$a = (string)($_GET['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$body = $post ? (json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST) : [];
$me = null;
if (!empty($_SESSION['uid'])) $me = q('SELECT id,email,name,role FROM admins WHERE id=?', [$_SESSION['uid']])->fetch() ?: null;

function need_login(): array { global $me; if (!$me) fail('Sila log masuk.', 401); return $me; }
function need_owner(): void { $m = need_login(); if ($m['role'] !== 'owner') fail('Hanya Owner boleh buat ini.', 403); }
function csrf_ok(): void { if (!hash_equals($_SESSION['csrf'] ?? 'x', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Sesi tamat. Muat semula halaman.', 419); }
function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

const ORDER_STATUS = ['menunggu_bayaran', 'baru', 'disiapkan', 'dalam_penghantaran', 'selesai', 'batal'];

if ($post && $a !== 'login') csrf_ok();

switch ($a) {
case 'me':
  if (!$me) fail('Sila log masuk.', 401);
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  json_out(['user' => $me, 'csrf' => $_SESSION['csrf'], 'catalog' => catalog(), 'today' => date('Y-m-d'), 'site' => SITE_URL]);

case 'login':
  if (!$post) fail('POST sahaja.', 405);
  $since = date('Y-m-d H:i:s', time() - 900);
  if ((int)q('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND at>?', [ip(), $since])->fetchColumn() >= 8) fail('Terlalu banyak cubaan. Cuba lagi dalam 15 minit.', 429);
  $u = q('SELECT * FROM admins WHERE email=?', [strtolower(trim((string)($body['email'] ?? '')))])->fetch();
  if (!$u || !password_verify((string)($body['pw'] ?? ''), $u['pass_hash'])) {
    q('INSERT INTO login_attempts (ip,at) VALUES (?,?)', [ip(), date('Y-m-d H:i:s')]);
    fail('Email atau password salah.', 401);
  }
  session_regenerate_id(true);
  $_SESSION['uid'] = $u['id']; $_SESSION['csrf'] = bin2hex(random_bytes(16));
  q('DELETE FROM login_attempts WHERE ip=?', [ip()]);
  json_out(['ok' => true]);

case 'logout':
  $_SESSION = []; session_destroy(); json_out(['ok' => true]);

case 'orders':
  need_login();
  $f = (string)($_GET['f'] ?? 'all'); $qq = trim((string)($_GET['q'] ?? ''));
  $by = ($_GET['by'] ?? 'del') === 'created' ? 'created_at' : 'del_date';
  $W = []; $P = [];
  if ($f === 'pending') $W[] = "pay_status<>'paid'";
  elseif ($f === 'paid') { $W[] = "pay_status='paid'"; $W[] = "status NOT IN ('selesai','batal')"; }
  elseif ($f === 'delivered') $W[] = "status='selesai'";
  elseif ($f === 'today') { $W[] = 'del_date=?'; $P[] = date('Y-m-d'); $W[] = "pay_status='paid'"; }
  if (!empty($_GET['from'])) { $W[] = "$by>=?"; $P[] = $_GET['from'] . ($by === 'created_at' ? ' 00:00:00' : ''); }
  if (!empty($_GET['to'])) { $W[] = "$by<=?"; $P[] = $_GET['to'] . ($by === 'created_at' ? ' 23:59:59' : ''); }
  if ($qq !== '') { $W[] = '(code LIKE ? OR r_name LIKE ? OR b_name LIKE ? OR r_phone LIKE ? OR b_phone LIKE ?)'; array_push($P, ...array_fill(0, 5, "%$qq%")); }
  $where = $W ? 'WHERE ' . implode(' AND ', $W) : '';
  $order = $f === 'pending' || $by === 'created_at' ? 'created_at DESC' : "del_date ASC, CASE del_type WHEN 'A' THEN 1 WHEN 'spec' THEN 2 WHEN 'free' THEN 3 WHEN 'B' THEN 4 ELSE 5 END, created_at";
  if ($f === 'all' || $f === 'delivered') $order = 'created_at DESC';
  $rows = q("SELECT id,code,created_at,pay_status,status,bouquet,cake,del_date,del_type,del_time,r_name,r_post,zone,b_name,b_phone,total,notes FROM orders $where ORDER BY $order LIMIT 300", $P)->fetchAll();
  $t = date('Y-m-d');
  $counts = [
    'today' => (int)q("SELECT COUNT(*) FROM orders WHERE del_date=? AND pay_status='paid' AND status<>'batal'", [$t])->fetchColumn(),
    'today_left' => (int)q("SELECT COUNT(*) FROM orders WHERE del_date=? AND pay_status='paid' AND status NOT IN ('selesai','batal')", [$t])->fetchColumn(),
    'pending' => (int)q("SELECT COUNT(*) FROM orders WHERE pay_status='pending' AND created_at>=?", [date('Y-m-d H:i:s', time() - 86400)])->fetchColumn(),
    'paid_today_rm' => (float)q("SELECT COALESCE(SUM(total),0) FROM orders WHERE pay_status='paid' AND paid_at>=?", [$t . ' 00:00:00'])->fetchColumn(),
  ];
  json_out(['rows' => $rows, 'counts' => $counts]);

case 'order':
  need_login();
  $o = q('SELECT * FROM orders WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch(); if (!$o) fail('Order tidak dijumpai.', 404);
  unset($o['token']);
  foreach (['cake_flavours', 'extra_cakes', 'photos', 'price_lines'] as $k) $o[$k] = json_decode((string)$o[$k], true);
  json_out(['order' => $o]);

case 'update':
  $m = need_login();
  $o = q('SELECT * FROM orders WHERE id=?', [(int)($body['id'] ?? 0)])->fetch(); if (!$o) fail('Order tidak dijumpai.', 404);
  $set = []; $P = [];
  if (isset($body['status'])) {
    if (!in_array($body['status'], ORDER_STATUS, true)) fail('Status tidak sah.');
    if ($o['pay_status'] !== 'paid' && !in_array($body['status'], ['menunggu_bayaran', 'batal'], true)) fail('Order belum dibayar. Sahkan bayaran dulu.');
    $set[] = 'status=?'; $P[] = $body['status'];
  }
  if (array_key_exists('notes', $body)) { $set[] = 'notes=?'; $P[] = str_in($body['notes'], 2000); }
  if (isset($body['del_date'])) { // customer may change the delivery date (no refunds policy)
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$body['del_date']);
    if (!$d || $d->format('Y-m-d') !== $body['del_date']) fail('Tarikh tidak sah.');
    $set[] = 'del_date=?'; $P[] = $body['del_date'];
    if (isset($body['del_type'])) { if (!isset(DELIVERY[$body['del_type']])) fail('Jenis penghantaran tidak sah.'); $set[] = 'del_type=?'; $P[] = $body['del_type']; }
    if (isset($body['del_time'])) { $set[] = 'del_time=?'; $P[] = preg_match('/^\d{2}:\d{2}$/', (string)$body['del_time']) ? $body['del_time'] : ''; }
  }
  if (!empty($body['mark_paid'])) { // manual confirmation, e.g. paid by bank transfer
    need_owner();
    if ($o['pay_status'] !== 'paid') { $set[] = "pay_status='paid'"; $set[] = "status='baru'"; $set[] = 'paid_at=?'; $P[] = date('Y-m-d H:i:s'); $set[] = 'pay_channel=?'; $P[] = 'Manual'; $set[] = 'pay_ref=?'; $P[] = str_in($body['pay_ref'] ?? 'manual', 60); }
  }
  if (!$set) fail('Tiada perubahan.');
  $set[] = 'updated_by=?'; $P[] = $m['name']; $P[] = $o['id'];
  q('UPDATE orders SET ' . implode(',', $set) . ' WHERE id=?', $P);
  json_out(['ok' => true]);

case 'recheck':
  need_login();
  $o = q('SELECT * FROM orders WHERE id=?', [(int)($body['id'] ?? 0)])->fetch(); if (!$o) fail('Order tidak dijumpai.', 404);
  if ($o['pay_status'] === 'paid') json_out(['paid' => true]);
  if (!$o['bill_code']) fail('Order ini tiada bil ToyyibPay.');
  try { [$paid, $ref, $ch, $amt] = tp_check($o['bill_code']); } catch (Throwable $e) { fail('ToyyibPay tidak dapat dihubungi.', 502); }
  if ($paid) mark_paid($o, $ref, $ch, $amt);
  json_out(['paid' => $paid]);

case 'rphoto':
  $m = need_login();
  $o = q('SELECT * FROM orders WHERE id=?', [(int)($_POST['id'] ?? 0)])->fetch(); if (!$o) fail('Order tidak dijumpai.', 404);
  $f = $_FILES['file'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Gambar gagal dimuat naik.');
  $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('Guna gambar JPG atau PNG.');
  $dir = UPLOAD_DIR . '/' . $o['code']; if (!is_dir($dir)) mkdir($dir, 0755, true);
  $name = 'penerima-' . date('His') . '.' . str_replace('jpeg', 'jpg', image_type_to_extension($info[2], false));
  if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Simpan gambar gagal.', 500);
  q('UPDATE orders SET recipient_photo=?, updated_by=? WHERE id=?', [$name, $m['name'], $o['id']]);
  json_out(['ok' => true, 'name' => $name]);

case 'products':
  need_login();
  json_out(['rows' => q('SELECT type,item_id,name,active FROM products ORDER BY type,name')->fetchAll()]);

case 'product':
  need_login();
  q('UPDATE products SET active=? WHERE type=? AND item_id=?', [!empty($body['active']) ? 1 : 0, (string)($body['type'] ?? ''), (string)($body['item_id'] ?? '')]);
  json_out(['ok' => true]);

case 'blocked':
  need_login();
  json_out(['rows' => q('SELECT d,reason FROM blocked_dates WHERE d>=? ORDER BY d', [date('Y-m-d')])->fetchAll()]);

case 'block':
  need_login();
  $d = (string)($body['d'] ?? ''); if (!DateTimeImmutable::createFromFormat('!Y-m-d', $d)) fail('Tarikh tidak sah.');
  q('DELETE FROM blocked_dates WHERE d=?', [$d]);
  q('INSERT INTO blocked_dates (d,reason) VALUES (?,?)', [$d, str_in($body['reason'] ?? '', 150)]);
  json_out(['ok' => true]);

case 'unblock':
  need_login(); q('DELETE FROM blocked_dates WHERE d=?', [(string)($body['d'] ?? '')]); json_out(['ok' => true]);

case 'users':
  need_owner(); json_out(['rows' => q('SELECT id,email,name,role,created_at FROM admins ORDER BY id')->fetchAll()]);

case 'adduser':
  need_owner();
  $email = strtolower(trim((string)($body['email'] ?? ''))); $name = str_in($body['name'] ?? '', 100); $pw = (string)($body['pw'] ?? '');
  $role = ($body['role'] ?? '') === 'owner' ? 'owner' : 'staff';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($pw) < 8) fail('Isi nama, email yang sah dan password sekurang-kurangnya 8 aksara.');
  if (q('SELECT 1 FROM admins WHERE email=?', [$email])->fetchColumn()) fail('Email ini dah ada akaun.');
  q('INSERT INTO admins (email,pass_hash,name,role,created_at) VALUES (?,?,?,?,?)', [$email, password_hash($pw, PASSWORD_DEFAULT), $name, $role, date('Y-m-d H:i:s')]);
  json_out(['ok' => true]);

case 'deluser':
  need_owner(); $id = (int)($body['id'] ?? 0);
  if ($id === (int)$me['id']) fail('Tak boleh padam akaun sendiri.');
  q('DELETE FROM admins WHERE id=?', [$id]); json_out(['ok' => true]);

case 'passwd':
  $m = need_login(); $u = q('SELECT pass_hash FROM admins WHERE id=?', [$m['id']])->fetch();
  if (!password_verify((string)($body['old'] ?? ''), $u['pass_hash'])) fail('Password lama salah.');
  if (strlen((string)($body['new'] ?? '')) < 8) fail('Password baru mesti sekurang-kurangnya 8 aksara.');
  q('UPDATE admins SET pass_hash=? WHERE id=?', [password_hash($body['new'], PASSWORD_DEFAULT), $m['id']]); json_out(['ok' => true]);

default: fail('Tindakan tidak dikenali.', 404);
}
