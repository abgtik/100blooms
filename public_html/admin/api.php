<?php
// Admin JSON API. Session login (Owner / Staff); every POST needs the CSRF token from ?a=me.
require __DIR__ . '/../api/_lib.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('hbadmin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// Unexpected server errors: log them, and show the reason to signed-in staff instead of a bare "500".
set_exception_handler(function (Throwable $e) {
  error_log('100blooms admin: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
  if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
  echo json_encode(['error' => !empty($_SESSION['uid']) ? 'Ralat server: ' . mb_substr($e->getMessage(), 0, 300) : 'Ralat server. Cuba lagi.'], JSON_UNESCAPED_UNICODE);
});
$a = (string)($_GET['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$body = $post ? (json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST) : [];
$me = null;
if (!empty($_SESSION['uid'])) $me = q('SELECT id,email,name,role FROM admins WHERE id=?', [$_SESSION['uid']])->fetch() ?: null;

function need_login(): array { global $me; if (!$me) fail('Sila log masuk.', 401); return $me; }
function need_owner(): void { $m = need_login(); if ($m['role'] !== 'owner') fail('Hanya Owner boleh buat ini.', 403); }
function csrf_ok(): void { if (!hash_equals($_SESSION['csrf'] ?? 'x', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Sesi tamat. Muat semula halaman.', 419); }
function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

function refresh_status(int $id): array {
  $o = q('SELECT * FROM orders WHERE id=?', [$id])->fetch();
  $st = derive_status($o);
  if ($st !== $o['status']) { q('UPDATE orders SET status=? WHERE id=?', [$st, $id]); $o['status'] = $st; }
  return $o;
}
function manual_opts(array $d): array {
  return ['manual' => true, 'override' => !empty($d['override']), 'photo_count' => (int)($d['photo_count'] ?? 0), 'adjust' => [(float)($d['adj_amt'] ?? 0), (string)($d['adj_label'] ?? '')],
    'disc' => [($d['disc_type'] ?? '') === 'pct' ? 'pct' : 'rm', max(0, (float)($d['disc_val'] ?? 0)), (string)($d['disc_label'] ?? '')]];
}
function load_order(int $id): array { $o = q('SELECT * FROM orders WHERE id=?', [$id])->fetch(); if (!$o) fail('Order tidak dijumpai.', 404); return $o; }

if ($post && $a !== 'login') csrf_ok();

switch ($a) {
case 'me':
  if (!$me) fail('Sila log masuk.', 401);
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  json_out(['user' => $me, 'csrf' => $_SESSION['csrf'], 'catalog' => catalog(), 'status_label' => STATUS_LABEL, 'pmeta' => product_meta(), 'today' => date('Y-m-d'), 'site' => SITE_URL, 'addons' => ADDONS, 'gateway' => gateway_name()]);

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
  elseif ($f === 'issue') { $W[] = "issue IS NOT NULL AND issue<>''"; $W[] = "issue NOT LIKE '%\"resolved\"%'"; }
  elseif ($f === 'today') { $W[] = 'del_date=?'; $P[] = date('Y-m-d'); $W[] = "status<>'batal'"; } // same as the calendar: paid and unpaid
  if (!empty($_GET['from'])) { $W[] = "$by>=?"; $P[] = $_GET['from'] . ($by === 'created_at' ? ' 00:00:00' : ''); }
  if (!empty($_GET['to'])) { $W[] = "$by<=?"; $P[] = $_GET['to'] . ($by === 'created_at' ? ' 23:59:59' : ''); }
  if ($qq !== '') { $W[] = '(code LIKE ? OR r_name LIKE ? OR b_name LIKE ? OR r_phone LIKE ? OR b_phone LIKE ?)'; array_push($P, ...array_fill(0, 5, "%$qq%")); }
  $where = $W ? 'WHERE ' . implode(' AND ', $W) : '';
  $order = $f === 'pending' || $by === 'created_at' ? 'created_at DESC' : "del_date ASC, CASE del_type WHEN 'A' THEN 1 WHEN 'spec' THEN 2 WHEN 'free' THEN 3 WHEN 'B' THEN 4 ELSE 5 END, created_at";
  if ($f === 'all' || $f === 'delivered' || $f === 'issue') $order = 'created_at DESC';
  $rows = q("SELECT id,code,lang,created_at,pay_status,status,bouquet,balloon,cake,photo_count,price_lines,prep,del_steps,del_date,del_type,del_time,r_name,r_post,zone,b_name,b_phone,total,notes,voucher,wish,photos,created_by,agent_ref,issue FROM orders $where ORDER BY $order LIMIT 300", $P)->fetchAll();
  foreach ($rows as &$r) { $r['src'] = order_source($r); $ii = issue_of($r); $r['issue'] = $ii ? (empty($ii['resolved']) ? 'open' : 'done') : null; unset($r['created_by'], $r['agent_ref']); $r['progress'] = progress_text($r); $r['need'] = array_values(array_filter([$r['wish'] === WISH_NONE ? 'Wishcard belum' : null, count(jdec($r['photos'])) < (int)$r['photo_count'] ? 'Polaroid ' . count(jdec($r['photos'])) . '/' . (int)$r['photo_count'] : null])); unset($r['price_lines'], $r['prep'], $r['del_steps'], $r['wish'], $r['photos']); } unset($r);
  $t = date('Y-m-d');
  $legacy = []; $olToday = 0; $olLeft = 0;
  try { // Orderla orders for today's deliveries sit beside the new ones
    $olToday = (int)q("SELECT COUNT(*) FROM legacy_orders WHERE del_date=? AND (status IS NULL OR status<>'cancelled')", [$t])->fetchColumn();
    $olLeft = (int)q("SELECT COUNT(*) FROM legacy_orders WHERE del_date=? AND (status IS NULL OR status NOT IN ('cancelled','completed'))", [$t])->fetchColumn();
    if ($f === 'today') {
      $LW = "del_date=? AND (status IS NULL OR status<>'cancelled')"; $LP = [$t];
      if ($qq !== '') { $LW .= ' AND (invoice_no LIKE ? OR code_id LIKE ? OR name LIKE ? OR receiver LIKE ? OR phone LIKE ?)'; array_push($LP, ...array_fill(0, 5, "%$qq%")); }
      foreach (q("SELECT id, code_id, invoice_no, created_at, status, pay_status, total, name, receiver, items FROM legacy_orders WHERE $LW ORDER BY created_at", $LP)->fetchAll() as $r)
        $legacy[] = ['src' => 'orderla', 'lid' => (int)$r['id'], 'code' => $r['invoice_no'] ?: $r['code_id'], 'pay_status' => $r['pay_status'] === 'paid' ? 'paid' : 'pending',
          'ol_status' => $r['status'], 'r_name' => $r['receiver'] ?: '-', 'b_name' => $r['name'], 'items' => $r['items'], 'total' => (float)$r['total'], 'created_at' => $r['created_at']];
    }
  } catch (Throwable $e) {}
  $counts = [
    'today' => $olToday + (int)q("SELECT COUNT(*) FROM orders WHERE del_date=? AND status<>'batal'", [$t])->fetchColumn(),
    'today_left' => $olLeft + (int)q("SELECT COUNT(*) FROM orders WHERE del_date=? AND status NOT IN ('selesai','batal')", [$t])->fetchColumn(),
    'issue_open' => (int)q("SELECT COUNT(*) FROM orders WHERE issue IS NOT NULL AND issue<>'' AND issue NOT LIKE '%\"resolved\"%'")->fetchColumn(),
    'today_unpaid' => (int)q("SELECT COUNT(*) FROM orders WHERE del_date=? AND pay_status<>'paid' AND status<>'batal'", [$t])->fetchColumn(),
    'pending' => (int)q("SELECT COUNT(*) FROM orders WHERE pay_status='pending' AND created_at>=?", [date('Y-m-d H:i:s', time() - 86400)])->fetchColumn(),
    'paid_today_rm' => (float)q("SELECT COALESCE(SUM(total),0) FROM orders WHERE pay_status='paid' AND paid_at>=?", [$t . ' 00:00:00'])->fetchColumn(),
  ];
  json_out(['rows' => $rows, 'legacy' => $legacy, 'counts' => $counts]);

case 'order':
  need_login();
  $o = q('SELECT * FROM orders WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch(); if (!$o) fail('Order tidak dijumpai.', 404);
  $o['track_url'] = SITE_URL . '/semak/?c=' . rawurlencode($o['code']) . '&k=' . $o['token'];
  $o['src'] = order_source($o); $o['doc_invoice'] = doc_url($o, 'invoice');
  $o['issue'] = issue_of($o) ?: null; $o['aftercare'] = aftercare($o) ?: null; $o['issue_reasons'] = ISSUE_REASONS; $o['rcp_url'] = rcp_url($o);
  $dAt = (string)(jdec($o['del_steps'])['delivered']['at'] ?? ''); $o['after_due'] = $dAt !== '' ? date('Y-m-d H:i:s', strtotime($dAt) + AFTERCARE_MIN * 60) : null;
  $o['after_auto'] = $dAt !== '' && $dAt >= (string)setting('aftercare_since', '9999') ; $o['wa_on'] = wa_ready('buyer_done'); $o['doc_receipt'] = doc_url($o, 'receipt');
  unset($o['token']);
  $o['prep_items'] = prep_items($o); $o['del_labels'] = DEL_STEPS; $o['invoice'] = invoice_msg($o, $o['pay_status'] !== 'paid' && $o['bill_code'] ? pay_url($o['bill_code']) : null);
  $o['agent'] = $o['agent_ref'] ? (q('SELECT a.id,a.name,c.amount,c.payout_id FROM agents a LEFT JOIN commissions c ON c.order_id=? AND c.agent_id=a.id WHERE a.id=?', [$o['id'], (int)$o['agent_ref']])->fetch() ?: null) : null;
  foreach (['cake_flavours', 'extra_cakes', 'photos', 'price_lines', 'prep', 'del_steps'] as $k) $o[$k] = json_decode((string)$o[$k], true) ?: [];
  json_out(['order' => $o]);

case 'update':
  $m = need_login();
  $o = q('SELECT * FROM orders WHERE id=?', [(int)($body['id'] ?? 0)])->fetch(); if (!$o) fail('Order tidak dijumpai.', 404);
  $set = []; $P = [];
  if (isset($body['status'])) { // only cancel / restore by hand; everything else is derived from the checklist and delivery steps
    if ($body['status'] === 'batal') { $set[] = "status='batal'"; }
    elseif ($body['status'] === 'aktif') { $set[] = "status='baru'"; }
    else fail('Status tidak sah.');
  }
  if (array_key_exists('notes', $body)) { $set[] = 'notes=?'; $P[] = str_in($body['notes'], 2000); }
  if (array_key_exists('bill_to', $body)) { $b = str_in($body['bill_to'], 400); $set[] = 'bill_to=?'; $P[] = $b !== '' ? $b : null; }
  if (isset($body['del_date'])) { // customer may change the delivery date (no refunds policy)
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$body['del_date']);
    if (!$d || $d->format('Y-m-d') !== $body['del_date']) fail('Tarikh tidak sah.');
    $set[] = 'del_date=?'; $P[] = $body['del_date'];
    if (isset($body['del_type'])) { if (!isset(DELIVERY[$body['del_type']])) fail('Jenis penghantaran tidak sah.'); $set[] = 'del_type=?'; $P[] = $body['del_type']; }
    if (isset($body['del_time'])) { $set[] = 'del_time=?'; $P[] = preg_match('/^\d{2}:\d{2}$/', (string)$body['del_time']) ? $body['del_time'] : ''; }
  }
  if (!empty($body['mark_paid'])) { // manual confirmation, e.g. bank QR / transfer checked by the sales team
    if (trim((string)($body['pay_ref'] ?? '')) === '') fail('Isi rujukan bayaran (contoh no. transaksi).');
    if ($o['pay_status'] !== 'paid') { $set[] = "pay_status='paid'"; $set[] = "status='baru'"; $set[] = 'paid_at=?'; $P[] = date('Y-m-d H:i:s'); $set[] = 'pay_channel=?'; $P[] = 'Manual (' . $m['name'] . ')'; $set[] = 'pay_ref=?'; $P[] = str_in($body['pay_ref'] ?? 'manual', 60); }
  }
  if (!$set) fail('Tiada perubahan.');
  $set[] = 'updated_by=?'; $P[] = $m['name']; $P[] = $o['id'];
  q('UPDATE orders SET ' . implode(',', $set) . ' WHERE id=?', $P);
  if (($body['status'] ?? '') !== 'batal') refresh_status($o['id']);
  if (!empty($body['mark_paid']) && $o['pay_status'] !== 'paid') after_paid((int)$o['id']); // email, agent commission, WhatsApp
  elseif (isset($body['status'])) sync_commissions(); // commission dropped if cancelled before payout
  json_out(['ok' => true]);

case 'delorder': // Owner: remove an order for good (test orders, duplicates). Commission already paid out blocks it.
  need_owner();
  $o = q('SELECT * FROM orders WHERE id=?', [(int)($body['id'] ?? 0)])->fetch(); if (!$o) fail('Order tidak dijumpai.', 404);
  if (q('SELECT 1 FROM commissions WHERE order_id=? AND payout_id IS NOT NULL', [$o['id']])->fetch()) fail('Komisen order ni dah dibayar kepada agent. Batalkan sahaja, jangan padam.');
  q('DELETE FROM commissions WHERE order_id=?', [$o['id']]);
  q('DELETE FROM orders WHERE id=?', [$o['id']]);
  $dir = UPLOAD_DIR . '/' . basename((string)$o['code']);
  if ($o['code'] !== '' && is_dir($dir)) { foreach (glob($dir . '/*') ?: [] as $fl) @unlink($fl); @rmdir($dir); }
  json_out(['ok' => true]);

case 'oldel': // Owner: hide an Orderla order for good (auto-sync won't bring it back)
  need_owner();
  $r = q('SELECT id, code_id FROM legacy_orders WHERE id=?', [(int)($body['id'] ?? 0)])->fetch(); if (!$r) fail('Order tidak dijumpai.', 404);
  set_setting('olx_' . substr(md5((string)$r['code_id']), 0, 20), '1'); q('DELETE FROM legacy_orders WHERE id=?', [$r['id']]);
  json_out(['ok' => true]);

case 'items': // change bouquet / balloon / cake / occasion on an existing order (before the runner collects it)
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0)); $C = catalog();
  if ($o['status'] === 'batal') fail('Order dah dibatalkan.');
  if (isset(jdec($o['del_steps'])['otw_pickup'])) fail('Runner dah ambil barang. Item tak boleh ditukar lagi.');
  $bq = (string)($body['bouquet'] ?? $o['bouquet']); $bal = (string)($body['balloon'] ?? $o['balloon']); $cake = (string)($body['cake'] ?? $o['cake']);
  $occ = (string)($body['occasion'] ?? $o['occasion']);
  if (!isset($C['bq'][$bq])) fail('Bouquet tidak sah.'); if (!isset($C['bal'][$bal])) fail('Balloon tidak sah.');
  if (!in_array($cake, array_merge($C['cake_free'], $C['cake_up']), true)) fail('Kek tidak sah.');
  if ($occ !== '' && !isset($C['occ'][$occ])) fail('Occasion tidak sah.');
  $fl = $cake === OTHER_CAKE ? array_slice(array_values(array_filter(array_map(fn($x) => str_in($x, 40), (array)($body['flavours'] ?? [])))), 0, 5) : [];
  if ($cake === OTHER_CAKE && !$fl) fail('Isi perisa pilihan untuk "' . OTHER_CAKE . '".');
  $note = str_in($body['bq_note'] ?? ($o['bq_note'] ?? ''), 100);
  $chg = []; $p = jdec($o['prep']);
  if ($bq !== $o['bouquet']) { $chg[] = 'Bouquet ' . ($C['bq'][$o['bouquet']] ?? $o['bouquet']) . ' → ' . $C['bq'][$bq]; unset($p['bouquet']); }
  if ($bal !== $o['balloon']) { $chg[] = 'Balloon ' . ($C['bal'][$o['balloon']] ?? $o['balloon']) . ' → ' . $C['bal'][$bal]; unset($p['balloon']); }
  $oldFl = jdec($o['cake_flavours']);
  if ($cake !== $o['cake'] || $fl != $oldFl) { $chg[] = 'Kek ' . $o['cake'] . ' → ' . $cake . ($fl ? ' (' . implode(' / ', $fl) . ')' : ''); unset($p['cake']); }
  if ($note !== (string)($o['bq_note'] ?? '')) $chg[] = 'Nota bouquet: ' . ($note !== '' ? $note : '(kosong)');
  if ($occ !== (string)$o['occasion']) $chg[] = 'Occasion → ' . ($C['occ'][$occ] ?? '-');
  if (!$chg) fail('Tiada perubahan.');
  // price: only the cake upgrade can change it
  $wasUp = in_array($o['cake'], $C['cake_up'], true); $isUp = in_array($cake, $C['cake_up'], true); $diff = ($isUp ? CAKE_UPGRADE : 0) - ($wasUp ? CAKE_UPGRADE : 0);
  $L = jdec($o['price_lines']); $total = (float)$o['total']; $warn = null;
  if (isset($L[0][0]) && str_starts_with((string)$L[0][0], 'Combo Jimat')) $L[0][0] = preg_replace('/\(bouquet [^)]*\)/u', '(bouquet ' . $C['bq'][$bq] . ')', (string)$L[0][0]);
  if ($diff != 0 && $o['pay_status'] !== 'paid' && empty($o['bill_code'])) { // invoice not paid yet: reprice
    $L = array_values(array_filter($L, fn($l) => !str_starts_with((string)$l[0], 'Upgrade kek')));
    if ($isUp) array_splice($L, 1, 0, [['Upgrade kek: ' . $cake, CAKE_UPGRADE]]);
    $total = round($total + $diff, 2); $chg[] = 'Jumlah dikemas kini ke RM' . number_format($total, 2);
  } elseif ($diff != 0) $warn = ($diff > 0 ? 'Beza harga RM' . $diff . ' perlu dikutip daripada pembeli' : 'Lebihan bayaran RM' . (-$diff) . ' untuk pembeli') . ' (jumlah order tak berubah sebab dah dibayar / link bayaran dah dihantar).';
  $log = '[' . date('j/n H:i') . ' ' . $m['name'] . '] Tukar item: ' . implode('; ', $chg) . ($warn ? '. ' . $warn : '');
  q('UPDATE orders SET bouquet=?, balloon=?, cake=?, cake_flavours=?, bq_note=?, occasion=?, prep=?, price_lines=?, total=?, notes=?, updated_by=? WHERE id=?',
    [$bq, $bal, $cake, json_encode($fl, JSON_UNESCAPED_UNICODE), $note !== '' ? $note : null, $occ !== '' ? $occ : $o['occasion'], json_encode($p ?: new stdClass, JSON_UNESCAPED_UNICODE),
     json_encode($L, JSON_UNESCAPED_UNICODE), $total, trim(($o['notes'] ? $o['notes'] . "\n" : '') . $log), $m['name'], $o['id']]);
  refresh_status($o['id']);
  json_out(['ok' => true, 'warn' => $warn]);

case 'issue': // flag / update / clear a delivery problem (stops the automatic thank-you messages)
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0)); $i = issue_of($o);
  $reasons = array_values(array_intersect(array_keys(ISSUE_REASONS), array_map('strval', (array)($body['reasons'] ?? []))));
  if (!$reasons && empty($i['voucher'])) { q('UPDATE orders SET issue=NULL, updated_by=? WHERE id=?', [$m['name'], $o['id']]); json_out(['ok' => true, 'cleared' => true]); }
  $i = array_merge($i, ['reasons' => $reasons, 'note' => str_in($body['note'] ?? ($i['note'] ?? ''), 500), 'action' => str_in($body['action'] ?? ($i['action'] ?? ''), 200)]);
  $i['by'] = $i['by'] ?? $m['name']; $i['at'] = $i['at'] ?? date('Y-m-d H:i:s');
  q('UPDATE orders SET issue=?, updated_by=? WHERE id=?', [json_encode($i, JSON_UNESCAPED_UNICODE), $m['name'], $o['id']]);
  json_out(['ok' => true]);

case 'issue_voucher': // RM30 apology voucher, one per problem order
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0)); $i = issue_of($o);
  if (!$i) fail('Tanda masalah dulu.');
  if (empty($i['voucher'])) { [$c, $e] = make_voucher('MAAF', 'rm', 30, 'Maaf order ' . $o['code'] . ' oleh ' . $m['name']); $i['voucher'] = $c; $i['voucher_ends'] = $e; $i['voucher_by'] = $m['name'];
    q('UPDATE orders SET issue=? WHERE id=?', [json_encode($i, JSON_UNESCAPED_UNICODE), $o['id']]); }
  json_out(['ok' => true, 'code' => $i['voucher'], 'ends' => $i['voucher_ends']]);

case 'issue_done':
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0)); $i = issue_of($o); if (!$i) fail('Tiada masalah ditanda.');
  $i['resolved'] = empty($body['undo']) ? ['by' => $m['name'], 'at' => date('Y-m-d H:i:s')] : null; if (!$i['resolved']) unset($i['resolved']);
  q('UPDATE orders SET issue=? WHERE id=?', [json_encode($i, JSON_UNESCAPED_UNICODE), $o['id']]);
  json_out(['ok' => true]);

case 'aftermsg': // message text for the team to send by hand (WhatsApp opens with it); also records that it went out
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0)); $who = (string)($body['who'] ?? '');
  if ($o['pay_status'] !== 'paid') fail('Order belum dibayar.');
  $a = aftercare($o); $now = date('Y-m-d H:i:s');
  if ($who === 'issue') { $i = issue_of($o); if (!$i) fail('Tiada masalah ditanda.'); $i['msg_at'] = $now; $i['msg_by'] = $m['name'];
    q('UPDATE orders SET issue=? WHERE id=?', [json_encode($i, JSON_UNESCAPED_UNICODE), $o['id']]); json_out(['phone' => wa_num($o['b_phone']), 'text' => issue_msg($o)]); }
  if ($who === 'rcp') { rcp_voucher($o); $o = load_order((int)$o['id']); $a = aftercare($o); $a['rcp_manual_at'] = $now; aftercare_save((int)$o['id'], $a);
    $rp = first_phone((string)$o['r_phone']); if ($rp === '') fail('Nombor penerima tidak sah.'); json_out(['phone' => wa_num($rp), 'text' => aftercare_msg($o, 'rcp')]); }
  if ($who === 'buyer') { $a['offer'] = true; $a['buyer_manual_at'] = $now; aftercare_save((int)$o['id'], $a); json_out(['phone' => wa_num($o['b_phone']), 'text' => aftercare_msg($o, 'buyer')]); }
  fail('Pilihan tidak sah.');

case 'prep': // tick / untick a production checklist item
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0));
  if ($o['pay_status'] !== 'paid' || $o['status'] === 'batal') fail('Order belum dibayar atau dibatalkan.');
  $item = (string)($body['item'] ?? ''); if (!isset(prep_items($o)[$item])) fail('Item tidak sah.');
  $d = jdec($o['del_steps']); if (isset($d['otw_pickup'])) fail('Runner dah ambil barang. Undo langkah penghantaran dulu.');
  $p = jdec($o['prep']);
  if (!empty($body['on'])) $p[$item] = ['by' => $m['name'], 'at' => date('Y-m-d H:i:s')]; else unset($p[$item]);
  q('UPDATE orders SET prep=?, updated_by=? WHERE id=?', [json_encode($p ?: new stdClass, JSON_UNESCAPED_UNICODE), $m['name'], $o['id']]);
  json_out(['ok' => true, 'status' => refresh_status($o['id'])['status']]);

case 'dstep': // advance delivery by exactly one step
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0));
  if ($o['pay_status'] !== 'paid' || $o['status'] === 'batal') fail('Order belum dibayar atau dibatalkan.');
  $step = (string)($body['step'] ?? ''); if (!isset(DEL_STEPS[$step])) fail('Langkah tidak sah.');
  $d = jdec($o['del_steps']); $keys = array_keys(DEL_STEPS); $next = $keys[count(array_intersect_key(DEL_STEPS, $d))] ?? null;
  if ($step !== $next) fail('Ikut urutan: langkah seterusnya ialah "' . (DEL_STEPS[$next] ?? '-') . '".');
  if ($step === 'booked' && derive_status($o) !== 'siap' && empty($body['force'])) fail('Penyediaan belum lengkap. Tick semua item dulu.');
  if ($step === 'booked' && !empty($body['force'])) need_owner();
  if ($step === 'delivered' && !$o['recipient_photo'] && !in_array('no_photo', issue_of($o)['reasons'] ?? [], true)) fail('Upload gambar penerima dulu (atau tanda masalah "Tiada gambar penerima").');
  $d[$step] = ['by' => $m['name'], 'at' => date('Y-m-d H:i:s')];
  $set = 'del_steps=?'; $P = [json_encode($d, JSON_UNESCAPED_UNICODE)];
  if ($step === 'booked' && isset($body['url'])) { $u = trim((string)$body['url']); if ($u !== '' && !preg_match('#^https://#i', $u)) fail('Link mesti bermula dengan https://'); $set .= ', lalamove_url=?'; $P[] = mb_substr($u, 0, 300) ?: null; }
  q("UPDATE orders SET $set, updated_by=? WHERE id=?", [...$P, $m['name'], $o['id']]);
  $fresh = refresh_status($o['id']);
  if ($step === 'picked') wa_order($fresh, 'otw'); elseif ($step === 'delivered') wa_order($fresh, 'delivered');
  json_out(['ok' => true, 'status' => $fresh['status']]);

case 'dundo': // undo the last delivery step
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0));
  $d = jdec($o['del_steps']); if (!$d) fail('Tiada langkah untuk di-undo.');
  $last = array_key_last(array_intersect_key(DEL_STEPS, $d)); unset($d[$last]);
  q('UPDATE orders SET del_steps=?, updated_by=? WHERE id=?', [$d ? json_encode($d, JSON_UNESCAPED_UNICODE) : null, $m['name'], $o['id']]);
  json_out(['ok' => true, 'status' => refresh_status($o['id'])['status']]);

case 'lalamove':
  need_login(); $o = load_order((int)($body['id'] ?? 0)); $u = trim((string)($body['url'] ?? ''));
  if ($u !== '' && !preg_match('#^https://#i', $u)) fail('Link mesti bermula dengan https://');
  q('UPDATE orders SET lalamove_url=? WHERE id=?', [$u !== '' ? mb_substr($u, 0, 300) : null, $o['id']]); json_out(['ok' => true]);

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

case 'wish': // staff write / correct the wishcard (no edit limit)
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0));
  $wish = str_in($body['wish'] ?? '', 320); if ($wish === '') $wish = WISH_NONE;
  q('UPDATE orders SET wish=?, sender=?, updated_by=? WHERE id=?', [$wish, str_in($body['sender'] ?? '', 60) ?: null, $m['name'], $o['id']]);
  json_out(['ok' => true]);

case 'padd': // staff add polaroids received on WhatsApp
  $m = need_login(); $o = load_order((int)($_POST['id'] ?? 0));
  $files = collect_photos(); if (!$files) fail('Pilih gambar dulu.');
  json_out(['ok' => true, 'photos' => add_photos($o, $files)]);

case 'pdel':
  $m = need_login(); $o = load_order((int)($body['id'] ?? 0));
  json_out(['ok' => true, 'photos' => remove_photo($o, (string)($body['f'] ?? ''))]);

case 'agents': // agent list with sales, commission balance and payout info
  $m = need_login(); sync_commissions(); $own = $m['role'] === 'owner';
  $rows = q("SELECT a.*, (SELECT COUNT(*) FROM orders o WHERE (o.agent_ref + 0) = a.id AND o.pay_status='paid' AND o.status<>'batal') AS n_paid,
      (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE (o.agent_ref + 0) = a.id AND o.pay_status='paid' AND o.status<>'batal') AS sales,
      (SELECT COUNT(*) FROM orders o WHERE (o.agent_ref + 0) = a.id AND o.pay_status<>'paid' AND o.status<>'batal') AS n_pending
    FROM agents a ORDER BY a.id")->fetchAll();
  $out = [];
  foreach ($rows as $r) {
    $x = ['id' => (int)$r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'], 'active' => (int)$r['active'], 'rate' => $r['rate'] !== null ? (float)$r['rate'] : null,
      'ig' => $r['ig'], 'tiktok' => $r['tiktok'], 'fb' => $r['fb'], 'created_at' => $r['created_at'], 'last_login' => $r['last_login'],
      'n_paid' => (int)$r['n_paid'], 'n_pending' => (int)$r['n_pending'], 'sales' => (float)$r['sales']] + agent_money((int)$r['id']);
    if ($own) $x += ['bank_name' => $r['bank_name'], 'bank_acc' => $r['bank_acc'], 'bank_holder' => $r['bank_holder'], 'has_qr' => (bool)$r['qr_file']];
    $out[] = $x;
  }
  $pays = q('SELECT p.*, a.name FROM payouts p JOIN agents a ON a.id=p.agent_id ORDER BY p.created_at DESC LIMIT 30')->fetchAll();
  json_out(['rows' => $out, 'payouts' => $pays, 'rate' => agent_rate_default(), 'payout_min' => payout_min(), 'next_friday' => next_friday(), 'portal' => SITE_URL . '/agent/']);

case 'agent_set': // owner: activate / deactivate, own commission rate (blank = default)
  need_owner(); $id = (int)($body['id'] ?? 0);
  if (!q('SELECT 1 FROM agents WHERE id=?', [$id])->fetch()) fail('Agent tidak dijumpai.', 404);
  if (isset($body['active'])) q('UPDATE agents SET active=? WHERE id=?', [!empty($body['active']) ? 1 : 0, $id]);
  if (array_key_exists('rate', $body)) q('UPDATE agents SET rate=? WHERE id=?', [(string)$body['rate'] === '' ? null : max(0, min(500, (float)$body['rate'])), $id]);
  json_out(['ok' => true]);

case 'agent_pw': // owner: reset a forgotten password; the temporary password is shown once to give the agent on WhatsApp
  need_owner(); $id = (int)($body['id'] ?? 0);
  $tmp = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
  if (!q('UPDATE agents SET pass_hash=? WHERE id=?', [password_hash($tmp, PASSWORD_DEFAULT), $id])->rowCount()) fail('Agent tidak dijumpai.', 404);
  json_out(['ok' => true, 'pw' => $tmp]);

case 'agent_qr':
  need_owner(); $r = q('SELECT qr_file FROM agents WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch();
  $p = $r && $r['qr_file'] ? UPLOAD_DIR . '/_agents/' . basename($r['qr_file']) : '';
  if (!$p || !is_file($p)) { http_response_code(404); exit; }
  header('Content-Type: ' . (str_ends_with($p, '.png') ? 'image/png' : 'image/jpeg')); header('Cache-Control: private, no-store'); readfile($p); exit;

case 'payout': // owner: record that the agent's whole unpaid balance has been transferred
  $m = need_login(); need_owner(); $id = (int)($body['id'] ?? 0);
  $ref = str_in($body['ref'] ?? '', 100); if ($ref === '') fail('Isi rujukan pindahan (contoh no. transaksi).');
  sync_commissions();
  $pdo = db(); $pdo->beginTransaction();
  try {
    $cs = q('SELECT id, amount FROM commissions WHERE agent_id=? AND payout_id IS NULL', [$id])->fetchAll();
    $due = round(array_sum(array_column($cs, 'amount')), 2);
    if ($due <= 0) { $pdo->rollBack(); fail('Tiada baki untuk dibayar.'); }
    q('INSERT INTO payouts (agent_id,amount,ref,paid_by,created_at) VALUES (?,?,?,?,?)', [$id, $due, $ref, $m['name'], date('Y-m-d H:i:s')]);
    $pid = (int)$pdo->lastInsertId();
    q('UPDATE commissions SET payout_id=? WHERE payout_id IS NULL AND id IN (' . implode(',', array_map('intval', array_column($cs, 'id'))) . ')', [$pid]);
    $pdo->commit();
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  if ($ag = q('SELECT * FROM agents WHERE id=?', [$id])->fetch())
    wa_agent($ag, 'agent_payout', "Hai {$ag['name']} 💸\n\nKomisen *RM" . number_format($due, 2) . "* dah dibayar ke akaun awak.\nRujukan: $ref\n\nTerima kasih kerana bersama 100 Blooms! Teruskan kongsi link awak 🌸\n" . SITE_URL . '/agent/');
  json_out(['ok' => true, 'amount' => $due]);

case 'showcase': // owner: recipient photos for the agent sign-up page
  if (!$post) { need_login(); json_out(['files' => showcase_files()]); }
  need_owner();
  if (isset($body['del'])) { $f = basename((string)$body['del']); if (in_array($f, showcase_files(), true)) @unlink(UPLOAD_DIR . '/_site/' . $f); json_out(['ok' => true]); }
  $files = collect_photos(); if (!$files) fail('Pilih gambar dulu.');
  if (count(showcase_files()) + count($files) > 8) fail('Maksimum 8 gambar. Padam yang lama dulu.');
  $dir = UPLOAD_DIR . '/_site'; if (!is_dir($dir)) mkdir($dir, 0755, true);
  foreach ($files as [$tmp]) save_jpeg($tmp, $dir . '/showcase-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)) . '.jpg');
  json_out(['ok' => true]);

case 'showpic':
  need_login(); $f = basename((string)($_GET['f'] ?? ''));
  if (!in_array($f, showcase_files(), true)) { http_response_code(404); exit; }
  header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=600'); readfile(UPLOAD_DIR . '/_site/' . $f); exit;

case 'waset': // owner: WhatsApp (Murpati) settings; the API key is never sent back in full
  need_owner();
  if ($post) {
    if (isset($body['key']) && trim((string)$body['key']) !== '') set_setting('wa_key', trim((string)$body['key']));
    if (!empty($body['clear_key'])) { set_setting('wa_key', null); set_setting('wa_session', null); }
    if (isset($body['session'])) set_setting('wa_session', preg_replace('/[^A-Za-z0-9_:.-]/', '', (string)$body['session']) ?: null);
    if (isset($body['team'])) set_setting('wa_team', implode(',', array_filter(array_map('wa_num', explode(',', (string)$body['team'])))));
    if (isset($body['on']) && is_array($body['on'])) set_setting('wa_on', json_encode(array_map(fn($k) => !empty($body['on'][$k]), array_combine(array_keys(WA_TYPES), array_keys(WA_TYPES)))));
    json_out(['ok' => true]);
  }
  $k = (string)setting('wa_key', ''); $c = wa_cfg();
  json_out(['has_key' => $k !== '', 'key_hint' => $k !== '' ? substr($k, 0, 3) . '…' . substr($k, -4) : '', 'session' => $c['session'], 'team' => implode(', ', $c['team']),
    'on' => $c['on'], 'types' => WA_TYPES, 'last' => json_decode((string)setting('wa_last', ''), true)]);

case 'wasess': // owner: numbers connected in Murpati, to pick the sending number
  need_owner(); $key = (string)setting('wa_key', ''); if ($key === '') fail('Simpan API key dulu.');
  [$code, $j, $raw] = wa_http('GET', '/v1/sessions', null, $key);
  if ($code >= 400) fail('Murpati: ' . ($j['error'] ?? "HTTP $code"));
  $list = $j['sessions'] ?? $j['data'] ?? (array_is_list($j) ? $j : []);
  json_out(['rows' => array_values(array_map(fn($x) => ['id' => $x['session_id'] ?? $x['id'] ?? '', 'phone' => $x['phone_number'] ?? $x['phone'] ?? '', 'pipeline' => $x['pipeline'] ?? $x['type'] ?? '',
    'status' => $x['status'] ?? '', 'name' => $x['name'] ?? $x['label'] ?? ''], array_filter((array)$list, 'is_array')))]);

case 'watest': // owner: send a test message now
  need_owner(); $to = wa_num((string)($body['to'] ?? '')); if (strlen($to) < 10) fail('Isi nombor WhatsApp yang sah.');
  try { wa_send($to, "✅ Ujian notifikasi 100 Blooms.\nKalau awak terima mesej ni, notifikasi WhatsApp dah berfungsi."); }
  catch (Throwable $e) { fail($e->getMessage(), 502); }
  json_out(['ok' => true]);

case 'media': // owner manages the agents' media library
  need_login();
  json_out(['cats' => MEDIA_CATS, 'tg' => (string)setting('tg_link', ''), 'rows' => q('SELECT * FROM media ORDER BY cat, sort, id DESC')->fetchAll()]);

case 'mfile':
  need_login(); $r = q('SELECT * FROM media WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch(); if (!$r) { http_response_code(404); exit; } media_serve($r);

case 'madd': // multipart: cat, title, caption, file (optional for caption-only)
  need_owner();
  $cat = (string)($_POST['cat'] ?? ''); if (!isset(MEDIA_CATS[$cat])) fail('Pilih kategori.');
  $title = str_in($_POST['title'] ?? '', 100); $cap = str_in($_POST['caption'] ?? '', 2000);
  $name = null; $f = $_FILES['file'] ?? null;
  if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Gambar gagal dimuat naik (saiz terlalu besar?).');
    $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('Guna gambar JPG, PNG atau WEBP.');
    $dir = UPLOAD_DIR . '/_media'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'm-' . date('ymd') . '-' . bin2hex(random_bytes(4)) . '.jpg';
    save_jpeg($f['tmp_name'], "$dir/$name", 1600, 88);
  }
  if (!$name && $cap === '') fail('Isi caption atau pilih gambar.');
  if ($title === '') $title = MEDIA_CATS[$cat];
  q('INSERT INTO media (cat,title,caption,file,active,sort,created_at) VALUES (?,?,?,?,1,0,?)', [$cat, $title, $cap ?: null, $name, date('Y-m-d H:i:s')]);
  json_out(['ok' => true]);

case 'medit':
  need_owner(); $id = (int)($body['id'] ?? 0); $r = q('SELECT * FROM media WHERE id=?', [$id])->fetch(); if (!$r) fail('Bahan tidak dijumpai.', 404);
  if (!empty($body['delete'])) { if ($r['file'] && !str_starts_with($r['file'], 'asset:')) @unlink(UPLOAD_DIR . '/_media/' . basename($r['file'])); q('DELETE FROM media WHERE id=?', [$id]); json_out(['ok' => true]); }
  if (isset($body['active'])) q('UPDATE media SET active=? WHERE id=?', [!empty($body['active']) ? 1 : 0, $id]);
  if (isset($body['caption'])) q('UPDATE media SET caption=? WHERE id=?', [str_in($body['caption'], 2000) ?: null, $id]);
  if (isset($body['title']) && trim((string)$body['title']) !== '') q('UPDATE media SET title=? WHERE id=?', [str_in($body['title'], 100), $id]);
  if (isset($body['cat']) && isset(MEDIA_CATS[$body['cat']])) q('UPDATE media SET cat=? WHERE id=?', [$body['cat'], $id]);
  json_out(['ok' => true]);

case 'tgset':
  need_owner(); $u = trim((string)($body['tg'] ?? ''));
  if ($u !== '' && !preg_match('#^https://t\.me/[A-Za-z0-9_+/-]+$#', $u)) fail('Link mesti bermula dengan https://t.me/');
  set_setting('tg_link', $u ?: null); json_out(['ok' => true]);

case 'olset': // owner: Orderla API credentials (orderla.my/admin/manage-api); the token is never sent back
  need_owner();
  if ($post) {
    if (!empty($body['clear'])) { set_setting('ol_token', null); json_out(['ok' => true]); }
    $e = strtolower(trim((string)($body['email'] ?? ''))); if (!filter_var($e, FILTER_VALIDATE_EMAIL)) fail('Email tidak sah.');
    set_setting('ol_email', $e); if (trim((string)($body['token'] ?? '')) !== '') set_setting('ol_token', trim((string)$body['token']));
    json_out(['ok' => true]);
  }
  $agg = q('SELECT COUNT(*) n, MIN(created_at) a, MAX(created_at) b, COALESCE(SUM(CASE WHEN ' . OL_PAID . ' THEN total END),0) s FROM legacy_orders')->fetch();
  json_out(['email' => (string)setting('ol_email', ''), 'has_token' => (string)setting('ol_token', '') !== '', 'count' => (int)$agg['n'], 'first' => $agg['a'], 'last' => $agg['b'], 'sales' => (float)$agg['s'],
    'synced' => setting('ol_synced')]);

case 'olreparse': // re-read delivery date / receiver from orders already imported (after the detection improves)
  need_owner(); $n = 0;
  foreach (q('SELECT id, extra, raw FROM legacy_orders WHERE del_date IS NULL OR receiver IS NULL')->fetchAll() as $r) {
    $o = json_decode((string)$r['raw'], true) ?: []; $x = ol_extra($o); [$d, $rc] = ol_guess($x, (string)($o['notes'] ?? '') . "\n" . (string)($o['seller_notes'] ?? ''));
    if ($d || $rc) { q('UPDATE legacy_orders SET del_date=COALESCE(del_date,?), receiver=COALESCE(receiver,?) WHERE id=?', [$d, $rc, $r['id']]); $n++; }
  }
  json_out(['ok' => true, 'fixed' => $n]);

case 'olenrich': // read the single-order endpoint for recent orders still missing a delivery date (batch of 20)
  need_owner(); @set_time_limit(120);
  try { json_out(['ok' => true] + ol_enrich(20)); } catch (Throwable $e) { fail($e->getMessage(), 502); }

case 'oltest': // fetch the last 30 days and report what fields Orderla sends (names only, no customer data)
  need_owner();
  try { $j = orderla_get('/orders', ['start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d')]); } catch (Throwable $e) { fail($e->getMessage(), 502); }
  $orders = (array)($j['orders'] ?? []); $keys = []; $extra = []; $guess = ['date' => 0, 'receiver' => 0];
  foreach ($orders as $o) { foreach (array_keys((array)$o) as $k) $keys[$k] = 1; $x = ol_extra((array)$o); foreach (array_keys($x) as $k) $extra[$k] = ($extra[$k] ?? 0) + 1;
    [$d, $r] = ol_guess($x, (string)($o['notes'] ?? '')); if ($d) $guess['date']++; if ($r) $guess['receiver']++; }
  arsort($extra);
  // where do dates live? only date-looking values are shown (no names, phones or addresses)
  $dateish = function (array $o, string $pre = '') use (&$dateish): array { $f = [];
    foreach ($o as $k => $v) { $p = $pre === '' ? (string)$k : "$pre.$k";
      if (is_array($v)) { $f += $dateish($v, $p); continue; }
      if (!is_scalar($v) || in_array($p, ['created_at', 'updated_at'], true)) continue;
      $v = (string)$v;
      if (preg_match('/20\d{2}-\d{2}-\d{2}|\d{1,2}\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec|Mac|Mei|Ogos|Dis)[a-z]*\s+20\d{2}|\d{1,2}\/\d{1,2}\/20\d{2}|\d{1,2}:\d{2}\s*(AM|PM)/i', $v)) $f[preg_replace('/\.\d+\./', '.#.', $p)] = mb_substr($v, 0, 60);
    } return $f; };
  $found = []; foreach (array_slice($orders, 0, 20) as $o) $found += $dateish((array)$o);
  $detail = []; $dkeys = [];
  if ($orders) { try { $d = orderla_get('/order/' . rawurlencode((string)($orders[0]['code_id'] ?? ''))); $d = $d['order'] ?? $d['data'] ?? $d;
    $dkeys = array_values(array_diff(array_keys((array)$d), array_keys((array)$orders[0]))); $detail = $dateish((array)$d); } catch (Throwable $e) { $dkeys = ['(gagal: ' . mb_substr($e->getMessage(), 0, 80) . ')']; } }
  json_out(['count' => count($orders), 'msg' => $j['msg'] ?? null, 'keys' => array_keys($keys), 'extra' => array_slice($extra, 0, 60, true), 'guess' => $guess,
    'dates' => $found, 'detail_keys' => $dkeys, 'detail_dates' => array_diff_key($detail, $found)]);

case 'olimport': // one month per call (the page loops 2021-01 → now, so no request runs too long)
  need_owner(); @set_time_limit(120);
  $m = (string)($body['m'] ?? ''); if (!preg_match('/^\d{4}-\d{2}$/', $m)) fail('Bulan tidak sah.');
  try { $j = orderla_get('/orders', ['start_date' => "$m-01", 'end_date' => date('Y-m-t', strtotime("$m-01"))]); } catch (Throwable $e) { fail($e->getMessage(), 502); }
  $c = ['new' => 0, 'updated' => 0, 'skip' => 0];
  foreach ((array)($j['orders'] ?? []) as $o) $c[ol_upsert((array)$o)]++;
  set_setting('ol_synced', date('Y-m-d H:i:s'));
  json_out(['ok' => true, 'm' => $m] + $c);

case 'customers': // everyone who has paid, across the new system and Orderla history, grouped by WhatsApp number
  need_login();
  $agg = [];
  $add = function (?string $ph, string $name, string $at, float $tot, string $src) use (&$agg) {
    $k = wa_num((string)$ph); if (strlen($k) < 10) return;
    $a = &$agg[$k]; $a = ($a ?? ['phone' => $k, 'name' => $name, 'n' => 0, 'rm' => 0.0, 'first' => $at, 'last' => $at, 'src' => []]);
    $a['n']++; $a['rm'] += $tot; if ($at < $a['first']) $a['first'] = $at; if ($at >= $a['last']) { $a['last'] = $at; if ($name !== '') $a['name'] = $name; } $a['src'][$src] = 1;
  };
  foreach (q("SELECT b_phone, b_name, COALESCE(paid_at, created_at) t, total FROM orders WHERE pay_status='paid' AND status<>'batal'")->fetchAll() as $r) $add($r['b_phone'], (string)$r['b_name'], $r['t'], (float)$r['total'], 'baru');
  foreach (q('SELECT phone, name, created_at, total FROM legacy_orders WHERE ' . OL_PAID)->fetchAll() as $r) $add($r['phone'], (string)$r['name'], $r['created_at'], (float)$r['total'], 'orderla');
  $rows = array_values($agg); foreach ($rows as &$r) { $r['rm'] = round($r['rm'], 2); $r['src'] = array_keys($r['src']); } unset($r);
  $sort = (string)($_GET['sort'] ?? 'last');
  usort($rows, fn($a, $b) => $sort === 'rm' ? $b['rm'] <=> $a['rm'] : ($sort === 'n' ? $b['n'] <=> $a['n'] : strcmp($b['last'], $a['last'])));
  if (($_GET['csv'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="100blooms-pelanggan-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, ['Nama', 'WhatsApp', 'Bil. order', 'Jumlah RM', 'Order pertama', 'Order terakhir', 'Sumber']);
    foreach ($rows as $r) fputcsv($out, [$r['name'], $r['phone'], $r['n'], $r['rm'], substr($r['first'], 0, 10), substr($r['last'], 0, 10), implode('+', $r['src'])]); fclose($out); exit;
  }
  json_out(['rows' => $rows, 'total' => count($rows)]);

case 'lastyear': // gifts sent around this week in past years: a ready reason to message the buyer again
  need_login();
  $out = [];
  foreach ([1, 2, 3, 4, 5] as $y) {
    $a = date('Y-m-d', strtotime("-$y year -1 day")); $b = date('Y-m-d', strtotime("-$y year +7 days"));
    foreach (q('SELECT code_id code, name, phone, receiver, items, COALESCE(del_date, DATE(created_at)) d FROM legacy_orders WHERE ' . OL_PAID . ' AND COALESCE(del_date, DATE(created_at)) BETWEEN ? AND ? ORDER BY d', [$a, $b])->fetchAll() as $r) $out[] = $r + ['years' => $y, 'src' => 'orderla'];
    foreach (q("SELECT code, b_name name, b_phone phone, r_name receiver, bouquet items, del_date d FROM orders WHERE pay_status='paid' AND status<>'batal' AND del_date BETWEEN ? AND ? ORDER BY del_date", [$a, $b])->fetchAll() as $r) $out[] = $r + ['years' => $y, 'src' => 'baru'];
  }
  foreach ($out as &$r) $r['phone'] = wa_num((string)$r['phone']); unset($r);
  json_out(['rows' => array_slice($out, 0, 80)]);

case 'agset': // owner: default commission + minimum payout
  need_owner();
  $rate = max(0, min(500, (float)($body['rate'] ?? AGENT_RATE))); $min = max(0, min(5000, (float)($body['payout_min'] ?? PAYOUT_MIN)));
  foreach (['agent_rate' => $rate, 'payout_min' => $min] as $k => $v) { q('DELETE FROM settings WHERE k=?', [$k]); q('INSERT INTO settings (k,v) VALUES (?,?)', [$k, (string)$v]); }
  json_out(['ok' => true]);

case 'products':
  need_login();
  json_out(['rows' => q('SELECT type,item_id,name,active,code,price FROM products ORDER BY type,name')->fetchAll()]);

case 'pmeta': // product code (e.g. FB02) and retail price for the WhatsApp invoice
  need_owner();
  $code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($body['code'] ?? '')));
  $price = (string)($body['price'] ?? '') === '' ? null : max(0, (float)$body['price']);
  q('UPDATE products SET code=?, price=? WHERE type=? AND item_id=?', [$code !== '' ? substr($code, 0, 12) : null, $price, (string)($body['type'] ?? ''), (string)($body['item_id'] ?? '')]);
  json_out(['ok' => true]);

case 'payset': // bank QR + account text shown to customers paying an invoice
  if ($post) { need_owner(); q("DELETE FROM settings WHERE k='bank_text'"); q("INSERT INTO settings (k,v) VALUES ('bank_text',?)", [str_in($body['bank_text'] ?? '', 300)]); json_out(['ok' => true]); }
  need_login(); json_out(['bank_text' => setting('bank_text', ''), 'has_qr' => bank_qr_path() !== null]);

case 'qrup':
  need_owner();
  $f = $_FILES['file'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Gambar gagal dimuat naik.');
  $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) fail('Guna gambar JPG atau PNG.');
  $dir = UPLOAD_DIR . '/_site'; if (!is_dir($dir)) mkdir($dir, 0755, true);
  foreach (glob("$dir/bank-qr.*") ?: [] as $old) @unlink($old);
  if (!move_uploaded_file($f['tmp_name'], "$dir/bank-qr." . ($info[2] === IMAGETYPE_PNG ? 'png' : 'jpg'))) fail('Simpan gambar gagal.', 500);
  json_out(['ok' => true]);

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
  need_owner(); json_out(['rows' => q('SELECT id,email,name,role,doc_name,created_at FROM admins ORDER BY id')->fetchAll()]);

case 'userdoc': // name printed as "Salesperson" on this member's invoices/receipts
  need_owner();
  $n = str_in($body['doc_name'] ?? '', 100);
  q('UPDATE admins SET doc_name=? WHERE id=?', [$n !== '' ? $n : null, (int)($body['id'] ?? 0)]); json_out(['ok' => true]);

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

case 'solat': // JAKIM e-Solat times for today + tomorrow, fetched once a day on the server and cached
  need_login();
  $zone = preg_match('/^[A-Z]{3}\d{2}$/', (string)($_GET['zone'] ?? '')) ? $_GET['zone'] : (string)setting('solat_zone', 'SGR03');
  $today = date('Y-m-d'); $key = "sc_{$zone}_$today"; // daily cache
  $cached = json_decode((string)setting($key, ''), true);
  if (!$cached) {
    $cached = solat_fetch($zone, $today);
    if ($cached) { q("DELETE FROM settings WHERE k LIKE 'sc!_%' ESCAPE '!' AND k<>?", [$key]); set_setting($key, json_encode($cached)); }
  }
  if (!$cached) fail('Waktu solat tak dapat dimuat sekarang.', 502);
  json_out(['zone' => $zone, 'days' => $cached['days'], 'source' => $cached['source'], 'now' => date('Y-m-d H:i:s')]);

case 'dash': // ?by=paid (tarikh bayar) | created (tarikh order). Sales = paid, not cancelled orders, grouped by the chosen date.
  need_login();
  $col = ($_GET['by'] ?? 'paid') === 'created' ? 'created_at' : 'paid_at';
  $now = time(); $t = date('Y-m-d', $now); $m0 = date('Y-m-01', $now);
  $since = date('Y-m-01', strtotime('-23 months', strtotime($m0))) . ' 00:00:00';
  $rows = q("SELECT $col AS d, total FROM orders WHERE pay_status='paid' AND status<>'batal' AND $col>=?", [$since])->fetchAll();
  try { $rows = array_merge($rows, q('SELECT created_at AS d, total FROM legacy_orders WHERE ' . OL_PAID . ' AND created_at>=?', [$since])->fetchAll()); } catch (Throwable $e) {} // Orderla history
  $pts = array_map(fn($r) => [strtotime($r['d']), (float)$r['total']], $rows);
  $sum = function (int $from, int $to) use ($pts) { $n = 0; $s = 0.0; foreach ($pts as [$ts, $v]) if ($ts >= $from && $ts <= $to) { $n++; $s += $v; } return ['n' => $n, 's' => round($s, 2)]; };
  $cmp = fn(int $a, int $b, int $pa, int $pb) => $sum($a, $b) + ['prev' => $sum($pa, $pb)];
  // like-for-like: today so far vs yesterday up to the same time; month to date vs the same days last month; last 7 days vs the 7 before
  $d0 = strtotime("$t 00:00:00");
  $lm0 = strtotime(date('Y-m-01', strtotime('-1 month', strtotime($m0)))); $lmEnd = strtotime(date('Y-m-t 23:59:59', $lm0));
  $lmCut = strtotime(date('Y-m-', $lm0) . date('d H:i:s', $now)); // same day + time last month
  if ($lmCut === false || date('Y-m', $lmCut) !== date('Y-m', $lm0)) $lmCut = $lmEnd; // e.g. 31st vs a 30-day month
  $w0 = strtotime('-6 days', $d0);
  $kpi = ['today' => $cmp($d0, $now, $d0 - 86400, $now - 86400), 'week' => $cmp($w0, $now, $w0 - 7 * 86400, $w0 - 1),
    'month' => $cmp(strtotime($m0), $now, $lm0, $lmCut)];
  $kpi['month']['full'] = $sum($lm0, $lmEnd);
  $kpi['today']['yday'] = $sum($d0 - 86400, $d0 - 1); // whole of yesterday // whole of last month, the number to beat
  $created = fn(int $a, int $b) => (int)q('SELECT COUNT(*) FROM orders WHERE created_at>=? AND created_at<=?', [date('Y-m-d H:i:s', $a), date('Y-m-d H:i:s', $b)])->fetchColumn();
  $kpi['created'] = ['n' => $created($d0, $now), 'prev' => ['n' => $created($d0 - 86400, $now - 86400)]];
  $pend = q("SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM orders WHERE pay_status='pending' AND status<>'batal' AND created_at>=?", [date('Y-m-d H:i:s', $now - 7 * 86400)])->fetch();
  // series + the period before (day: previous 14 days, week: previous 12 weeks, month: same months last year)
  $bucket = function (string $fmt, array $keys) use ($pts) { $o = array_fill_keys($keys, 0.0); foreach ($pts as [$ts, $v]) { $k = date($fmt, $ts); if (isset($o[$k])) $o[$k] += $v; } return array_map(fn($x) => round($x, 2), $o); };
  $days = []; $pdays = []; for ($i = 13; $i >= 0; $i--) { $days[] = date('Y-m-d', strtotime("-$i days", $d0)); $pdays[] = date('Y-m-d', strtotime('-' . ($i + 14) . ' days', $d0)); }
  $wks = []; $pwks = []; for ($i = 11; $i >= 0; $i--) { $wks[] = date('o-\WW', strtotime("-$i weeks", $d0)); $pwks[] = date('o-\WW', strtotime('-' . ($i + 12) . ' weeks', $d0)); }
  $mos = []; $pmos = []; for ($i = 11; $i >= 0; $i--) { $mos[] = date('Y-m', strtotime("-$i months", strtotime($m0))); $pmos[] = date('Y-m', strtotime('-' . ($i + 12) . ' months', strtotime($m0))); }
  $series = ['day' => ['keys' => $days, 'cur' => array_values($bucket('Y-m-d', $days)), 'prev' => array_values($bucket('Y-m-d', $pdays))],
    'week' => ['keys' => $wks, 'cur' => array_values($bucket('o-\WW', $wks)), 'prev' => array_values($bucket('o-\WW', $pwks))],
    'month' => ['keys' => $mos, 'cur' => array_values($bucket('Y-m', $mos)), 'prev' => array_values($bucket('Y-m', $pmos))]];
  $todayDel = q("SELECT status, COUNT(*) n FROM orders WHERE del_date=? AND pay_status='paid' AND status<>'batal' GROUP BY status", [$t])->fetchAll();
  $topBq = q("SELECT bouquet, COUNT(*) n FROM orders WHERE pay_status='paid' AND status<>'batal' AND $col>=? GROUP BY bouquet ORDER BY n DESC", ["$m0 00:00:00"])->fetchAll();
  $C = catalog(); foreach ($topBq as &$b) $b['name'] = $C['bq'][$b['bouquet']] ?? $b['bouquet']; unset($b);
  // all time, per year: new system + Orderla history
  $yr = []; $all = ['baru' => ['n' => 0, 's' => 0.0], 'orderla' => ['n' => 0, 's' => 0.0]];
  foreach (q("SELECT SUBSTR($col,1,4) y, COUNT(*) n, COALESCE(SUM(total),0) s FROM orders WHERE pay_status='paid' AND status<>'batal' AND $col IS NOT NULL GROUP BY SUBSTR($col,1,4)")->fetchAll() as $r) { $yr[$r['y']] = ($yr[$r['y']] ?? 0) + (float)$r['s']; $all['baru']['n'] += (int)$r['n']; $all['baru']['s'] += (float)$r['s']; }
  try { foreach (q('SELECT SUBSTR(created_at,1,4) y, COUNT(*) n, COALESCE(SUM(total),0) s FROM legacy_orders WHERE ' . OL_PAID . ' GROUP BY SUBSTR(created_at,1,4)')->fetchAll() as $r) { $yr[$r['y']] = ($yr[$r['y']] ?? 0) + (float)$r['s']; $all['orderla']['n'] += (int)$r['n']; $all['orderla']['s'] += (float)$r['s']; } } catch (Throwable $e) {}
  if ($yr) { $y0 = (int)min(array_keys($yr)); $ys = range($y0, (int)date('Y')); $series['year'] = ['keys' => array_map('strval', $ys), 'cur' => array_map(fn($y) => round($yr[(string)$y] ?? 0, 2), $ys), 'prev' => array_map(fn($y) => round($yr[(string)($y - 1)] ?? 0, 2), $ys)]; }
  json_out(['by' => $col === 'created_at' ? 'created' : 'paid', 'kpi' => $kpi, 'pending' => $pend, 'series' => $series, 'alltime' => $all,
    'cut' => ['now' => date('H:i', $now), 'lm' => date('Y-m-d', $lm0) . '|' . date('Y-m-d', $lmCut), 'lm_end' => date('Y-m-d', $lmEnd), 'm_days' => [(int)date('j', $now), (int)date('t', $now)]], 'today_del' => array_column($todayDel, 'n', 'status'), 'top_bq' => $topBq]);

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
    try {
      $lw = $by === 'del_date' ? 'del_date>=? AND del_date<=?' : 'created_at>=? AND created_at<=?';
      foreach (q('SELECT * FROM legacy_orders WHERE ' . OL_PAID . " AND $lw ORDER BY created_at", $by === 'del_date' ? [$from, $to] : ["$from 00:00:00", "$to 23:59:59"])->fetchAll() as $r) {
        $o = json_decode((string)$r['raw'], true) ?: []; $P = ol_report_parts($o, json_decode((string)$r['extra'], true) ?: []);
        fputcsv($out, [$r['invoice_no'] ?: $r['code_id'], $r['created_at'], $r['created_at'], $r['del_date'] ?? '', implode(', ', $P['del']), 'Orderla: ' . $r['status'], implode(', ', $P['occasion']), implode(', ', $P['bouquet']),
          implode(', ', $P['balloon']), implode(', ', $P['cake']), '', $r['receiver'] ?? '', $r['postcode'], implode(', ', $P['zone']), $r['name'], $r['phone'], implode(', ', $P['voucher']), $o['discount'] ?? 0, '', $r['total'], 'Orderla']);
      }
    } catch (Throwable $e) {}
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
    $inc('del', $DL[$o['del_type']] ?? $o['del_type'], $v); $inc('zone', $o['zone'] === 'plus20' ? 'Zon merah (+RM20)' : ($o['zone'] === 'out' ? 'Luar kawasan (kes khas)' : 'Zon biru (percuma)'), $v);
    $inc('area', substr($o['r_post'], 0, 2) . 'xxx', $v); $inc('source', $o['agent_ref'] ? 'Agent: ' . $o['agent_ref'] : (!empty($o['created_by']) ? 'Sales HQ: ' . $o['created_by'] : 'Laman web (terus)'), $v);
    if (!empty($o['voucher'])) $inc('voucher', $o['voucher'], $v);
  }
  $olN = 0; $olS = 0.0;
  try { // Orderla orders in the same period: paid date = order date (Orderla doesn't send a paid time); delivery date when known
    $lw = $by === 'del_date' ? 'del_date>=? AND del_date<=?' : 'created_at>=? AND created_at<=?';
    foreach (q('SELECT created_at, del_date, total, postcode, extra, raw FROM legacy_orders WHERE ' . OL_PAID . " AND $lw", $by === 'del_date' ? [$from, $to] : ["$from 00:00:00", "$to 23:59:59"])->fetchAll() as $r) {
      $v = (float)$r['total']; $sales += $v; $olN++; $olS += $v;
      $d = substr((string)($by === 'del_date' ? $r['del_date'] : $r['created_at']), 0, 10); $series[$d] = ($series[$d] ?? 0) + $v;
      $o = json_decode((string)$r['raw'], true) ?: []; $disc += (float)($o['discount'] ?? 0);
      foreach (ol_report_parts($o, json_decode((string)$r['extra'], true) ?: []) as $k => $list) foreach (array_unique($list) as $nm) $inc($k, $nm, $v);
      if (preg_match('/^\d{5}$/', (string)$r['postcode'])) $inc('area', substr($r['postcode'], 0, 2) . 'xxx', $v);
      $inc('source', 'Orderla', $v);
    }
    $createdN += (int)q('SELECT COUNT(*) FROM legacy_orders WHERE created_at>=? AND created_at<=?', ["$from 00:00:00", "$to 23:59:59"])->fetchColumn();
    $createdPaid += (int)q('SELECT COUNT(*) FROM legacy_orders WHERE ' . OL_PAID . ' AND created_at>=? AND created_at<=?', ["$from 00:00:00", "$to 23:59:59"])->fetchColumn();
  } catch (Throwable $e) {}
  $nPaid = count($paid) + $olN;
  foreach ($rank as &$r) { uasort($r, fn($x, $y) => $y[0] <=> $x[0] ?: $y[1] <=> $x[1]); $r = array_map(fn($k, $x) => ['name' => (string)$k, 'n' => $x[0], 'sales' => round($x[1], 2)], array_keys($r), $r); } unset($r);
  $days = []; for ($d = strtotime($from); $d <= strtotime($to) && count($days) < 400; $d += 86400) $days[date('Y-m-d', $d)] = round($series[date('Y-m-d', $d)] ?? 0, 2);
  // after-delivery care: problems (by delivery date), and how the thank-you / apology / recipient vouchers performed (by voucher creation date)
  $after = ['issues' => 0, 'reasons' => [], 'auto' => 0, 'rcp_views' => 0, 'v' => []];
  foreach (q("SELECT issue, aftercare FROM orders WHERE pay_status='paid' AND status<>'batal' AND del_date>=? AND del_date<=?", [$from, $to])->fetchAll() as $r) {
    $i = jdec($r['issue']); $ac = jdec($r['aftercare']);
    if ($i && !empty($i['reasons'])) { $after['issues']++; foreach ($i['reasons'] as $k) { $nm = ISSUE_REASONS[$k] ?? $k; $after['reasons'][$nm] = ($after['reasons'][$nm] ?? 0) + 1; } }
    if (!empty($ac['auto_at'])) $after['auto']++; if (!empty($ac['rcp_viewed_at'])) $after['rcp_views']++;
  }
  arsort($after['reasons']);
  foreach (['TQ' => 'Hadiah terima kasih (TQ)', 'HAI' => 'Voucher penerima (HAI)', 'MAAF' => 'Voucher maaf (MAAF)'] as $px => $nm) {
    $w = "v.code LIKE '$px%' AND LENGTH(v.code)=" . (strlen($px) + 6) . " AND v.created_at>=? AND v.created_at<=?";
    $iss = (int)q("SELECT COUNT(*) FROM vouchers v WHERE $w", ["$from 00:00:00", "$to 23:59:59"])->fetchColumn();
    $u = q("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) s FROM orders o JOIN vouchers v ON v.code=o.voucher WHERE o.pay_status='paid' AND o.status<>'batal' AND $w", ["$from 00:00:00", "$to 23:59:59"])->fetch();
    $after['v'][] = ['name' => $nm, 'issued' => $iss, 'used' => (int)$u['n'], 'sales' => round((float)$u['s'], 2)];
  }
  json_out(['after' => $after, 'from' => $from, 'to' => $to, 'by' => $by, 'sales' => round($sales, 2), 'paid' => $nPaid, 'avg' => $nPaid ? round($sales / $nPaid, 2) : 0,
    'orderla' => ['n' => $olN, 's' => round($olS, 2)], 'created' => $createdN, 'pay_rate' => $createdN ? round($createdPaid / $createdN * 100) : 0, 'discount' => round($disc, 2), 'rank' => $rank, 'series' => $days]);

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
  try { // Orderla orders (history, and anything still being taken there): by delivery date when known, else order date
    $lc = $by === 'del' ? 'COALESCE(del_date, DATE(created_at))' : 'DATE(created_at)';
    foreach (q("SELECT id, code_id, invoice_no, created_at, del_date, status, pay_status, total, name, receiver, items FROM legacy_orders
        WHERE $lc BETWEEN ? AND ? AND (status IS NULL OR status<>'cancelled') ORDER BY created_at", [$lo, $hi])->fetchAll() as $r) {
      $d = $by === 'del' ? ($r['del_date'] ?: substr($r['created_at'], 0, 10)) : substr($r['created_at'], 0, 10);
      $days[$d][] = ['src' => 'orderla', 'lid' => (int)$r['id'], 'code' => $r['invoice_no'] ?: $r['code_id'], 'pay_status' => $r['pay_status'] === 'paid' ? 'paid' : 'pending',
        'ol_status' => $r['status'], 'no_del' => !$r['del_date'], 'r_name' => $r['receiver'] ?: '-', 'b_name' => $r['name'], 'items' => $r['items'], 'total' => (float)$r['total']];
    }
  } catch (Throwable $e) {}
  json_out(['m' => $m, 'by' => $by, 'days' => $days]);

case 'upcoming': // deliveries from today to the end of next month, both systems
  need_login();
  $a = date('Y-m-d'); $b = date('Y-m-t', strtotime('first day of next month'));
  $new = (int)q("SELECT COUNT(*) FROM orders WHERE status<>'batal' AND del_date BETWEEN ? AND ?", [$a, $b])->fetchColumn();
  $newPend = (int)q("SELECT COUNT(*) FROM orders WHERE status<>'batal' AND pay_status<>'paid' AND del_date BETWEEN ? AND ?", [$a, $b])->fetchColumn();
  $ol = 0; $olNoDate = 0;
  try {
    $ol = (int)q("SELECT COUNT(*) FROM legacy_orders WHERE (status IS NULL OR status<>'cancelled') AND del_date BETWEEN ? AND ?", [$a, $b])->fetchColumn();
    $olNoDate = (int)q("SELECT COUNT(*) FROM legacy_orders WHERE (status IS NULL OR status NOT IN ('cancelled','completed')) AND del_date IS NULL AND created_at>=?", [date('Y-m-d H:i:s', strtotime('-60 days'))])->fetchColumn();
  } catch (Throwable $e) {}
  json_out(['to' => $b, 'new' => $new, 'new_pending' => $newPend, 'orderla' => $ol, 'orderla_nodate' => $olNoDate, 'synced' => setting('ol_synced'), 'auto' => (string)setting('ol_token', '') !== '', 'err' => setting('ol_err')]);

case 'legacy': // one Orderla order, read-only
  need_login();
  $r = q('SELECT * FROM legacy_orders WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch(); if (!$r) fail('Order tidak dijumpai.', 404);
  unset($r['raw']); $r['extra'] = json_decode((string)$r['extra'], true) ?: [];
  json_out(['order' => $r]);

case 'quote': // live price preview for the manual order form
  need_login();
  $d = is_array($body['data'] ?? null) ? $body['data'] : [];
  $r = build_order($d, max(0, (int)($body['photos'] ?? 0)), null, manual_opts($d));
  if (isset($r['errors'])) { // still show the running total: fill missing customer details with placeholders, price only
    $tmp = $d; $ph = ['bname' => 'X', 'bphone' => '0123456789', 'rname' => 'X', 'rphone' => '0123456789', 'raddr' => 'Alamat sementara untuk kira harga', 'rpost' => '43800'];
    foreach ($ph as $k => $v) if (trim((string)($tmp[$k] ?? '')) === '' || ($k === 'rpost' && !preg_match('/^\d{5}$/', (string)$tmp[$k])) || (str_ends_with($k, 'phone') && strlen(preg_replace('/\D/', '', (string)$tmp[$k])) < 9)) $tmp[$k] = $v;
    $p = build_order($tmp, max(0, (int)($body['photos'] ?? 0)), null, ['override' => true] + manual_opts($tmp));
    json_out(['errors' => $r['errors']] + (isset($p['errors']) ? [] : ['lines' => json_decode($p['order']['price_lines'], true), 'total' => $p['order']['total']]));
  }
  json_out(['lines' => json_decode($r['order']['price_lines'], true), 'total' => $r['order']['total'], 'zone' => $r['order']['zone']]);

case 'manual': // sales team keys in an order taken on WhatsApp
  $m = need_login();
  $d = json_decode((string)($_POST['data'] ?? ''), true); if (!is_array($d)) fail('Data tidak lengkap.');
  $files = collect_photos();
  $r = build_order($d, count($files), null, manual_opts($d));
  if (isset($r['errors'])) fail(implode(' ', $r['errors']), 422);
  $mode = in_array($d['pay_mode'] ?? '', ['paid', 'link'], true) ? $d['pay_mode'] : 'invoice';
  $payRef = str_in($d['pay_ref'] ?? '', 60);
  if ($mode === 'paid' && $payRef === '') fail('Isi rujukan bayaran (contoh no. transaksi atau "tunai").');
  $o = $r['order'];
  $o['code'] = new_code(); $o['token'] = bin2hex(random_bytes(12)); $o['created_at'] = date('Y-m-d H:i:s');
  $ag = agent_from_ref($d['ref'] ?? ''); if (trim((string)($d['ref'] ?? '')) !== '' && !$ag) fail('Kod agent tidak dijumpai atau tidak aktif.');
  $o['agent_ref'] = $ag ? (string)$ag['id'] : null;
  $o['created_by'] = $m['name']; $o['pay_mode'] = $mode === 'link' ? 'gateway' : 'invoice';
  $note = trim('Order WhatsApp oleh ' . $m['name'] . (!empty($d['override']) ? ' (had waktu/kawasan diabaikan)' : '') . '. ' . str_in($d['notes'] ?? '', 500));
  $o['notes'] = $note;
  if ($mode === 'paid') { $o['pay_status'] = 'paid'; $o['status'] = 'baru'; $o['paid_at'] = date('Y-m-d H:i:s'); $o['pay_channel'] = 'Manual (' . $m['name'] . ')'; $o['pay_ref'] = $payRef; }
  try {
    $o['photos'] = json_encode(save_photos($o['code'], $files));
    $cols = array_keys($o);
    q('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($o));
    $o['id'] = (int)db()->lastInsertId();
  } catch (Throwable $e) { error_log('100blooms manual: ' . $e->getMessage()); fail('Order tak dapat disimpan. Cuba lagi.', 500); }
  $track = SITE_URL . '/semak/?c=' . rawurlencode($o['code']) . '&k=' . $o['token'];
  $inv = invoice_msg($o);
  if ($mode === 'paid') { after_paid((int)$o['id']); json_out(['id' => $o['id'], 'code' => $o['code'], 'total' => $o['total'], 'paid' => true, 'track_url' => $track, 'invoice' => $inv]); }
  if ($mode === 'invoice') json_out(['id' => $o['id'], 'code' => $o['code'], 'total' => $o['total'], 'paid' => false, 'mode' => 'invoice', 'track_url' => $track, 'invoice' => $inv, 'has_qr' => bank_qr_path() !== null]);
  try { [$bill, $url] = pay_create($o); q('UPDATE orders SET bill_code=? WHERE id=?', [$bill, $o['id']]); }
  catch (Throwable $e) { error_log('100blooms manual bill: ' . $e->getMessage()); json_out(['id' => $o['id'], 'code' => $o['code'], 'total' => $o['total'], 'paid' => false, 'payUrl' => null, 'track_url' => $track, 'warn' => 'Order disimpan tapi link bayaran gagal dicipta: ' . mb_substr($e->getMessage(), 0, 150)]); }
  json_out(['id' => $o['id'], 'code' => $o['code'], 'total' => $o['total'], 'paid' => false, 'payUrl' => $url, 'track_url' => $track, 'invoice' => invoice_msg($o, $url)]);

case 'biz': // business details printed on invoices and receipts
  need_login();
  if ($post) { need_owner(); foreach (['name' => 120, 'reg' => 40, 'phone' => 30, 'addr' => 300, 'email' => 120] as $k => $n) set_setting('biz_' . $k, str_in($body[$k] ?? '', $n)); }
  json_out(['biz' => biz_info()]);

case 'hqorder': // Sales HQ page (/sales/): customer order flow, saved under this team member, payment link + WhatsApp invoice for the buyer
  $m = need_login(); if (!$post) fail('POST sahaja.', 405);
  $d = json_decode((string)($_POST['data'] ?? ''), true); if (!is_array($d)) fail('Data order tidak lengkap.');
  $files = collect_photos();
  $r = build_order($d, count($files));
  if (isset($r['errors'])) fail(implode(' ', $r['errors']), 422);
  $o = $r['order'];
  $o['code'] = new_code(); $o['token'] = bin2hex(random_bytes(12)); $o['created_at'] = date('Y-m-d H:i:s');
  $o['agent_ref'] = null; $o['created_by'] = $m['name']; $o['pay_mode'] = 'gateway';
  $o['notes'] = trim('Order Sales HQ oleh ' . $m['name'] . '.');
  try {
    $o['photos'] = json_encode(save_photos($o['code'], $files));
    $cols = array_keys($o);
    q('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($o));
    $o['id'] = (int)db()->lastInsertId();
  } catch (Throwable $e) { error_log('100blooms hqorder: ' . $e->getMessage()); fail('Order tak dapat disimpan. Cuba lagi.', 500); }
  $track = SITE_URL . '/semak/?c=' . rawurlencode($o['code']) . '&k=' . $o['token'];
  $url = null; $warn = null;
  try { [$bill, $url] = pay_create($o); q('UPDATE orders SET bill_code=? WHERE id=?', [$bill, $o['id']]); }
  catch (Throwable $e) { error_log('100blooms hqorder bill: ' . $e->getMessage()); $warn = 'Link bayaran online gagal dicipta. Pembeli masih boleh bayar melalui QR bank di Invoice Link.'; }
  $inv = invoice_msg($o, $url);
  json_out(['id' => $o['id'], 'code' => $o['code'], 'total' => $o['total'], 'b_phone' => wa_num((string)$o['b_phone']), 'b_name' => $o['b_name'], 'track_url' => $track, 'payUrl' => $url, 'invoice' => $inv, 'doc' => doc_url($o, 'invoice'), 'warn' => $warn]);

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
