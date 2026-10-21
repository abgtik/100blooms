<?php
// Agent portal JSON API. Agents register themselves (active at once), share https://100blooms.my/?ref=<id>,
// and earn a commission on each paid order placed through their link. Payouts are made by the owner on Fridays.
// Agents only ever see: order code, dates, status, bouquet, recipient's full name, phone with the last 5 digits hidden, area.
require __DIR__ . '/../api/_lib.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('hbagent');
session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

set_exception_handler(function (Throwable $e) {
  error_log('100blooms agent: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
  if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
  echo json_encode(['error' => 'Ralat server. Cuba lagi sebentar, atau WhatsApp 100 Blooms.'], JSON_UNESCAPED_UNICODE);
});
$a = (string)($_GET['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$body = $post ? (json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST) : [];
$ip = 'a' . substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 44);
$me = null;
if (!empty($_SESSION['aid'])) $me = q('SELECT * FROM agents WHERE id=?', [$_SESSION['aid']])->fetch() ?: null;
elseif (!in_array($a, ['login', 'register'], true) && ($rid = remember_uid('agent'))) { // "Ingat saya"
  $me = q('SELECT * FROM agents WHERE id=?', [$rid])->fetch() ?: null;
  if ($me && (int)$me['active']) { session_regenerate_id(true); $_SESSION['aid'] = (int)$me['id']; $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
  else { remember_clear('agent'); $me = null; }
}
if ($me && !(int)$me['active']) { $_SESSION = []; $me = null; }

function need_agent(): array { global $me; if (!$me) fail('Sila log masuk.', 401); return $me; }
function too_many(string $ip, int $max): void {
  if ((int)q('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND at>?', [$ip, date('Y-m-d H:i:s', time() - 900)])->fetchColumn() >= $max) fail('Terlalu banyak cubaan. Cuba lagi dalam 15 minit.', 429);
}
function attempt(string $ip): void { q('INSERT INTO login_attempts (ip,at) VALUES (?,?)', [$ip, date('Y-m-d H:i:s')]); }
function start_session(int $id): void { session_regenerate_id(true); $_SESSION['aid'] = $id; $_SESSION['csrf'] = bin2hex(random_bytes(16)); q('UPDATE agents SET last_login=? WHERE id=?', [date('Y-m-d H:i:s'), $id]); }
function qr_path(array $ag): ?string { return $ag['qr_file'] && is_file(UPLOAD_DIR . '/_agents/' . $ag['qr_file']) ? UPLOAD_DIR . '/_agents/' . $ag['qr_file'] : null; }
function link_of(int $id): string { return SITE_URL . '/?ref=' . $id; }

if ($post && !in_array($a, ['login', 'register'], true) && !hash_equals($_SESSION['csrf'] ?? 'x', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Sesi tamat. Muat semula halaman.', 419);

const AG_STATUS = ['menunggu_bayaran' => 'Menunggu bayaran', 'baru' => 'Dibayar', 'disiapkan' => 'Sedang disiapkan', 'siap' => 'Siap, tunggu runner',
  'dalam_penghantaran' => 'Dalam penghantaran', 'sampai' => 'Dah sampai', 'selesai' => 'Selesai', 'batal' => 'Batal'];

switch ($a) {
case 'info': // public: terms shown on the sign-up page
  json_out(['rate' => agent_rate_default(), 'payout_min' => payout_min(), 'pics' => showcase_files() ? array_map(fn($f) => 'api.php?a=pic&f=' . rawurlencode($f), showcase_files())
    : array_map(fn($n) => "/assets/img/bouquet/$n-c1.webp", ['glam', 'rossa', 'sunny', 'jessie', 'kiddo', 'walter'])]); // default until the owner uploads photos

case 'pic': // public showcase photo
  $f = basename((string)($_GET['f'] ?? ''));
  if (!in_array($f, showcase_files(), true)) { http_response_code(404); exit; }
  header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=86400'); readfile(UPLOAD_DIR . '/_site/' . $f); exit;

case 'me':
  $m = need_agent(); sync_commissions();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  $mon = date('Y-m');
  $month = q("SELECT COUNT(*) AS n, COALESCE(SUM(c.amount),0) AS rm FROM commissions c WHERE c.agent_id=? AND c.created_at>=?", [$m['id'], "$mon-01 00:00:00"])->fetch();
  $pending = (int)q("SELECT COUNT(*) FROM orders WHERE agent_ref=? AND pay_status<>'paid' AND status<>'batal' AND created_at>=?", [(string)$m['id'], date('Y-m-d H:i:s', time() - 7 * 86400)])->fetchColumn();
  json_out(['agent' => ['id' => (int)$m['id'], 'name' => $m['name'], 'phone' => $m['phone'], 'email' => $m['email'],
      'bank_name' => $m['bank_name'], 'bank_acc' => $m['bank_acc'], 'bank_holder' => $m['bank_holder'], 'has_qr' => qr_path($m) !== null,
      'ig' => $m['ig'], 'tiktok' => $m['tiktok'], 'fb' => $m['fb'], 'created_at' => $m['created_at']],
    'csrf' => $_SESSION['csrf'], 'link' => link_of((int)$m['id']), 'money' => agent_money((int)$m['id']),
    'month' => ['orders' => (int)$month['n'], 'rm' => (float)$month['rm']], 'pending' => $pending,
    'rate' => $m['rate'] !== null ? (float)$m['rate'] : agent_rate_default(), 'payout_min' => payout_min(), 'next_friday' => next_friday(),
    'today' => date('Y-m-d'), 'wa' => WA_MAIN,
    'runner' => ['state' => runner_state($m), 'plate' => $m['r_plate'], 'area' => $m['r_area'], 'avail' => $m['r_avail'] === date('Y-m-d'), 'susp' => $m['r_susp'],
      'face' => (bool)$m['r_face'], 'bike' => (bool)$m['r_bike'], 'rating' => runner_rating((int)$m['id'])]]);

case 'register':
  if (!$post) fail('POST sahaja.', 405);
  too_many($ip . 'r', 5);
  $name = str_in($body['name'] ?? '', 100); $phone = digits((string)($body['phone'] ?? '')); $email = strtolower(trim((string)($body['email'] ?? ''))); $pw = (string)($body['pw'] ?? '');
  if (mb_strlen($name) < 2) fail('Isi nama penuh.');
  if (strlen($phone) < 9 || strlen($phone) > 13) fail('Nombor telefon tidak sah.');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Email tidak sah.');
  if (strlen($pw) < 8) fail('Password mesti sekurang-kurangnya 8 aksara.');
  if (empty($body['terms'])) fail('Sila setuju dengan terma agent.');
  if (q('SELECT 1 FROM agents WHERE email=?', [$email])->fetch()) fail('Email ini dah didaftarkan. Log masuk atau guna email lain.');
  attempt($ip . 'r');
  $cols = 'name,phone,email,pass_hash,active,created_at'; $vals = [$name, $phone, $email, password_hash($pw, PASSWORD_DEFAULT), 1, date('Y-m-d H:i:s')];
  if (!q('SELECT 1 FROM agents LIMIT 1')->fetch()) { $cols = "id,$cols"; array_unshift($vals, 101); } // agent numbers start at 101
  q("INSERT INTO agents ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ')', $vals);
  $id = (int)db()->lastInsertId() ?: (int)q('SELECT id FROM agents WHERE email=?', [$email])->fetchColumn();
  start_session($id);
  wa_agent(['phone' => $phone], 'agent_welcome', "Selamat datang ke Program Agent 100 Blooms, $name! 🌸\n\nNo. agent awak: *$id*\nLink order awak:\n" . link_of($id)
    . "\n\nKongsi link ni di WhatsApp, status, Instagram atau TikTok. Setiap order yang dibayar melalui link awak = komisen *RM" . number_format(agent_rate_default(), 0) . "* 💰\n\nDashboard agent: " . SITE_URL . '/agent/');
  json_out(['ok' => true, 'id' => $id]);

case 'login':
  if (!$post) fail('POST sahaja.', 405);
  too_many($ip, 8);
  $id = trim((string)($body['login'] ?? ''));
  $ag = str_contains($id, '@') ? q('SELECT * FROM agents WHERE email=?', [strtolower($id)])->fetch()
    : q('SELECT * FROM agents WHERE phone=? OR phone=? ORDER BY id LIMIT 1', [digits($id), '6' . digits($id)])->fetch();
  if (!$ag || !password_verify((string)($body['pw'] ?? ''), $ag['pass_hash'])) { attempt($ip); fail('Email/telefon atau password salah.', 401); }
  if (!(int)$ag['active']) fail('Akaun agent ini tidak aktif. Hubungi 100 Blooms.', 403);
  q('DELETE FROM login_attempts WHERE ip=?', [$ip]);
  start_session((int)$ag['id']);
  if (!empty($body['remember'])) remember_set('agent', (int)$ag['id']);
  json_out(['ok' => true]);

case 'logout':
  remember_clear('agent'); $_SESSION = []; session_destroy(); json_out(['ok' => true]);

case 'orders': // month view by delivery date
  $m = need_agent();
  $mon = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
  $from = "$mon-01"; $to = date('Y-m-t', strtotime($from));
  $rows = q("SELECT o.id,o.code,o.created_at,o.del_date,o.del_type,o.del_time,o.bouquet,o.kind,o.items,o.r_name,o.r_phone,o.r_addr,o.r_post,o.b_name,o.pay_status,o.status,o.total,c.amount AS comm,c.payout_id
    FROM orders o LEFT JOIN commissions c ON c.order_id=o.id WHERE o.agent_ref=? AND o.del_date BETWEEN ? AND ? ORDER BY o.del_date, o.created_at", [(string)$m['id'], $from, $to])->fetchAll();
  $C = catalog();
  json_out(['month' => $mon, 'rows' => array_map(fn($o) => [
    'code' => $o['code'], 'created_at' => $o['created_at'], 'del_date' => $o['del_date'], 'del' => $o['del_type'] === 'spec' ? 'Masa khusus ' . $o['del_time'] : (DELIVERY[$o['del_type']]['label'] ?? ''),
    'bouquet' => order_summary($o), 'r_name' => $o['r_name'], 'r_phone' => mask_phone($o['r_phone']), 'area' => area_of($o['r_addr'], $o['r_post']),
    'buyer' => explode(' ', trim($o['b_name']))[0], 'paid' => $o['pay_status'] === 'paid', 'status' => $o['status'], 'status_label' => AG_STATUS[$o['status']] ?? $o['status'],
    'total' => (float)$o['total'], 'comm' => $o['comm'] !== null ? (float)$o['comm'] : null, 'comm_paid' => $o['payout_id'] !== null,
  ], $rows)]);

case 'comms':
  $m = need_agent(); sync_commissions();
  $c = q('SELECT c.amount,c.created_at,c.payout_id,o.code,o.del_date FROM commissions c JOIN orders o ON o.id=c.order_id WHERE c.agent_id=? ORDER BY c.created_at DESC LIMIT 300', [$m['id']])->fetchAll();
  $p = q('SELECT id,amount,ref,created_at FROM payouts WHERE agent_id=? ORDER BY created_at DESC', [$m['id']])->fetchAll();
  json_out(['comms' => $c, 'payouts' => $p, 'money' => agent_money((int)$m['id'])]);

case 'profile':
  $m = need_agent(); if (!$post) fail('POST sahaja.', 405);
  $name = str_in($body['name'] ?? '', 100); $phone = digits((string)($body['phone'] ?? ''));
  if (mb_strlen($name) < 2) fail('Isi nama penuh.');
  if (strlen($phone) < 9 || strlen($phone) > 13) fail('Nombor telefon tidak sah.');
  $url = fn($k) => str_in($body[$k] ?? '', 150) ?: null;
  q('UPDATE agents SET name=?, phone=?, bank_name=?, bank_acc=?, bank_holder=?, ig=?, tiktok=?, fb=? WHERE id=?',
    [$name, $phone, str_in($body['bank_name'] ?? '', 60) ?: null, preg_replace('/[^0-9-]/', '', (string)($body['bank_acc'] ?? '')) ?: null, str_in($body['bank_holder'] ?? '', 100) ?: null,
     $url('ig'), $url('tiktok'), $url('fb'), $m['id']]);
  json_out(['ok' => true]);

case 'qr': // DuitNow QR for payouts
  $m = need_agent(); if (!$post) fail('POST sahaja.', 405);
  $f = $_FILES['file'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Gambar gagal dimuat naik.');
  if ($f['size'] > 8 * 1024 * 1024) fail('Gambar mesti bawah 8MB.');
  $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) fail('Guna gambar JPG atau PNG.');
  $dir = UPLOAD_DIR . '/_agents'; if (!is_dir($dir)) mkdir($dir, 0755, true);
  if ($old = qr_path($m)) @unlink($old);
  $name = $m['id'] . '-' . bin2hex(random_bytes(3)) . ($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
  if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Simpan gambar gagal.', 500);
  q('UPDATE agents SET qr_file=? WHERE id=?', [$name, $m['id']]);
  json_out(['ok' => true]);

case 'myqr':
  $m = need_agent(); $p = qr_path($m); if (!$p) { http_response_code(404); exit; }
  header('Content-Type: ' . (str_ends_with($p, '.png') ? 'image/png' : 'image/jpeg')); header('Cache-Control: private, no-store'); readfile($p); exit;

case 'media': // library for posting: posters, product/customer photos, reviews, ready captions; videos live in the Telegram channel
  $m = need_agent();
  $rows = q('SELECT id,cat,title,caption,file FROM media WHERE active=1 ORDER BY cat, sort, id DESC')->fetchAll();
  json_out(['cats' => MEDIA_CATS, 'tg' => (string)setting('tg_link', ''), 'link' => link_of((int)$m['id']),
    'rows' => array_map(fn($r) => ['id' => (int)$r['id'], 'cat' => $r['cat'], 'title' => $r['title'], 'caption' => (string)$r['caption'], 'img' => (bool)$r['file']], $rows)]);

case 'mfile':
  need_agent(); $r = q('SELECT * FROM media WHERE id=? AND active=1', [(int)($_GET['id'] ?? 0)])->fetch();
  if (!$r) { http_response_code(404); exit; } media_serve($r, !empty($_GET['dl']));

// ---------- runner mode ----------
case 'r_apply': // runner application (or update of photos / plate / area): face photo, motorbike with box, plate, base area
  $m = need_agent(); if (!$post) fail('POST sahaja.', 405);
  $plate = strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', (string)($_POST['plate'] ?? ''))); $area = str_in($_POST['area'] ?? '', 100);
  if (strlen(str_replace(' ', '', $plate)) < 3) fail('Isi no. plat motor.'); if ($area === '') fail('Isi kawasan asal awak.');
  $save = function (string $k) use ($m) {
    $f = $_FILES[$k] ?? null; if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 10 * 1024 * 1024) fail('Gambar gagal dimuat naik (maksimum 10MB).');
    $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('Guna gambar JPG atau PNG.');
    $dir = runner_dir(); if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $m['id'] . "-$k-" . bin2hex(random_bytes(4)) . '.' . str_replace('jpeg', 'jpg', image_type_to_extension($info[2], false));
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Simpan gambar gagal.', 500);
    $old = $m["r_$k"] ?? ''; if ($old && is_file("$dir/$old")) @unlink("$dir/$old");
    return $name;
  };
  $face = $save('face'); $bike = $save('bike');
  if (!$face && !$m['r_face']) fail('Upload gambar muka awak.'); if (!$bike && !$m['r_bike']) fail('Upload gambar motor bersama box.');
  $state = runner_state($m); $new = in_array($state, ['none', 'rejected'], true);
  q('UPDATE agents SET r_plate=?, r_area=?, r_face=?, r_bike=?' . ($new ? ", runner='pending', r_applied=?" : '') . ' WHERE id=?',
    array_merge([$plate, $area, $face ?: $m['r_face'], $bike ?: $m['r_bike']], $new ? [date('Y-m-d H:i:s')] : [], [$m['id']]));
  if ($new) foreach (wa_cfg()['team'] as $t) if (wa_ready('team_paid')) wa_queue($t, "🛵 *Permohonan runner baru*\n{$m['name']} (agent #{$m['id']}) · " . wa_num($m['phone']) . "\n🏍️ $plate · 📍 $area\n\nLulus di admin → Agent → Runner:\n" . SITE_URL . '/admin/', 'team_paid');
  json_out(['ok' => true]);

case 'r_pic': // own runner photos
  $m = need_agent(); $k = ($_GET['k'] ?? '') === 'bike' ? 'r_bike' : 'r_face'; $p = $m[$k] ? runner_dir() . '/' . $m[$k] : '';
  if (!$p || !is_file($p)) { http_response_code(404); exit; }
  header('Content-Type: ' . (mime_content_type($p) ?: 'image/jpeg')); header('Cache-Control: private, no-store'); readfile($p); exit;

case 'r_avail':
  $m = need_agent(); if (runner_state($m) !== 'active') fail('Mod runner belum aktif.');
  q('UPDATE agents SET r_avail=? WHERE id=?', [!empty($body['on']) ? date('Y-m-d') : null, $m['id']]); json_out(['ok' => true]);

case 'r_jobs':
  $m = need_agent(); $st = runner_state($m); $rid = (int)$m['id'];
  $load = fn($sql, $p) => array_values(array_filter(array_map(function ($j) use ($rid) { $o = q('SELECT * FROM orders WHERE id=?', [$j['order_id']])->fetch(); return $o ? runner_job_view($j, $o, $rid) : null; }, q($sql, $p)->fetchAll())));
  $open = $st === 'active' ? array_values(array_filter($load("SELECT j.* FROM runner_jobs j JOIN orders o ON o.id=j.order_id WHERE j.status='open' AND j.mode='open' AND o.status<>'batal' AND o.del_date>=? ORDER BY o.del_date, j.id", [date('Y-m-d')]), fn($v) => !$v['cancelled'])) : [];
  $mine = $load("SELECT j.* FROM runner_jobs j JOIN orders o ON o.id=j.order_id WHERE j.runner_id=? AND j.status='taken' ORDER BY o.del_date, j.id", [$rid]);
  $hist = $load("SELECT * FROM runner_jobs WHERE runner_id=? AND status='done' ORDER BY done_at DESC LIMIT 60", [$rid]);
  $sum = q("SELECT COALESCE(SUM(CASE WHEN paid_at IS NULL THEN pay END),0) unpaid, COALESCE(SUM(CASE WHEN paid_at>=? THEN pay END),0) month, COUNT(*) n FROM runner_jobs WHERE runner_id=? AND status='done'", [date('Y-m-01 00:00:00'), $rid])->fetch();
  json_out(['state' => $st, 'open' => $open, 'mine' => $mine, 'history' => $hist, 'unpaid' => (float)$sum['unpaid'], 'month' => (float)$sum['month'], 'done' => (int)$sum['n'], 'rating' => runner_rating($rid), 'susp' => $m['r_susp']]);

case 'r_take':
  $m = need_agent(); if (runner_state($m) !== 'active') fail('Mod runner awak tidak aktif sekarang.');
  $j = q('SELECT * FROM runner_jobs WHERE id=?', [(int)($body['id'] ?? 0)])->fetch(); if (!$j) fail('Job tidak dijumpai.', 404);
  $n = q("UPDATE runner_jobs SET runner_id=?, status='taken', taken_at=? WHERE id=? AND status='open' AND mode='open'", [$m['id'], date('Y-m-d H:i:s'), $j['id']])->rowCount();
  if (!$n) fail('Alamak, job ni dah diambil runner lain.');
  json_out(['ok' => true]);

case 'r_release': // give a job back before pickup starts
  $m = need_agent(); $j = q('SELECT * FROM runner_jobs WHERE id=? AND runner_id=? AND status=\'taken\'', [(int)($body['id'] ?? 0), $m['id']])->fetch(); if (!$j) fail('Job tidak dijumpai.', 404);
  $o = q('SELECT * FROM orders WHERE id=?', [$j['order_id']])->fetch(); if ($o && isset(jdec($o['del_steps'])['otw_pickup'])) fail('Job dah bermula. Hubungi HQ.');
  q("UPDATE runner_jobs SET runner_id=NULL, status='open', mode='open', taken_at=NULL WHERE id=?", [$j['id']]);
  foreach (wa_cfg()['team'] as $t) if (wa_ready('team_paid')) wa_queue($t, "↩️ Runner {$m['name']} lepaskan job {$o['code']} (" . fmt_day($o['del_date']) . "). Job dibuka semula untuk runner lain.", 'team_paid');
  json_out(['ok' => true]);

case 'r_step':
  $m = need_agent(); if (runner_state($m) === 'none' || runner_state($m) === 'pending') fail('Mod runner belum aktif.');
  $j = q("SELECT * FROM runner_jobs WHERE id=? AND runner_id=? AND status='taken'", [(int)($body['id'] ?? 0), $m['id']])->fetch(); if (!$j) fail('Job tidak dijumpai.', 404);
  $o = q('SELECT * FROM orders WHERE id=?', [$j['order_id']])->fetch();
  $o = runner_step($o, (string)($body['step'] ?? ''), 'Runner ' . $m['name']);
  if (($body['step'] ?? '') === 'delivered') q("UPDATE runner_jobs SET status='done', done_at=? WHERE id=?", [date('Y-m-d H:i:s'), $j['id']]);
  json_out(['ok' => true]);

case 'r_photo': // recipient photo for the job's order
  $m = need_agent(); $j = q("SELECT * FROM runner_jobs WHERE id=? AND runner_id=? AND status='taken'", [(int)($_POST['id'] ?? 0), $m['id']])->fetch(); if (!$j) fail('Job tidak dijumpai.', 404);
  $o = q('SELECT * FROM orders WHERE id=?', [$j['order_id']])->fetch();
  $f = $_FILES['file'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Gambar gagal dimuat naik.');
  $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('Guna gambar JPG atau PNG.');
  $dir = UPLOAD_DIR . '/' . $o['code']; if (!is_dir($dir)) mkdir($dir, 0755, true);
  $name = 'penerima-' . date('His') . '.' . str_replace('jpeg', 'jpg', image_type_to_extension($info[2], false));
  if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Simpan gambar gagal.', 500);
  q('UPDATE orders SET recipient_photo=?, updated_by=? WHERE id=?', [$name, 'Runner ' . $m['name'], $o['id']]);
  json_out(['ok' => true]);

case 'r_problem': // runner reports a problem on the road: goes to the order notes and to HQ on WhatsApp
  $m = need_agent(); $j = q("SELECT * FROM runner_jobs WHERE id=? AND runner_id=?", [(int)($body['id'] ?? 0), $m['id']])->fetch(); if (!$j) fail('Job tidak dijumpai.', 404);
  $o = q('SELECT * FROM orders WHERE id=?', [$j['order_id']])->fetch(); $why = str_in($body['text'] ?? '', 300); if ($why === '') fail('Tulis masalahnya.');
  q('UPDATE orders SET notes=? WHERE id=?', [trim(($o['notes'] ?? '') . "\n[Runner {$m['name']} " . date('d/m H:i') . "] $why"), $o['id']]);
  foreach (wa_cfg()['team'] as $t) if (wa_ready('team_paid')) wa_queue($t, "⚠️ *Runner lapor masalah* · {$o['code']}\n{$m['name']} · " . wa_num($m['phone']) . "\n\n$why\n\n" . SITE_URL . '/admin/', 'team_paid');
  json_out(['ok' => true]);

case 'passwd':
  $m = need_agent();
  if (!password_verify((string)($body['old'] ?? ''), $m['pass_hash'])) fail('Password lama salah.');
  if (strlen((string)($body['new'] ?? '')) < 8) fail('Password baru mesti sekurang-kurangnya 8 aksara.');
  q('UPDATE agents SET pass_hash=? WHERE id=?', [password_hash($body['new'], PASSWORD_DEFAULT), $m['id']]);
  $had = !empty($_COOKIE[remember_cookie('agent')]); remember_clear('agent', (int)$m['id']); if ($had) remember_set('agent', (int)$m['id']); // other devices sign in again
  json_out(['ok' => true]);

default: fail('Tindakan tidak dikenali.', 404);
}
