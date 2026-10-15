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
  json_out(['user' => $me, 'csrf' => $_SESSION['csrf'], 'catalog' => catalog(), 'today' => date('Y-m-d'), 'site' => SITE_URL, 'gateway' => gateway_name()]);

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
  $rows = q("SELECT id,code,created_at,pay_status,status,bouquet,cake,del_date,del_type,del_time,r_name,r_post,zone,b_name,b_phone,total,notes,voucher FROM orders $where ORDER BY $order LIMIT 300", $P)->fetchAll();
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
  $o['track_url'] = SITE_URL . '/semak/?c=' . rawurlencode($o['code']) . '&k=' . $o['token'];
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
  if (!$o['bill_code']) fail('Order ini tiada rekod bayaran ' . gateway_name() . '.');
  try { [$paid, $ref, $ch, $amt] = pay_check($o['bill_code']); } catch (Throwable $e) { fail(gateway_name() . ' tidak dapat dihubungi.', 502); }
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

case 'dash':
  need_login();
  $t = date('Y-m-d'); $m0 = date('Y-m-01');
  $paidSum = fn(string $from, string $to) => q("SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM orders WHERE pay_status='paid' AND status<>'batal' AND paid_at>=? AND paid_at<=?", [$from, $to])->fetch();
  $today = $paidSum("$t 00:00:00", "$t 23:59:59"); $month = $paidSum("$m0 00:00:00", "$t 23:59:59");
  $pend = q("SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM orders WHERE pay_status='pending' AND status<>'batal' AND created_at>=?", [date('Y-m-d H:i:s', time() - 7 * 86400)])->fetch();
  $created = (int)q('SELECT COUNT(*) FROM orders WHERE created_at>=?', ["$t 00:00:00"])->fetchColumn();
  // sales series by paid date: 14 days, 12 weeks, 12 months
  $rows = q("SELECT paid_at, total FROM orders WHERE pay_status='paid' AND status<>'batal' AND paid_at>=?", [date('Y-m-01', strtotime('-11 months')) . ' 00:00:00'])->fetchAll();
  $day = []; for ($i = 13; $i >= 0; $i--) $day[date('Y-m-d', strtotime("-$i days"))] = 0;
  $wk = []; for ($i = 11; $i >= 0; $i--) $wk[date('o-\WW', strtotime("-$i weeks"))] = 0;
  $mo = []; for ($i = 11; $i >= 0; $i--) $mo[date('Y-m', strtotime(date('Y-m-01') . " -$i months"))] = 0;
  foreach ($rows as $r) { $ts = strtotime($r['paid_at']); $v = (float)$r['total'];
    if (isset($day[$k = date('Y-m-d', $ts)])) $day[$k] += $v; if (isset($wk[$k = date('o-\WW', $ts)])) $wk[$k] += $v; if (isset($mo[$k = date('Y-m', $ts)])) $mo[$k] += $v; }
  $todayDel = q("SELECT status, COUNT(*) n FROM orders WHERE del_date=? AND pay_status='paid' AND status<>'batal' GROUP BY status", [$t])->fetchAll();
  $topBq = q("SELECT bouquet, COUNT(*) n FROM orders WHERE pay_status='paid' AND status<>'batal' AND paid_at>=? GROUP BY bouquet ORDER BY n DESC", ["$m0 00:00:00"])->fetchAll();
  $C = catalog(); foreach ($topBq as &$b) $b['name'] = $C['bq'][$b['bouquet']] ?? $b['bouquet']; unset($b);
  json_out(['today' => $today, 'month' => $month, 'pending' => $pend, 'created_today' => $created,
    'series' => ['day' => $day, 'week' => $wk, 'month' => $mo], 'today_del' => array_column($todayDel, 'n', 'status'), 'top_bq' => $topBq]);

case 'report':
case 'reportcsv':
  need_login();
  $from = (string)($_GET['from'] ?? date('Y-m-01')); $to = (string)($_GET['to'] ?? date('Y-m-d'));
  foreach ([$from, $to] as $d) if (!DateTimeImmutable::createFromFormat('!Y-m-d', $d)) fail('Tarikh tidak sah.');
  $by = ($_GET['by'] ?? 'paid') === 'del' ? 'del_date' : 'paid_at';
  $lo = $by === 'del_date' ? $from : "$from 00:00:00"; $hi = $by === 'del_date' ? $to : "$to 23:59:59";
  $paid = q("SELECT * FROM orders WHERE pay_status='paid' AND status<>'batal' AND $by>=? AND $by<=? ORDER BY $by", [$lo, $hi])->fetchAll();
  if ($a === 'reportcsv') {
    header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=\"100blooms-order-$from-$to.csv\"");
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Kod', 'Tarikh order', 'Tarikh bayar', 'Tarikh hantar', 'Slot', 'Status', 'Momen', 'Bouquet', 'Balloon', 'Kek', 'Polaroid', 'Penerima', 'Poskod', 'Zon', 'Pembeli', 'WhatsApp', 'Voucher', 'Diskaun', 'Agent', 'Jumlah', 'Saluran bayar']);
    $C = catalog();
    foreach ($paid as $o) fputcsv($out, [$o['code'], $o['created_at'], $o['paid_at'], $o['del_date'], $o['del_type'] . ($o['del_time'] ? ' ' . $o['del_time'] : ''), $o['status'],
      $C['occ'][$o['occasion']] ?? $o['occasion'], $C['bq'][$o['bouquet']] ?? $o['bouquet'], $C['bal'][$o['balloon']] ?? $o['balloon'], $o['cake'], $o['photo_count'],
      $o['r_name'], $o['r_post'], $o['zone'], $o['b_name'], $o['b_phone'], $o['voucher'] ?? '', $o['discount'] ?? 0, $o['agent_ref'] ?? '', $o['total'], $o['pay_channel']]);
    fclose($out); exit;
  }
  $createdN = (int)q('SELECT COUNT(*) FROM orders WHERE created_at>=? AND created_at<=?', ["$from 00:00:00", "$to 23:59:59"])->fetchColumn();
  $createdPaid = (int)q("SELECT COUNT(*) FROM orders WHERE created_at>=? AND created_at<=? AND pay_status='paid'", ["$from 00:00:00", "$to 23:59:59"])->fetchColumn();
  $C = catalog(); $sales = 0; $disc = 0; $rank = ['bouquet' => [], 'balloon' => [], 'occasion' => [], 'cake' => [], 'del' => [], 'zone' => [], 'area' => [], 'source' => [], 'voucher' => []]; $series = [];
  $DL = ['free' => 'Percuma 10am–6pm', 'A' => 'Slot A', 'B' => 'Slot B', 'C' => 'Slot C', 'spec' => 'Masa khusus'];
  $inc = function (string $k, string $name, float $v) use (&$rank) { $rank[$k][$name] = ($rank[$k][$name] ?? [0, 0]); $rank[$k][$name][0]++; $rank[$k][$name][1] += $v; };
  foreach ($paid as $o) {
    $v = (float)$o['total']; $sales += $v; $disc += (float)($o['discount'] ?? 0);
    $d = substr((string)$o[$by], 0, 10); $series[$d] = ($series[$d] ?? 0) + $v;
    $inc('bouquet', $C['bq'][$o['bouquet']] ?? $o['bouquet'], $v); $inc('balloon', $C['bal'][$o['balloon']] ?? $o['balloon'], $v);
    $inc('occasion', $C['occ'][$o['occasion']] ?? $o['occasion'], $v); $inc('cake', $o['cake'], $v);
    $inc('del', $DL[$o['del_type']] ?? $o['del_type'], $v); $inc('zone', $o['zone'] === 'plus20' ? 'Zon merah (+RM20)' : 'Zon biru (percuma)', $v);
    $inc('area', substr($o['r_post'], 0, 2) . 'xxx', $v); $inc('source', $o['agent_ref'] ? 'Agent: ' . $o['agent_ref'] : 'Terus (tanpa agent)', $v);
    if (!empty($o['voucher'])) $inc('voucher', $o['voucher'], $v);
  }
  foreach ($rank as &$r) { uasort($r, fn($x, $y) => $y[0] <=> $x[0] ?: $y[1] <=> $x[1]); $r = array_map(fn($k, $x) => ['name' => (string)$k, 'n' => $x[0], 'sales' => round($x[1], 2)], array_keys($r), $r); } unset($r);
  $days = []; for ($d = strtotime($from); $d <= strtotime($to) && count($days) < 400; $d += 86400) $days[date('Y-m-d', $d)] = round($series[date('Y-m-d', $d)] ?? 0, 2);
  json_out(['from' => $from, 'to' => $to, 'by' => $by, 'sales' => round($sales, 2), 'paid' => count($paid), 'avg' => count($paid) ? round($sales / count($paid), 2) : 0,
    'created' => $createdN, 'pay_rate' => $createdN ? round($createdPaid / $createdN * 100) : 0, 'discount' => round($disc, 2), 'rank' => $rank, 'series' => $days]);

case 'calendar':
  need_login();
  $m = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
  $by = ($_GET['by'] ?? 'del') === 'created' ? 'created' : 'del';
  $col = $by === 'del' ? 'del_date' : 'created_at';
  $lo = "$m-01"; $hi = date('Y-m-t', strtotime($lo));
  $rows = q("SELECT id,code,pay_status,status,del_date,del_type,del_time,created_at,r_name,bouquet,total FROM orders
    WHERE $col>=? AND $col<=? AND status<>'batal' ORDER BY $col", [$by === 'del' ? $lo : "$lo 00:00:00", $by === 'del' ? $hi : "$hi 23:59:59"])->fetchAll();
  $days = [];
  foreach ($rows as $r) { $d = substr($by === 'del' ? $r['del_date'] : $r['created_at'], 0, 10); $days[$d][] = $r; }
  json_out(['m' => $m, 'by' => $by, 'days' => $days]);

case 'vouchers':
  need_login();
  $rows = q("SELECT v.*, (SELECT COUNT(*) FROM orders o WHERE o.voucher=v.code AND o.pay_status='paid') AS used,
    (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.voucher=v.code AND o.pay_status='paid') AS sales
    FROM vouchers v ORDER BY v.created_at DESC")->fetchAll();
  foreach ($rows as &$r) $r['label'] = voucher_label($r);
  json_out(['rows' => $rows, 'types' => VOUCHER_TYPES]);

case 'vsave':
  need_owner();
  $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($body['code'] ?? '')));
  $type = (string)($body['type'] ?? '');
  if (strlen($code) < 3 || strlen($code) > 30) fail('Kod voucher mesti 3–30 huruf atau nombor, tanpa ruang.');
  if (!isset(VOUCHER_TYPES[$type])) fail('Jenis voucher tidak sah.');
  $value = (float)($body['value'] ?? 0);
  if ($type === 'rm' && ($value <= 0 || $value >= BASE_PRICE)) fail('Nilai diskaun RM mesti antara 1 dan ' . (BASE_PRICE - 1) . '.');
  if ($type === 'pct' && ($value <= 0 || $value > 90)) fail('Peratus diskaun mesti antara 1 dan 90.');
  if ($type === 'photo') { $value = max(1, min(MAX_PHOTOS - 1, (int)$value)); }
  $gift = str_in($body['gift_name'] ?? '', 100);
  if ($type === 'gift' && $gift === '') fail('Tulis nama hadiah percuma.');
  $dates = [];
  foreach (['starts_on', 'ends_on'] as $k) { $d = (string)($body[$k] ?? ''); if ($d !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $d)) fail('Tarikh tidak sah.'); $dates[$k] = $d !== '' ? $d : null; }
  if ($dates['starts_on'] && $dates['ends_on'] && $dates['ends_on'] < $dates['starts_on']) fail('Tarikh tamat sebelum tarikh mula.');
  $max = (string)($body['max_uses'] ?? '') === '' ? null : max(1, (int)$body['max_uses']);
  $maxOff = $type === 'pct' && (string)($body['max_off'] ?? '') !== '' ? max(1, (float)$body['max_off']) : null;
  $vals = [$type, $value, $maxOff, $type === 'gift' ? $gift : null, !empty($body['agent_ok']) ? 1 : 0, $dates['starts_on'], $dates['ends_on'], $max, !empty($body['once_per_phone']) ? 1 : 0, str_in($body['note'] ?? '', 200)];
  if (q('SELECT 1 FROM vouchers WHERE code=?', [$code])->fetchColumn()) {
    if (empty($body['edit'])) fail('Kod ini dah wujud.');
    q('UPDATE vouchers SET type=?, value=?, max_off=?, gift_name=?, agent_ok=?, starts_on=?, ends_on=?, max_uses=?, once_per_phone=?, note=? WHERE code=?', [...$vals, $code]);
  } else q('INSERT INTO vouchers (type,value,max_off,gift_name,agent_ok,starts_on,ends_on,max_uses,once_per_phone,note,code,active,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)', [...$vals, $code, date('Y-m-d H:i:s')]);
  json_out(['ok' => true]);

case 'vtoggle':
  need_owner(); q('UPDATE vouchers SET active=? WHERE code=?', [!empty($body['active']) ? 1 : 0, (string)($body['code'] ?? '')]); json_out(['ok' => true]);

case 'vdel':
  need_owner(); $c = (string)($body['code'] ?? '');
  if ((int)q('SELECT COUNT(*) FROM orders WHERE voucher=?', [$c])->fetchColumn()) fail('Voucher ini dah digunakan dalam order. Matikan sahaja, jangan padam.');
  q('DELETE FROM vouchers WHERE code=?', [$c]); json_out(['ok' => true]);

case 'testmail':
  need_owner();
  if (!defined('NOTIFY_EMAIL') || !NOTIFY_EMAIL) fail('NOTIFY_EMAIL kosong dalam config.php.');
  $from = defined('MAIL_FROM') && MAIL_FROM ? MAIL_FROM : NOTIFY_EMAIL;
  $ok = @mail(NOTIFY_EMAIL, '[100 Blooms] Ujian email notifikasi', "Kalau awak terima email ini, notifikasi order berfungsi.\n\n" . SITE_URL . '/admin/',
    "From: 100 Blooms <$from>\r\nContent-Type: text/plain; charset=utf-8", '-f' . $from);
  json_out(['ok' => $ok, 'to' => NOTIFY_EMAIL, 'from' => $from]);

case 'passwd':
  $m = need_login(); $u = q('SELECT pass_hash FROM admins WHERE id=?', [$m['id']])->fetch();
  if (!password_verify((string)($body['old'] ?? ''), $u['pass_hash'])) fail('Password lama salah.');
  if (strlen((string)($body['new'] ?? '')) < 8) fail('Password baru mesti sekurang-kurangnya 8 aksara.');
  q('UPDATE admins SET pass_hash=? WHERE id=?', [password_hash($body['new'], PASSWORD_DEFAULT), $m['id']]); json_out(['ok' => true]);

default: fail('Tindakan tidak dikenali.', 404);
}
