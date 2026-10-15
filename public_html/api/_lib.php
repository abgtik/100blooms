<?php
// Shared helpers: config, database, JSON responses, business rules (pricing, zones, delivery windows), ToyyibPay.
declare(strict_types=1);

$cfg = dirname(__DIR__) . '/config.php';
if (!is_file($cfg)) { http_response_code(500); header('Content-Type: application/json'); echo '{"error":"config.php belum disediakan"}'; exit; }
require_once $cfg;
date_default_timezone_set('Asia/Kuala_Lumpur');

const BASE_PRICE = 128;
const CAKE_UPGRADE = 10;
const EXTRA_CAKE = 20;
const EXTRA_PHOTO = 10;
const MAX_EXTRA_CAKE = 10;
const MAX_PHOTOS = 10;
const OTHER_CAKE = 'Pilihan lain (ikut stok)';
// Customer photos must not be reachable by URL. On Plesk, nginx can serve static files directly and skip
// .htaccess, so prefer the webspace "private" folder (outside httpdocs) when it exists.
if (!defined('UPLOAD_DIR')) {
  $priv = dirname(__DIR__, 2) . '/private';
  define('UPLOAD_DIR', (is_dir($priv) && is_writable($priv)) ? $priv . '/uploads' : dirname(__DIR__) . '/uploads');
}

// ---------- catalogue (ids must match the customer app) ----------
function catalog(): array {
  return [
    'bq' => ['glam'=>'Glam','rossa'=>'Rossa','jessie'=>'Jessie','sunny'=>'Sunny','sugar'=>'Sugar','walter'=>'Walter','kiddo'=>'Kiddo','romeo'=>'Romeo'],
    'bal' => ['hb-pink'=>'Happy Birthday Pink','hb-blue'=>'Happy Birthday Biru','hb-floral'=>'Happy Birthday Floral','hb-retro'=>'Happy Birthday To You',
      'hb-jungle'=>'Happy Birthday Jungle','anniv'=>'Happy Anniversary','ily-red'=>'I Love You Merah','ily-rw'=>'I Love You Putih-Merah','roses'=>'Heart Roses',
      'ly-gold'=>'Love You Gold','candy'=>'Sweet Heart','sorry-dots'=>"I'm Sorry Polka Dot",'sorry-stripe'=>"I'm Sorry Pelangi",'gws-sun'=>'Get Well Soon Matahari',
      'gws-wishes'=>'Get Well Soon Wishes','gws-plaster'=>'Get Well Soon Plaster','grad-gold'=>'Congrats Grad Gold','grad-black'=>'Congrats Graduate',
      'grad-white'=>'Congrats Grad Putih','grad-pink'=>'Congrats Grad Pink','grad-key'=>'Congrats Grad Key','mom'=>'Love You Mom','dad'=>'Best Dad Ever',
      'baby-boy'=>'New Baby Boy','baby-girl'=>'New Baby Girl'],
    'cake_free' => ['Chocolate Chip Walnut','Chocolate Moist','Carrot Cake','Chocolate Fudge'],
    'cake_up' => ['Chocolate Indulgence','Red Velvet', OTHER_CAKE],
    'occ' => ['birthday'=>'Birthday','anniv'=>'Anniversary','pujuk'=>'Pujuk orang merajuk','convo'=>'Konvokesyen','rindu'=>'Rindu','thanks'=>'Terima kasih','sembuh'=>'Cepat sembuh','baby'=>'Kelahiran bayi','saja'=>'Saja-saja'],
    'rel' => ['isteri'=>'Isteri','suami'=>'Suami','tunang'=>'Tunang','kekasih'=>'Kekasih','ibu'=>'Ibu','ayah'=>'Ayah','adik'=>'Adik / Kakak / Abang','makcik'=>'Mak saudara / Pak saudara','cikgu'=>'Cikgu / Pensyarah','kawan'=>'Kawan baik','kerja'=>'Rakan sekerja / Bos','kenal'=>'Baru nak berkenalan'],
  ];
}

// ---------- delivery zones (owner's map, Oct 2026) ----------
const ZONE_FREE = [[50000,60000],[62000,62988],[63000,63300],[64000,64300],[68000,68099],[40000,41999],[42000,42199],[42300,42399],[42500,42799],[43000,43949],[46000,46999],[47100,47999],[71800,71809]];
const ZONE_20 = [[47000,47099],[48000,48099],[68100,68199]];
function zone_of(string $pc): string {
  if (!preg_match('/^\d{5}$/', $pc)) return 'out';
  $n = (int)$pc;
  foreach (ZONE_FREE as [$a,$b]) if ($n >= $a && $n <= $b) return 'free';
  foreach (ZONE_20 as [$a,$b]) if ($n >= $a && $n <= $b) return 'plus20';
  return 'out';
}

const DELIVERY = [
  'free' => ['label'=>'Penghantaran percuma (10am–6pm)', 'fee'=>0,  'close'=>17],
  'A'    => ['label'=>'Slot A (9am–12pm)',               'fee'=>10, 'close'=>9],
  'B'    => ['label'=>'Slot B (1pm–4pm)',                'fee'=>10, 'close'=>14],
  'C'    => ['label'=>'Slot C (5pm–8pm)',                'fee'=>10, 'close'=>18],
  'spec' => ['label'=>'Masa khusus',                     'fee'=>50, 'close'=>null],
];

// ---------- database ----------
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO(DB_DSN, defined('DB_USER') ? DB_USER : null, defined('DB_PASS') ? DB_PASS : null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
  if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $pdo->exec("SET time_zone = '+08:00'");
  migrate($pdo);
  register_shutdown_function('cron_tick'); // light background jobs after each response (throttled)
  return $pdo;
}
/** Small, idempotent schema upgrades for installs made before a feature existed (setup.php is deleted after install). */
function migrate(PDO $pdo): void {
  try { $v = (int)$pdo->query("SELECT v FROM settings WHERE k='schema_v'")->fetchColumn(); } catch (Throwable $e) { return; } // not installed yet
  if ($v >= 8) return;
  $my = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
  $run = function (string $sql) use ($pdo) { try { $pdo->exec($sql); } catch (PDOException $e) { if (!preg_match('/exist|Duplicate/i', $e->getMessage())) throw $e; } };
  $run("CREATE TABLE IF NOT EXISTS vouchers (
    code VARCHAR(30) PRIMARY KEY, type VARCHAR(8) NOT NULL, value DECIMAL(8,2) NOT NULL DEFAULT 0, max_off DECIMAL(8,2) NULL,
    gift_name VARCHAR(100) NULL, agent_ok TINYINT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1,
    starts_on DATE NULL, ends_on DATE NULL, max_uses INT NULL, once_per_phone TINYINT NOT NULL DEFAULT 1,
    note VARCHAR(200) NULL, created_at DATETIME NOT NULL)$tail");
  $run('ALTER TABLE orders ADD COLUMN voucher VARCHAR(30) NULL');
  $run('ALTER TABLE orders ADD COLUMN discount DECIMAL(8,2) NOT NULL DEFAULT 0');
  // v3: production checklist + delivery steps
  $run('ALTER TABLE orders ADD COLUMN prep TEXT NULL');
  $run('ALTER TABLE orders ADD COLUMN del_steps TEXT NULL');
  $run('ALTER TABLE orders ADD COLUMN lalamove_url VARCHAR(300) NULL');
  // v4: who keyed in the order (null = customer via website)
  $run('ALTER TABLE orders ADD COLUMN created_by VARCHAR(100) NULL');
  // v5: product codes / retail prices, bouquet wrapping note, payment mode
  $run('ALTER TABLE products ADD COLUMN code VARCHAR(12) NULL');
  $run('ALTER TABLE products ADD COLUMN price DECIMAL(8,2) NULL');
  $run('ALTER TABLE orders ADD COLUMN bq_note VARCHAR(100) NULL');
  $run("ALTER TABLE orders ADD COLUMN pay_mode VARCHAR(10) NOT NULL DEFAULT 'gateway'");
  // v6: buyer edits the wishcard from the tracking page (limited)
  $run('ALTER TABLE orders ADD COLUMN wish_edits INT NOT NULL DEFAULT 0');
  // v7: agents, commissions, payouts
  foreach (agent_schema($my ? 'mysql' : 'sqlite') as $sql) $run($sql);
  // v8: WhatsApp notifications already sent per order (no repeats)
  $run('ALTER TABLE orders ADD COLUMN wa_log TEXT NULL');
  if ($v < 3) { // carry over orders already marked done with the old single status
    $all = json_encode(array_fill_keys(array_keys(DEL_STEPS), ['by' => 'sistem', 'at' => date('Y-m-d H:i:s')]));
    $pdo->prepare("UPDATE orders SET del_steps=? WHERE status='selesai' AND del_steps IS NULL")->execute([$all]);
    $pdo->exec("UPDATE orders SET status='baru' WHERE status IN ('disiapkan','dalam_penghantaran') AND pay_status='paid'");
  }
  $pdo->exec("DELETE FROM settings WHERE k='schema_v'");
  $pdo->exec("INSERT INTO settings (k,v) VALUES ('schema_v','8')");
}
function agent_schema(string $driver): array {
  $my = $driver === 'mysql';
  $id = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
  $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
  return [
"CREATE TABLE IF NOT EXISTS agents (
  id $id, name VARCHAR(100) NOT NULL, phone VARCHAR(30) NOT NULL, email VARCHAR(150) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL,
  active TINYINT NOT NULL DEFAULT 1, rate DECIMAL(8,2) NULL,
  bank_name VARCHAR(60) NULL, bank_acc VARCHAR(40) NULL, bank_holder VARCHAR(100) NULL, qr_file VARCHAR(40) NULL,
  ig VARCHAR(150) NULL, tiktok VARCHAR(150) NULL, fb VARCHAR(150) NULL, created_at DATETIME NOT NULL, last_login DATETIME NULL
)$tail",
"CREATE TABLE IF NOT EXISTS commissions (
  id $id, order_id INT NOT NULL UNIQUE, agent_id INT NOT NULL, amount DECIMAL(8,2) NOT NULL, created_at DATETIME NOT NULL, payout_id INT NULL
)$tail",
"CREATE INDEX idx_comm_agent ON commissions (agent_id)",
"CREATE TABLE IF NOT EXISTS payouts (
  id $id, agent_id INT NOT NULL, amount DECIMAL(8,2) NOT NULL, ref VARCHAR(100) NULL, paid_by VARCHAR(100) NULL, created_at DATETIME NOT NULL
)$tail",
  ];
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function setting(string $k, $default = null) { $r = q('SELECT v FROM settings WHERE k=?', [$k])->fetchColumn(); return $r === false ? $default : $r; }

// ---------- http ----------
function json_out($data, int $code = 200): void {
  http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
function fail(string $msg, int $code = 400): void { json_out(['error' => $msg], $code); }
function str_in($v, int $max): string { $s = trim((string)($v ?? '')); return mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s), 0, $max); }
function digits(string $s): string { return preg_replace('/\D/', '', $s); }

// ---------- availability ----------
function off_items(): array {
  $off = ['bq'=>[], 'bal'=>[], 'cake'=>[]];
  foreach (q("SELECT type, item_id FROM products WHERE active=0")->fetchAll() as $r) $off[$r['type']][] = $r['item_id'];
  return $off;
}
function blocked_dates(): array {
  return array_column(q("SELECT d FROM blocked_dates WHERE d >= ? ORDER BY d", [date('Y-m-d')])->fetchAll(), 'd');
}

// ---------- order workflow: payment → production checklist → delivery steps ----------
// Delivery labels are for the team only; customers never see the courier's name.
const DEL_STEPS = ['booked' => 'Lalamove ditempah', 'otw_pickup' => 'Runner OTW ambil barang', 'picked' => 'Runner dah ambil, dalam perjalanan',
  'delivered' => 'Dah sampai kepada penerima', 'done' => 'Order selesai'];
const STATUS_LABEL = ['menunggu_bayaran' => 'Menunggu bayaran', 'baru' => 'Baru (dibayar)', 'disiapkan' => 'Sedang disiapkan', 'siap' => 'Siap dihantar',
  'dalam_penghantaran' => 'Dalam penghantaran', 'sampai' => 'Dah sampai', 'selesai' => 'Selesai', 'batal' => 'Batal'];
/** Checklist items for this order: [key => label]. Polaroid only if photos were sent, gift only if a gift voucher was used. */
function prep_items(array $o): array {
  $C = catalog();
  $it = ['bouquet' => 'Bouquet ' . ($C['bq'][$o['bouquet']] ?? $o['bouquet']), 'balloon' => 'Balloon ' . ($C['bal'][$o['balloon']] ?? $o['balloon']),
    'cake' => 'Kek dibeli (' . $o['cake'] . ')'];
  if ((int)$o['photo_count'] > 0) $it['polaroid'] = 'Polaroid dicetak (' . (int)$o['photo_count'] . ' keping)';
  $it['wishcard'] = 'Wishcard ditulis';
  foreach (json_decode((string)$o['price_lines'], true) ?: [] as $l) if (str_starts_with((string)$l[0], '🎁 ')) $it['gift'] = 'Hadiah voucher: ' . preg_replace('/^🎁 | \(voucher .*\)$/u', '', $l[0]);
  return $it;
}
function jdec($v): array { return is_array($v) ? $v : (json_decode((string)$v, true) ?: []); }
/** Overall status is derived, never set by hand (except Batal). */
function derive_status(array $o): string {
  if ($o['status'] === 'batal') return 'batal';
  if ($o['pay_status'] !== 'paid') return 'menunggu_bayaran';
  $d = jdec($o['del_steps'] ?? null); $p = jdec($o['prep'] ?? null);
  if (isset($d['done'])) return 'selesai';
  if (isset($d['delivered'])) return 'sampai';
  if (isset($d['otw_pickup']) || isset($d['picked'])) return 'dalam_penghantaran';
  $items = prep_items($o); $doneN = count(array_intersect_key($p, $items));
  if ($doneN === count($items)) return 'siap';
  return $doneN > 0 ? 'disiapkan' : 'baru';
}
function progress_text(array $o): string {
  if ($o['pay_status'] !== 'paid' || $o['status'] === 'batal') return '';
  $d = jdec($o['del_steps'] ?? null);
  if ($d) { $last = array_key_last(array_intersect_key(DEL_STEPS, $d)); return DEL_STEPS[$last]; }
  $items = prep_items($o); return 'Penyediaan ' . count(array_intersect_key(jdec($o['prep'] ?? null), $items)) . '/' . count($items);
}

// ---------- agents ----------
const AGENT_RATE = 20;   // default commission per paid order (owner can change in admin)
const PAYOUT_MIN = 40;   // minimum balance paid out on Fridays
function agent_rate_default(): float { return (float)setting('agent_rate', AGENT_RATE); }
function payout_min(): float { return (float)setting('payout_min', PAYOUT_MIN); }
/** Agent number from a ?ref= value, only if that agent exists and is active. */
function agent_from_ref(?string $ref): ?array {
  $ref = preg_replace('/\D/', '', (string)$ref); if ($ref === '') return null;
  return q('SELECT * FROM agents WHERE id=? AND active=1', [(int)$ref])->fetch() ?: null;
}
/** Idempotent: one commission per paid order that came through an agent's link; unpaid commissions of cancelled orders are dropped.
 *  Commissions already paid out are final (agent terms). */
function sync_commissions(): void {
  $rows = q("SELECT o.id, o.paid_at, a.id AS aid, COALESCE(a.rate, ?) AS rate FROM orders o JOIN agents a ON a.id = (o.agent_ref + 0)
    WHERE o.pay_status='paid' AND o.status<>'batal' AND o.agent_ref IS NOT NULL AND o.agent_ref<>''
    AND NOT EXISTS (SELECT 1 FROM commissions c WHERE c.order_id=o.id)", [agent_rate_default()])->fetchAll();
  foreach ($rows as $r) {
    try { q('INSERT INTO commissions (order_id,agent_id,amount,created_at) VALUES (?,?,?,?)', [$r['id'], $r['aid'], $r['rate'], $r['paid_at'] ?: date('Y-m-d H:i:s')]); }
    catch (PDOException $e) { /* another request inserted it */ }
  }
  q("DELETE FROM commissions WHERE payout_id IS NULL AND order_id IN (SELECT id FROM orders WHERE status='batal')");
}
/** Balance owed, lifetime earned and paid for one agent. */
function agent_money(int $aid): array {
  $r = q('SELECT COALESCE(SUM(CASE WHEN payout_id IS NULL THEN amount END),0) AS due, COALESCE(SUM(amount),0) AS earned, COUNT(*) AS n FROM commissions WHERE agent_id=?', [$aid])->fetch();
  $paid = (float)q('SELECT COALESCE(SUM(amount),0) FROM payouts WHERE agent_id=?', [$aid])->fetchColumn();
  return ['due' => (float)$r['due'], 'earned' => (float)$r['earned'], 'orders' => (int)$r['n'], 'paid' => $paid];
}
function next_friday(): string { $d = new DateTimeImmutable('today'); return $d->format('N') == 5 && (int)date('G') < 18 ? $d->format('Y-m-d') : $d->modify('next friday')->format('Y-m-d'); }
/** What an agent may see of the recipient: full name, phone with the last 5 digits hidden, area only. */
function mask_phone(string $p): string { $d = digits($p); return strlen($d) > 5 ? substr($d, 0, -5) . '*****' : '*****'; }
function area_of(string $addr, string $post): string {
  $parts = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $addr)), fn($x) => $x !== ''));
  $states = '/\b(selangor|d\/?e|darul ehsan|kuala lumpur|wilayah persekutuan|w\.?p\.?|putrajaya|negeri sembilan|malaysia)\b/i';
  for ($i = count($parts) - 1; $i >= 0; $i--) {
    $t = trim(preg_replace(['/\b\d{5}\b/', $states], '', $parts[$i]), " .-/");
    if ($t !== '' && !preg_match('/\d/', $t) && !preg_match('/^(no|lot|jalan|jln|lorong|blok|block|unit|taman|tmn)\b/i', $t)) return mb_convert_case($t, MB_CASE_TITLE) . " $post";
  }
  return $post;
}

/** Recipient photos shown on the agent sign-up page (owner uploads them in admin → Agent). */
function showcase_files(): array { $f = glob(UPLOAD_DIR . '/_site/showcase-*.jpg') ?: []; sort($f); return array_map('basename', $f); }
/** Saves an uploaded image as a JPEG no wider than $max px (falls back to the original if GD is missing). */
function save_jpeg(string $tmp, string $dest, int $max = 900): void {
  if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring((string)file_get_contents($tmp)))) {
    $w = imagesx($src); $h = imagesy($src); $k = min(1, $max / max($w, $h));
    $dst = imagecreatetruecolor((int)round($w * $k), (int)round($h * $k));
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    if (!imagejpeg($dst, $dest, 82)) fail('Simpan gambar gagal.', 500);
    return;
  }
  if (!move_uploaded_file($tmp, $dest)) fail('Simpan gambar gagal.', 500);
}

// ---------- wishcard + polaroids sent in after the order (tracking page and admin) ----------
const WISH_NONE = '(Ayat wishcard belum diterima)';
const WISH_EDITS = 3; // edits the buyer gets after the first time the wish is written
/** What the buyer may still change on the tracking page. Locked once the item is ticked in production or the order has left. */
function buyer_edit(array $o): array {
  $p = jdec($o['prep'] ?? null);
  $gone = in_array($o['status'], ['batal', 'dalam_penghantaran', 'sampai', 'selesai'], true);
  $first = $o['wish'] === WISH_NONE;
  $left = $first ? WISH_EDITS : max(0, WISH_EDITS - (int)($o['wish_edits'] ?? 0));
  $max = (int)$o['photo_count'];
  return ['wish' => !$gone && !isset($p['wishcard']) && ($first || $left > 0), 'wish_first' => $first, 'wish_left' => $left,
    'photo' => !$gone && $max > 0 && !isset($p['polaroid']), 'photo_max' => $max];
}
/** Adds uploaded polaroids to an order (never more than it paid for). Returns the new list. */
function add_photos(array $o, array $files): array {
  $have = jdec($o['photos']); $room = (int)$o['photo_count'] - count($have);
  if ($room <= 0) fail('Semua ' . (int)$o['photo_count'] . ' gambar polaroid dah ada. Padam satu dulu untuk tukar.');
  if (count($files) > $room) fail("Boleh tambah $room gambar lagi sahaja.");
  $dir = UPLOAD_DIR . '/' . $o['code']; if (!is_dir($dir) && !mkdir($dir, 0755, true)) fail('Simpan gambar gagal.', 500);
  foreach ($files as [$tmp, $ext]) {
    $name = 'p-' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($tmp, "$dir/$name")) fail('Simpan gambar gagal.', 500);
    $have[] = $name;
  }
  q('UPDATE orders SET photos=? WHERE id=?', [json_encode($have), $o['id']]);
  return $have;
}
function remove_photo(array $o, string $f): array {
  $have = jdec($o['photos']); $f = basename($f);
  if (!in_array($f, $have, true)) fail('Gambar tidak dijumpai.', 404);
  $have = array_values(array_diff($have, [$f]));
  @unlink(UPLOAD_DIR . '/' . $o['code'] . '/' . $f);
  q('UPDATE orders SET photos=? WHERE id=?', [json_encode($have), $o['id']]);
  return $have;
}

function set_setting(string $k, ?string $v): void { q('DELETE FROM settings WHERE k=?', [$k]); if ($v !== null) q('INSERT INTO settings (k,v) VALUES (?,?)', [$k, $v]); }

/** Everything that happens once an order becomes paid (gateway, sales confirmation or keyed in as paid). */
function after_paid(int $id): void {
  $o = q('SELECT * FROM orders WHERE id=?', [$id])->fetch(); if (!$o) return;
  notify_paid($o);
  sync_commissions();
  wa_order($o, 'paid');
}

// ---------- WhatsApp notifications through Murpati (https://murpati.com/developers/api) ----------
// Owner sets the API key, sending number and recipients in admin → Pengguna. Messages are queued and sent after the
// page has answered, so a slow WhatsApp API never delays the customer, the payment webhook or the admin screen.
const UNPAID_MIN = 5; // minutes before an unpaid website order is followed up
const WA_TYPES = ['team_paid' => 'Team: order baru dibayar', 'team_unpaid' => 'Team: order website belum dibayar selepas 5 minit (untuk follow up)',
  'buyer_unpaid' => 'Pembeli: peringatan bayar selepas 5 minit (dengan link bayaran)', 'buyer_paid' => 'Pembeli: bayaran diterima', 'buyer_otw' => 'Pembeli: hadiah dalam perjalanan',
  'buyer_done' => 'Pembeli: hadiah dah sampai (dengan link gambar penerima)', 'agent_comm' => 'Agent: komisen baru', 'agent_payout' => 'Agent: komisen dibayar', 'agent_welcome' => 'Agent: selamat datang selepas daftar'];
function wa_cfg(): array {
  static $c = null; if ($c !== null) return $c;
  $on = json_decode((string)setting('wa_on', ''), true);
  return $c = ['key' => (string)setting('wa_key', ''), 'session' => (string)setting('wa_session', ''),
    'team' => array_values(array_filter(array_map(fn($x) => wa_num($x), explode(',', (string)setting('wa_team', WA_MAIN))))),
    'on' => array_merge(array_fill_keys(array_keys(WA_TYPES), true), is_array($on) ? $on : [])]; // new message types start switched on
}
function wa_num(string $p): string { $d = digits($p); if ($d === '') return ''; if (str_starts_with($d, '60')) return $d; return '60' . ltrim($d, '0'); }
function wa_ready(string $type): bool { $c = wa_cfg(); return $c['key'] !== '' && $c['session'] !== '' && !empty($c['on'][$type]); }
$GLOBALS['WA_Q'] = [];
function wa_queue(string $to, string $msg): void {
  $to = wa_num($to); if (strlen($to) < 10) return;
  if (!$GLOBALS['WA_Q']) register_shutdown_function('wa_flush');
  $GLOBALS['WA_Q'][] = [$to, $msg];
}
function wa_flush(): void {
  $q = $GLOBALS['WA_Q']; $GLOBALS['WA_Q'] = []; if (!$q) return;
  if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
  foreach ($q as [$to, $msg]) { try { wa_send($to, $msg); } catch (Throwable $e) { error_log('100blooms wa: ' . $e->getMessage()); } }
}
/** Sends one text now. Throws on failure; records the latest result for the admin screen. */
function wa_send(string $to, string $msg): array {
  $c = wa_cfg(); if ($c['key'] === '' || $c['session'] === '') throw new RuntimeException('WhatsApp belum disediakan.');
  [$code, $j, $raw] = wa_http('POST', '/v1/messages/send', ['session_id' => $c['session'], 'to' => wa_num($to), 'message' => $msg]);
  $ok = $code < 400 && !empty($j['success']);
  set_setting('wa_last', json_encode(['at' => date('Y-m-d H:i:s'), 'to' => substr(wa_num($to), 0, -4) . '****', 'ok' => $ok, 'error' => $ok ? null : ($j['error'] ?? "HTTP $code " . mb_substr($raw, 0, 120))], JSON_UNESCAPED_UNICODE));
  if (!$ok) throw new RuntimeException('Murpati: ' . ($j['error'] ?? "HTTP $code"));
  return $j;
}
function wa_http(string $method, string $path, ?array $body = null, ?string $key = null): array {
  if (defined('WA_MOCK') && WA_MOCK) { file_put_contents(WA_MOCK, json_encode([$method, $path, $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); return [200, ['success' => true, 'sessions' => [['session_id' => 'sess_test', 'phone_number' => '601165862584', 'pipeline' => 'unofficial', 'status' => 'connected']]], '']; }
  $ch = curl_init('https://api.murpati.com' . $path);
  $h = ['X-API-Key: ' . ($key ?? wa_cfg()['key']), 'Accept: application/json'];
  $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6];
  if ($method === 'POST') { $h[] = 'Content-Type: application/json'; $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); }
  $opt[CURLOPT_HTTPHEADER] = $h; curl_setopt_array($ch, $opt);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($r === false) throw new RuntimeException('Murpati tidak dapat dihubungi: ' . $err);
  return [$code, json_decode((string)$r, true) ?: [], (string)$r];
}
/** Order events → WhatsApp. Each (order, event) is sent at most once. Never mentions the delivery provider. */
function wa_order(array $o, string $event): void {
  try {
    $log = jdec($o['wa_log'] ?? null); $sent = [];
    $C = catalog(); $M = product_meta();
    $track = SITE_URL . '/semak/?c=' . rawurlencode($o['code']) . '&k=' . $o['token'];
    $first = explode(' ', trim($o['b_name']))[0];
    $day = fmt_day($o['del_date']); $slot = $o['del_type'] === 'spec' ? 'Masa khusus ' . $o['del_time'] : (DELIVERY[$o['del_type']]['label'] ?? '');
    $need = [];
    if ($o['wish'] === WISH_NONE) $need[] = 'tulis ayat wishcard';
    $miss = (int)$o['photo_count'] - count(jdec($o['photos'])); if ($miss > 0) $need[] = "upload $miss gambar polaroid";
    $add = function (string $key, string $type, string $to, string $msg) use (&$log, &$sent) {
      if (isset($log[$key]) || !wa_ready($type)) return;
      wa_queue($to, $msg); $log[$key] = date('Y-m-d H:i:s'); $sent[] = $key;
    };
    if ($event === 'paid') {
      $bal = $C['bal'][$o['balloon']] ?? $o['balloon']; $bc = $M['bal'][$o['balloon']]['code'] ?? '';
      $ag = $o['agent_ref'] ? q('SELECT id,name,phone FROM agents WHERE id=?', [(int)$o['agent_ref']])->fetch() : null;
      $team = "🛎️ *Order dibayar* · {$o['code']}\n💰 RM" . number_format((float)$o['total'], 2) . ' · ' . ($o['pay_channel'] ?: '-')
        . "\n🚚 $day · $slot\n💐 " . ($C['bq'][$o['bouquet']] ?? $o['bouquet']) . ($o['bq_note'] ? " ({$o['bq_note']})" : '') . "\n🎈 " . ($bc ? "$bc - " : '') . $bal . "\n🍰 {$o['cake']}"
        . "\n👤 Untuk {$o['r_name']} ({$o['r_post']})\n🧾 Pembeli {$o['b_name']} · " . wa_num($o['b_phone'])
        . ($ag ? "\n🤝 Agent #{$ag['id']} {$ag['name']}" : '') . ($o['voucher'] ? "\n🎟️ Voucher {$o['voucher']}" : '')
        . ($need ? "\n⚠️ Belum: " . implode(', ', $need) : '') . "\n\n" . SITE_URL . '/admin/';
      foreach (wa_cfg()['team'] as $i => $t) $add("team$i", 'team_paid', $t, $team);
      $add('buyer_paid', 'buyer_paid', $o['b_phone'], "Hai $first 🌸\n\nTerima kasih! Bayaran untuk order *{$o['code']}* (RM" . number_format((float)$o['total'], 2) . ") dah kami terima.\n\n"
        . "💐 Hadiah untuk *{$o['r_name']}*\n🚚 $day · $slot\n\n"
        . ($need ? '📝 Jangan lupa ' . implode(' dan ', $need) . " di link bawah ya, supaya kami boleh siapkan hadiah.\n\n" : '')
        . "Semak status order:\n$track\n\n— 100 Blooms");
      if ($ag) {
        $c = q('SELECT amount FROM commissions WHERE order_id=?', [$o['id']])->fetchColumn();
        if ($c !== false) { $due = agent_money((int)$ag['id'])['due'];
          $add('agent_comm', 'agent_comm', $ag['phone'], "Tahniah {$ag['name']}! 🎉\n\nOrder *{$o['code']}* dari link awak dah dibayar.\n💰 Komisen: *RM" . number_format((float)$c, 2) . "*\n💼 Baki komisen: RM" . number_format($due, 2)
            . "\n\nKomisen dibayar setiap Jumaat bila baki RM" . number_format(payout_min(), 0) . " ke atas.\n" . SITE_URL . '/agent/'); }
      }
    } elseif ($event === 'unpaid') {
      $bw = wa_num($o['b_phone']); $C2 = $C['bq'][$o['bouquet']] ?? $o['bouquet'];
      $ag = $o['agent_ref'] ? q('SELECT id,name FROM agents WHERE id=?', [(int)$o['agent_ref']])->fetch() : null;
      $team = "⏳ *Order belum dibayar* · {$o['code']}\n(" . UNPAID_MIN . " minit selepas submit)\n💰 RM" . number_format((float)$o['total'], 2)
        . "\n🧾 {$o['b_name']} · $bw\n🚚 $day · $slot\n💐 $C2 untuk {$o['r_name']} ({$o['r_post']})" . ($ag ? "\n🤝 Agent #{$ag['id']} {$ag['name']}" : '')
        . "\n\nFollow up pembeli: https://wa.me/$bw\n" . SITE_URL . '/admin/';
      foreach (wa_cfg()['team'] as $i => $t) $add("unpaid_team$i", 'team_unpaid', $t, $team);
      $pay = $o['bill_code'] ? (pay_url($o['bill_code']) ?: $track) : $track;
      $add('buyer_unpaid', 'buyer_unpaid', $o['b_phone'], "Hai $first 🌸\n\nOrder *{$o['code']}* (RM" . number_format((float)$o['total'], 2) . ") untuk *{$o['r_name']}* dah kami simpan, tapi bayaran belum kami terima.\n\n"
        . "Kalau ada masalah masa bayar tadi, boleh sambung bayar di sini:\n$pay\n\nAtau balas mesej ni, team kami sedia bantu 😊\n— 100 Blooms");
    } elseif ($event === 'otw') {
      $add('buyer_otw', 'buyer_otw', $o['b_phone'], "Hai $first 🚗\n\nHadiah untuk *{$o['r_name']}* sedang dalam perjalanan bersama runner kami 💐\n\nSemak status:\n$track\n\n— 100 Blooms");
    } elseif ($event === 'delivered') {
      $add('buyer_done', 'buyer_done', $o['b_phone'], "Hai $first 🎉\n\nHadiah untuk *{$o['r_name']}* dah selamat sampai! 🌸\n\nGambar penerima masa hadiah sampai boleh dilihat di sini:\n$track\n\nTerima kasih kerana memilih 100 Blooms 💕\n— 100 Blooms");
    }
    if ($sent) q('UPDATE orders SET wa_log=? WHERE id=?', [json_encode($log), $o['id']]);
  } catch (Throwable $e) { error_log('100blooms wa_order: ' . $e->getMessage()); }
}
/** Background jobs, run after a response at most once a minute (also callable from api/cron.php via Plesk Scheduled Tasks). */
function cron_tick(bool $force = false): void {
  try {
    $last = (int)setting('cron_at', 0); if (!$force && time() - $last < 60) return;
    set_setting('cron_at', (string)time());
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    unpaid_sweep();
    if ($GLOBALS['WA_Q']) wa_flush();
  } catch (Throwable $e) { error_log('100blooms cron: ' . $e->getMessage()); }
}
/** Website orders still unpaid UNPAID_MIN minutes after submit: re-check the gateway first, then nudge team + buyer once. */
function unpaid_sweep(): void {
  if (!wa_ready('team_unpaid') && !wa_ready('buyer_unpaid')) return;
  $rows = q("SELECT * FROM orders WHERE pay_status<>'paid' AND status<>'batal' AND created_by IS NULL AND created_at<=? AND created_at>=? ORDER BY id LIMIT 20",
    [date('Y-m-d H:i:s', time() - UNPAID_MIN * 60), date('Y-m-d H:i:s', time() - 6 * 3600)])->fetchAll();
  foreach ($rows as $o) {
    $log = jdec($o['wa_log']); if (isset($log['buyer_unpaid']) || isset($log['unpaid_team0'])) continue;
    if (str_contains((string)$o['notes'], 'ORDER UJIAN')) continue;
    if ($o['bill_code']) { // paid but the webhook was missed? then it is not "unpaid"
      try { [$paid, $ref, $ch, $amt] = pay_check($o['bill_code']); if ($paid && mark_paid($o, $ref, $ch, $amt)) continue; } catch (Throwable $e) { error_log('100blooms sweep: ' . $e->getMessage()); }
    }
    wa_order($o, 'unpaid');
  }
}
function wa_agent(array $ag, string $type, string $msg): void { if (wa_ready($type)) wa_queue($ag['phone'], $msg); }
function fmt_day(string $ymd): string {
  $t = strtotime($ymd); $D = ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu']; $Mo = ['Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogos', 'Sep', 'Okt', 'Nov', 'Dis'];
  return $D[(int)date('w', $t)] . ', ' . date('j', $t) . ' ' . $Mo[(int)date('n', $t) - 1];
}

/** Prayer times for $date and the day after: JAKIM e-Solat first, Aladhan (JAKIM method) as a fallback. */
function solat_fetch(string $zone, string $date): ?array {
  $tomorrow = date('Y-m-d', strtotime("$date +1 day"));
  $get = function (string $url, ?array $post = null): ?array {
    if (defined('SOLAT_MOCK') && SOLAT_MOCK) return json_decode((string)file_get_contents(SOLAT_MOCK), true);
    $ch = curl_init($url); $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => '100Blooms/1.0', CURLOPT_HTTPHEADER => ['Accept: application/json']];
    if ($post) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $o); $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($r !== false && $code < 400) ? json_decode((string)$r, true) : null;
  };
  $pick = fn(array $p) => ['fajr' => substr($p['fajr'], 0, 5), 'syuruk' => substr($p['syuruk'], 0, 5), 'dhuhr' => substr($p['dhuhr'], 0, 5), 'asr' => substr($p['asr'], 0, 5), 'maghrib' => substr($p['maghrib'], 0, 5), 'isha' => substr($p['isha'], 0, 5)];
  try {
    $j = $get('https://www.e-solat.gov.my/index.php?r=esolatApi/takwimsolat&period=duration&zone=' . rawurlencode($zone), ['datestart' => $date, 'dateend' => $tomorrow]);
    $days = [];
    foreach ((array)($j['prayerTime'] ?? []) as $p) { $d = DateTimeImmutable::createFromFormat('!d-M-Y', (string)($p['date'] ?? '')); if ($d && !empty($p['fajr'])) $days[$d->format('Y-m-d')] = $pick($p); }
    if (isset($days[$date])) return ['days' => $days, 'source' => 'JAKIM e-Solat'];
  } catch (Throwable $e) { error_log('100blooms solat jakim: ' . $e->getMessage()); }
  try { // fallback: Aladhan with the JAKIM method, at the shop (Jenjarom)
    $days = [];
    foreach ([$date, $tomorrow] as $d) {
      $j = $get('https://api.aladhan.com/v1/timings/' . date('d-m-Y', strtotime($d)) . '?latitude=2.8836&longitude=101.5014&method=17');
      $t = $j['data']['timings'] ?? null; if (!$t) continue;
      $days[$d] = ['fajr' => substr($t['Fajr'], 0, 5), 'syuruk' => substr($t['Sunrise'], 0, 5), 'dhuhr' => substr($t['Dhuhr'], 0, 5), 'asr' => substr($t['Asr'], 0, 5), 'maghrib' => substr($t['Maghrib'], 0, 5), 'isha' => substr($t['Isha'], 0, 5)];
    }
    if (isset($days[$date])) return ['days' => $days, 'source' => 'Aladhan (kaedah JAKIM)'];
  } catch (Throwable $e) { error_log('100blooms solat aladhan: ' . $e->getMessage()); }
  return null;
}

function bank_qr_path(): ?string { foreach (glob(UPLOAD_DIR . '/_site/bank-qr.*') ?: [] as $f) return $f; return null; }
// ---------- product codes / retail prices (set in admin → Stok) ----------
function product_meta(): array {
  static $m = null; if ($m !== null) return $m;
  $m = ['bq' => [], 'bal' => [], 'cake' => []];
  try { foreach (q('SELECT type,item_id,code,price FROM products')->fetchAll() as $r) $m[$r['type']][$r['item_id']] = ['code' => $r['code'], 'price' => $r['price'] !== null ? (float)$r['price'] : null]; } catch (Throwable $e) {}
  return $m;
}
const SLOT_EN = ['free' => '10:00 AM – 6:00 PM', 'A' => '9:00 AM – 12:00 PM', 'B' => '1:00 PM – 4:00 PM', 'C' => '5:00 PM – 8:00 PM'];
/** WhatsApp invoice in the shop's usual layout, priced the same way as the website (Combo Jimat set + add-ons). */
function invoice_text(array $o): string {
  $C = catalog(); $M = product_meta(); $f = fn($n) => number_format((float)$n, 2, '.', '');
  $lines = json_decode((string)$o['price_lines'], true) ?: [];
  $bqName = $C['bq'][$o['bouquet']] ?? $o['bouquet']; $balName = $C['bal'][$o['balloon']] ?? $o['balloon'];
  $balCode = $M['bal'][$o['balloon']]['code'] ?? ''; $bqCode = $M['bq'][$o['bouquet']]['code'] ?? '';
  $cakeTxt = $o['cake'] === OTHER_CAKE ? implode(' / ', json_decode((string)$o['cake_flavours'], true) ?: []) . ' (ikut stok)' : $o['cake'];
  $items = []; $sub = 0;
  $add = function (string $t, float $p, int $n = 1, string $sublines = '') use (&$items, &$sub, $f) { $items[] = "↳ $n x $t (" . $f($p) . " x $n = " . $f($p * $n) . ")" . ($sublines !== '' ? "\n        $sublines" : ''); $sub += $p * $n; };
  $set = ['1 x ' . ($bqCode ? "$bqCode - " : '') . "$bqName Bouquet" . ($o['bq_note'] ? " - {$o['bq_note']}" : ''),
    "1 x Balloon Foil ( " . ($balCode ? "$balCode - " : '') . "$balName )",
    "1 x Secret Recipe Slice Cake ( $cakeTxt )", '1 x Polaroid photo', '1 x Wishcard'];
  $add('Combo Jimat', (float)($lines[0][1] ?? BASE_PRICE), 1, implode("\n        ", $set));
  $fees = 0; $delLabel = ''; $extra = [];
  foreach (array_slice($lines, 1) as [$label, $amt]) { // everything after the set line
    $amt = (float)$amt;
    if (str_starts_with($label, 'Upgrade kek')) $add($label, $amt);
    elseif (str_starts_with($label, 'Kek tambahan')) $add($label, $amt);
    elseif (str_starts_with($label, 'Polaroid tambahan')) { $n = (int)preg_replace('/\D/', '', $label) ?: 1; $add('Polaroid tambahan', EXTRA_PHOTO, $n); }
    elseif (str_starts_with($label, '🎁')) $items[] = '↳ 1 x ' . $label . ' (FREE)';
    elseif (isset(DELIVERY[$o['del_type']]) && str_starts_with($label, DELIVERY[$o['del_type']]['label'])) { $delLabel = $label; $fees += $amt; }
    elseif (str_starts_with($label, 'Caj kawasan')) { $fees += $amt; $extra[] = "$label: RM" . $f($amt); }
    elseif ($amt > 0) $add($label, $amt);
  }
  $disc = round($sub + $fees - (float)$o['total'], 2);
  $delName = ['free' => 'Free Delivery (10am-6pm)', 'A' => 'Slot A (9am-12pm)', 'B' => 'Slot B (1pm-4pm)', 'C' => 'Slot C (5pm-8pm)', 'spec' => 'Masa khusus ' . $o['del_time']][$o['del_type']] ?? $delLabel;
  $date = date('l, j F Y', strtotime($o['del_date'])) . ' (' . ($o['del_type'] === 'spec' ? $o['del_time'] : (SLOT_EN[$o['del_type']] ?? '')) . ')';
  $wish = $o['wish'] === WISH_NONE ? 'not receive yet (boleh isi sendiri di Invoice Link bersama gambar polaroid)' : $o['wish'] . ($o['sender'] ? "\n— " . $o['sender'] : '');
  $track = SITE_URL . '/semak/?c=' . rawurlencode($o['code']) . '&k=' . $o['token'];
  return "[ Invoice No: {$o['code']} ]\n\nHi, I want to order:\n\n" . implode("\n \n", $items) . " \n \nSubtotal: RM" . $f($sub)
    . "\n\nDelivery: $delName \nDelivery Fee: RM" . $f($fees) . ($extra ? "\n" . implode("\n", $extra) : '')
    . ($disc > 0 ? "\n\nDiscount" . ($o['voucher'] ? " ({$o['voucher']})" : '') . ": RM" . $f(-$disc) : '') . "\n\nTotal:\nRM" . $f($o['total'])
    . "\n \nName:\n{$o['b_name']}\n\nPhone Number:\n" . '60' . norm_phone($o['b_phone']) . "\n\nAddress:\n\n{$o['r_addr']}\n\nPostcode:\n{$o['r_post']}"
    . "\n\nReceiver Name:\n{$o['r_name']}\n\nReceiver Phone Number:\n{$o['r_phone']}\n\nWishcard (FREE):\n$wish\n\nDate & Time Slot:\n$date"
    . "\n\n[ Invoice Link: $track ]";
}

// ---------- vouchers ----------
const VOUCHER_TYPES = ['rm' => 'Diskaun RM', 'pct' => 'Diskaun %', 'photo' => 'Polaroid percuma', 'gift' => 'Hadiah percuma'];
function norm_phone(string $p): string { $d = digits($p); if (str_starts_with($d, '60')) $d = '0' . substr($d, 2); return ltrim($d, '0'); }
function voucher_find(string $code): ?array {
  $code = strtoupper(preg_replace('/\s+/', '', $code));
  if ($code === '') return null;
  return q('SELECT * FROM vouchers WHERE code=?', [$code])->fetch() ?: null;
}
/** Why this voucher can't be used right now, or null if it can. */
function voucher_block(array $v, string $phone, bool $viaAgent): ?string {
  $today = date('Y-m-d');
  if (!(int)$v['active']) return 'Voucher ini tidak aktif.';
  if ($v['starts_on'] && $today < $v['starts_on']) return 'Voucher ini belum boleh digunakan.';
  if ($v['ends_on'] && $today > $v['ends_on']) return 'Voucher ini dah tamat tempoh.';
  if ($viaAgent && !(int)$v['agent_ok']) return 'Voucher ini tak boleh digunakan bersama link agent.';
  if ($v['max_uses'] !== null && (int)q("SELECT COUNT(*) FROM orders WHERE voucher=? AND pay_status='paid'", [$v['code']])->fetchColumn() >= (int)$v['max_uses'])
    return 'Voucher ini dah habis ditebus.';
  if ((int)$v['once_per_phone'] && $phone !== '') {
    foreach (q("SELECT b_phone FROM orders WHERE voucher=? AND pay_status='paid'", [$v['code']])->fetchAll() as $r)
      if (norm_phone($r['b_phone']) === norm_phone($phone)) return 'Nombor WhatsApp ini dah pernah guna voucher ini.';
  }
  return null;
}
function voucher_label(array $v): string {
  switch ($v['type']) {
    case 'rm': return 'Diskaun RM' . rtrim(rtrim(number_format((float)$v['value'], 2, '.', ''), '0'), '.');
    case 'pct': return 'Diskaun ' . rtrim(rtrim(number_format((float)$v['value'], 2, '.', ''), '0'), '.') . '%' . ($v['max_off'] ? ' (maks RM' . (float)$v['max_off'] . ')' : '');
    case 'photo': return (int)$v['value'] . ' polaroid tambahan percuma';
    default: return 'Hadiah percuma: ' . $v['gift_name'];
  }
}
/** Applies the voucher to the price lines. Returns [lines, discount] or an error string. Discounts apply to the set price only, never delivery. */
function voucher_apply(array $L, array $v, int $photoCount, float $setSubtotal) {
  $tag = ' (voucher ' . $v['code'] . ')';
  switch ($v['type']) {
    case 'rm': $off = min((float)$v['value'], $setSubtotal - 1); $L[] = ['Voucher ' . $v['code'], -$off]; return [$L, $off];
    case 'pct': $off = round($setSubtotal * (float)$v['value'] / 100, 2); if ($v['max_off']) $off = min($off, (float)$v['max_off']); $off = min($off, $setSubtotal - 1);
      $L[] = ['Voucher ' . $v['code'] . ' (' . (float)$v['value'] . '%)', -$off]; return [$L, $off];
    case 'photo':
      $n = max(1, (int)$v['value']);
      if ($photoCount < 2) return 'Voucher ini beri ' . $n . ' polaroid tambahan percuma. Upload sekurang-kurangnya ' . ($n + 1) . ' gambar untuk guna.';
      $free = min($n, $photoCount - 1); $off = $free * EXTRA_PHOTO;
      $L[] = ['Polaroid percuma × ' . $free . $tag, -$off]; return [$L, $off];
    default: $L[] = ['🎁 ' . $v['gift_name'] . $tag, 0]; return [$L, 0.0];
  }
}

// ---------- order validation + pricing (authoritative; the app only previews) ----------
/** $opts (admin only): override → skip stock, closing-time, closed-date and zone checks; adjust → [amount, label] extra price line. */
function build_order(array $in, int $photoCount, ?DateTimeImmutable $now = null, array $opts = []): array {
  $ov = !empty($opts['override']);
  $now = $now ?? new DateTimeImmutable('now');
  $C = catalog(); $off = off_items(); $E = [];
  if (!empty($opts['manual'])) { // sales form does not ask these; the card is addressed to the receiver
    $in['occ'] = isset($C['occ'][$in['occ'] ?? '']) ? $in['occ'] : 'saja'; $in['rel'] = isset($C['rel'][$in['rel'] ?? '']) ? $in['rel'] : 'kawan';
    $in['gender'] = in_array($in['gender'] ?? '', ['l', 'p'], true) ? $in['gender'] : 'p';
    if (trim((string)($in['call'] ?? '')) === '') $in['call'] = mb_substr(trim((string)($in['rname'] ?? '')), 0, 24) ?: 'Penerima';
    if (trim((string)($in['wish'] ?? '')) === '') $in['wish'] = WISH_NONE;
    if (isset($opts['photo_count'])) $photoCount = max($photoCount, min(MAX_PHOTOS, (int)$opts['photo_count']));
  }
  $occ = (string)($in['occ'] ?? ''); $rel = (string)($in['rel'] ?? '');
  if (!isset($C['occ'][$occ])) $E[] = 'Momen tidak sah.';
  if (!isset($C['rel'][$rel])) $E[] = 'Hubungan tidak sah.';
  $gender = in_array($in['gender'] ?? '', ['l','p'], true) ? $in['gender'] : '';
  if ($gender === '') $E[] = 'Jantina penerima belum dipilih.';
  $call = str_in($in['call'] ?? '', 24); if ($call === '') $E[] = 'Panggilan belum diisi.';

  $bq = (string)($in['bq'] ?? ''); if (!isset($C['bq'][$bq]) || (!$ov && in_array($bq, $off['bq'], true))) $E[] = 'Bouquet tidak tersedia.';
  $bal = (string)($in['bal'] ?? ''); if (!isset($C['bal'][$bal]) || (!$ov && in_array($bal, $off['bal'], true))) $E[] = 'Balloon tidak tersedia.';
  $cakes = array_merge($C['cake_free'], $C['cake_up']);
  $cake = (string)($in['cake'] ?? '');
  if (!in_array($cake, $cakes, true) || (!$ov && in_array($cake, $off['cake'], true))) $E[] = 'Perisa kek tidak tersedia.';
  $otherFl = [];
  if ($cake === OTHER_CAKE) {
    foreach (array_slice((array)($in['otherFl'] ?? []), 0, 5) as $f) { $f = str_in($f, 40); if ($f !== '') $otherFl[] = $f; }
    if (!$otherFl) $E[] = 'Tulis sekurang-kurangnya satu perisa pilihan.';
  }
  $extra = []; $extraN = 0;
  foreach ((array)($in['extraCakes'] ?? []) as $k => $n) {
    $n = (int)$n; if ($n <= 0) continue;
    if (!in_array($k, $cakes, true)) { $E[] = 'Perisa kek tambahan tidak sah.'; continue; }
    $extra[$k] = $n; $extraN += $n;
  }
  if ($extraN > MAX_EXTRA_CAKE) $E[] = 'Maksimum ' . MAX_EXTRA_CAKE . ' kek tambahan.';
  if ($photoCount > MAX_PHOTOS) $E[] = 'Maksimum ' . MAX_PHOTOS . ' gambar.';

  $wish = str_in($in['wish'] ?? '', 320); if ($wish === '') $E[] = 'Ayat wishcard belum diisi.';
  $sender = str_in($in['sender'] ?? '', 40);
  $rname = str_in($in['rname'] ?? '', 80); if ($rname === '') $E[] = 'Nama penerima belum diisi.';
  $rphone = str_in($in['rphone'] ?? '', 20); if (strlen(digits($rphone)) < 9) $E[] = 'Nombor telefon penerima tidak sah.';
  $raddr = str_in($in['raddr'] ?? '', 300); if (mb_strlen($raddr) < 8) $E[] = 'Alamat penerima terlalu pendek.';
  $rpost = digits((string)($in['rpost'] ?? '')); $zone = zone_of($rpost);
  if (!preg_match('/^\d{5}$/', $rpost)) $E[] = 'Poskod mesti 5 digit.';
  elseif ($zone === 'out' && !$ov) $E[] = 'Poskod di luar kawasan penghantaran.';
  $bname = str_in($in['bname'] ?? '', 80); if ($bname === '') $E[] = 'Nama awak belum diisi.';
  $bphone = str_in($in['bphone'] ?? '', 20); if (strlen(digits($bphone)) < 9) $E[] = 'Nombor WhatsApp awak tidak sah.';

  // delivery date + window
  $date = (string)($in['date'] ?? ''); $del = (string)($in['del'] ?? ''); $time = (string)($in['time'] ?? '');
  $today = $now->format('Y-m-d'); $hr = (int)$now->format('G') + (int)$now->format('i') / 60;
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
  if (!$d || $d->format('Y-m-d') !== $date || $date < $today) $E[] = 'Tarikh penghantaran tidak sah.';
  elseif ($date > $now->modify('+120 days')->format('Y-m-d')) $E[] = 'Tarikh terlalu jauh. Maksimum 120 hari dari hari ini.';
  elseif (!$ov && in_array($date, blocked_dates(), true)) $E[] = 'Tarikh ini penuh / ditutup. Sila pilih tarikh lain.';
  if (!isset(DELIVERY[$del])) $E[] = 'Pilihan penghantaran tidak sah.';
  else {
    $isToday = $date === $today;
    if (!$ov && $isToday && DELIVERY[$del]['close'] !== null && $hr >= DELIVERY[$del]['close']) $E[] = 'Slot ini dah ditutup untuk hari ini.';
    if ($del === 'spec') {
      if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) $E[] = 'Masa khusus belum dipilih.';
      else {
        $t = (int)$m[1] + (int)$m[2] / 60;
        if ($t < 9 || $t > 22) $E[] = 'Masa khusus hanya antara 9 pagi hingga 10 malam.';
        elseif (!$ov && $isToday && $t < $hr + 5) $E[] = 'Masa khusus perlu ditempah sekurang-kurangnya 5 jam awal.';
      }
    } else $time = '';
  }
  if ($E) return ['errors' => $E];

  // price lines
  $L = [['Combo Jimat (bouquet ' . $C['bq'][$bq] . ')', BASE_PRICE]];
  if (in_array($cake, $C['cake_up'], true)) $L[] = ['Upgrade kek: ' . $cake, CAKE_UPGRADE];
  if ($extraN) $L[] = ['Kek tambahan × ' . $extraN . ' (' . implode(', ', array_map(fn($k, $n) => "$k × $n", array_keys($extra), $extra)) . ')', $extraN * EXTRA_CAKE];
  if ($photoCount > 1) $L[] = ['Polaroid tambahan × ' . ($photoCount - 1), ($photoCount - 1) * EXTRA_PHOTO];
  $L[] = [DELIVERY[$del]['label'] . ($del === 'spec' ? ' ' . $time : ''), DELIVERY[$del]['fee']];
  if ($zone === 'plus20') $L[] = ['Caj kawasan (Rawang / Kuang / Selayang / Sg Buloh)', 20];
  $setSub = array_sum(array_column(array_slice($L, 0, count($L) - ($zone === 'plus20' ? 2 : 1)), 1)); // everything before delivery
  $vcode = null; $discount = 0.0;
  if (trim((string)($in['voucher'] ?? '')) !== '') {
    $v = voucher_find((string)$in['voucher']);
    if (!$v) return ['errors' => ['Kod voucher tidak sah.']];
    $viaAgent = agent_from_ref($in['ref'] ?? '') !== null;
    if ($why = voucher_block($v, $bphone, $viaAgent)) return ['errors' => [$why]];
    $r = voucher_apply($L, $v, $photoCount, $setSub);
    if (is_string($r)) return ['errors' => [$r]];
    [$L, $discount] = $r; $vcode = $v['code'];
  }
  if (!empty($opts['adjust']) && (float)$opts['adjust'][0] != 0) $L[] = [str_in($opts['adjust'][1] ?: 'Pelarasan harga', 80), round((float)$opts['adjust'][0], 2)];
  $total = round(array_sum(array_column($L, 1)), 2);
  if ($total < 1) return ['errors' => ['Jumlah mesti sekurang-kurangnya RM1.']];

  return ['order' => [
    'occasion' => $occ, 'relation' => $rel, 'gender' => $gender, 'call_name' => $call,
    'bouquet' => $bq, 'balloon' => $bal, 'cake' => $cake, 'cake_flavours' => json_encode($otherFl, JSON_UNESCAPED_UNICODE),
    'extra_cakes' => json_encode((object)$extra, JSON_UNESCAPED_UNICODE), 'photo_count' => $photoCount,
    'wish' => $wish, 'sender' => $sender,
    'r_name' => $rname, 'r_phone' => $rphone, 'r_addr' => $raddr, 'r_post' => $rpost, 'zone' => $zone,
    'del_date' => $date, 'del_type' => $del, 'del_time' => $time,
    'b_name' => $bname, 'b_phone' => $bphone,
    'price_lines' => json_encode($L, JSON_UNESCAPED_UNICODE), 'total' => $total,
    'voucher' => $vcode, 'discount' => $discount,
    'bq_note' => !empty($opts['manual']) ? (str_in($in['bq_note'] ?? '', 100) ?: null) : null,
  ]];
}

/** Validates uploaded photo[] files. Returns [[tmp, ext], …] or fails the request. */
function collect_photos(): array {
  $files = [];
  if (!empty($_FILES['photo']['name']) && is_array($_FILES['photo']['name'])) {
    foreach ($_FILES['photo']['name'] as $i => $_) {
      $err = $_FILES['photo']['error'][$i]; if ($err === UPLOAD_ERR_NO_FILE) continue;
      if ($err !== UPLOAD_ERR_OK) fail('Satu gambar gagal dimuat naik (saiz terlalu besar?). Cuba gambar lain.');
      $tmp = $_FILES['photo']['tmp_name'][$i];
      if ($_FILES['photo']['size'][$i] > 8 * 1024 * 1024) fail('Setiap gambar mesti bawah 8MB.');
      $info = @getimagesize($tmp);
      if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('Fail gambar tidak sah. Guna JPG atau PNG.');
      $files[] = [$tmp, image_type_to_extension($info[2], false)];
    }
  }
  return $files;
}
function save_photos(string $code, array $files): array {
  $saved = [];
  if (!$files) return $saved;
  $dir = UPLOAD_DIR . '/' . $code;
  if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Tak dapat cipta folder uploads');
  foreach ($files as $i => [$tmp, $ext]) {
    $name = 'p' . ($i + 1) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($tmp, "$dir/$name") && !rename($tmp, "$dir/$name")) throw new RuntimeException('Simpan gambar gagal');
    $saved[] = $name;
  }
  return $saved;
}
function new_code(): string {
  $a = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
  for ($i = 0; $i < 10; $i++) {
    $c = 'HB' . date('ymd') . '-'; for ($j = 0; $j < 4; $j++) $c .= $a[random_int(0, strlen($a) - 1)];
    if (!q('SELECT 1 FROM orders WHERE code=?', [$c])->fetchColumn()) return $c;
  }
  throw new RuntimeException('code');
}

// ---------- ToyyibPay ----------
function tp_base(): string { return TP_SANDBOX ? 'https://dev.toyyibpay.com' : 'https://toyyibpay.com'; }
function tp_post(string $fn, array $fields): string {
  if (defined('TP_MOCK') && TP_MOCK) return tp_mock($fn, $fields);
  $ch = curl_init(tp_base() . '/index.php/api/' . $fn);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
  $r = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
  if ($r === false) throw new RuntimeException('ToyyibPay tidak dapat dihubungi: ' . $err);
  return (string)$r;
}
function tp_create_bill(array $o): string {
  $f = [
    'userSecretKey' => TP_SECRET, 'categoryCode' => TP_CATEGORY,
    'billName' => 'Combo Jimat 100 Blooms',
    'billDescription' => 'Order ' . str_replace('-', ' ', $o['code']) . ' untuk ' . preg_replace('/[^A-Za-z0-9 ]/', '', mb_substr($o['r_name'], 0, 40)),
    'billPriceSetting' => 1, 'billPayorInfo' => 0, 'billAmount' => (int)round($o['total'] * 100),
    'billReturnUrl' => SITE_URL . '/?r=' . rawurlencode($o['code']) . '&k=' . $o['token'],
    'billCallbackUrl' => SITE_URL . '/api/callback.php',
    'billExternalReferenceNo' => $o['code'], 'billPhone' => digits($o['b_phone']), 'billTo' => mb_substr($o['b_name'], 0, 60),
    'billExpiryDays' => 1, 'billPaymentChannel' => 0,
  ];
  if (TP_DUITNOW_QR) { $f['enableDuitNowQR'] = 1; $f['chargeDuitNowQR'] = 0; }
  $r = tp_post('createBill', $f);
  $j = json_decode($r, true);
  if (is_array($j) && isset($j[0]['BillCode'])) return $j[0]['BillCode'];
  throw new RuntimeException('ToyyibPay createBill gagal: ' . mb_substr($r, 0, 200));
}
function tp_pay_url(string $bill): string { return tp_base() . '/' . $bill; }
/** Ask ToyyibPay directly whether a bill has a successful payment. Returns [paid(bool), ref, channel, amount]. */
function tp_check(string $bill): array {
  $r = tp_post('getBillTransactions', ['billCode' => $bill]);
  $j = json_decode($r, true);
  if (!is_array($j)) return [false, '', '', 0];
  foreach ($j as $t) if ((string)($t['billpaymentStatus'] ?? '') === '1')
    return [true, (string)($t['billpaymentInvoiceNo'] ?? ''), (string)($t['billpaymentChannel'] ?? ''), (float)($t['billpaymentAmount'] ?? 0)];
  return [false, '', '', 0];
}
function tp_mock(string $fn, array $f): string { // local testing only (TP_MOCK in config)
  $dir = sys_get_temp_dir() . '/tpmock'; @mkdir($dir);
  if ($fn === 'createBill') { $b = 'mock' . bin2hex(random_bytes(3)); file_put_contents("$dir/$b", json_encode(['paid' => 0, 'amt' => $f['billAmount'] / 100, 'ref' => $f['billExternalReferenceNo']])); return json_encode([['BillCode' => $b]]); }
  if ($fn === 'getBillTransactions') { $d = json_decode(@file_get_contents("$dir/" . basename($f['billCode'])) ?: '{}', true);
    return json_encode([['billpaymentStatus' => !empty($d['paid']) ? '1' : '2', 'billpaymentInvoiceNo' => 'TP' . $f['billCode'], 'billpaymentChannel' => 'DuitNow QR', 'billpaymentAmount' => (string)($d['amt'] ?? 0)]]); }
  return '[]';
}

// ---------- HitPay ----------
function hp_sandbox(): bool { return filter_var(HP_SANDBOX, FILTER_VALIDATE_BOOLEAN); } // tolerates 'false' typed with quotes
function hp_base(): string { return hp_sandbox() ? 'https://api.sandbox.hit-pay.com/v1' : 'https://api.hit-pay.com/v1'; }
function hp_req(string $method, string $path, array $fields = []): array {
  if (defined('HP_MOCK') && HP_MOCK) return hp_mock($method, $path, $fields);
  $ch = curl_init(hp_base() . $path);
  $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => ['X-BUSINESS-API-KEY: ' . HP_API_KEY, 'X-Requested-With: XMLHttpRequest', 'Accept: application/json']];
  if ($method === 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = http_build_query($fields); }
  curl_setopt_array($ch, $opt);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($r === false) throw new RuntimeException('HitPay tidak dapat dihubungi: ' . $err);
  $j = json_decode((string)$r, true);
  if ($code >= 400 || !is_array($j)) throw new RuntimeException("HitPay $code: " . mb_substr((string)$r, 0, 300));
  return $j;
}
/** Create a hosted payment request. Returns [payment_request_id, checkout_url]. */
function hp_create(array $o): array {
  $j = hp_req('POST', '/payment-requests', [
    'amount' => number_format((float)$o['total'], 2, '.', ''), 'currency' => 'MYR',
    'reference_number' => $o['code'], 'purpose' => 'Combo Jimat 100 Blooms ' . $o['code'],
    'name' => mb_substr($o['b_name'], 0, 60),
    'redirect_url' => SITE_URL . '/?r=' . rawurlencode($o['code']) . '&k=' . $o['token'],
  ]);
  if (empty($j['id']) || empty($j['url'])) throw new RuntimeException('HitPay: jawapan tidak lengkap');
  return [$j['id'], $j['url']];
}
/** Ask HitPay whether the payment request was paid. Returns [paid, ref, channel, amount]. */
function hp_check(string $id): array {
  $j = hp_req('GET', '/payment-requests/' . rawurlencode($id));
  foreach ((array)($j['payments'] ?? []) as $p)
    if (in_array($p['status'] ?? '', ['succeeded', 'succeeded_manually'], true))
      return [true, (string)($p['id'] ?? $id), (string)($p['payment_type'] ?? 'HitPay'), (float)($p['amount'] ?? 0)];
  if (($j['status'] ?? '') === 'completed') return [true, $id, 'HitPay', (float)($j['amount'] ?? 0)];
  return [false, '', '', 0];
}
function hp_mock(string $m, string $path, array $f): array { // local testing only (HP_MOCK in config)
  $dir = sys_get_temp_dir() . '/hpmock'; @mkdir($dir);
  if ($m === 'POST') { $id = 'mock-' . bin2hex(random_bytes(6)); file_put_contents("$dir/$id", json_encode(['paid' => 0, 'amt' => $f['amount']])); return ['id' => $id, 'url' => 'https://securecheckout.sandbox.hit-pay.com/mock/' . $id, 'status' => 'pending']; }
  $id = basename($path); $d = json_decode(@file_get_contents("$dir/$id") ?: '{}', true);
  return ['id' => $id, 'url' => 'https://securecheckout.sandbox.hit-pay.com/mock/' . $id, 'status' => !empty($d['paid']) ? 'completed' : 'pending', 'amount' => $d['amt'] ?? '0',
    'payments' => !empty($d['paid']) ? [['id' => 'pay-' . $id, 'status' => 'succeeded', 'amount' => $d['amt'], 'payment_type' => 'duitnow']] : []];
}

// ---------- gateway switch (PAY_GATEWAY in config: 'hitpay' or 'toyyibpay') ----------
function gateway(): string { return defined('PAY_GATEWAY') ? PAY_GATEWAY : 'toyyibpay'; }
function gateway_name(): string { return gateway() === 'hitpay' ? 'HitPay' : 'ToyyibPay'; }
/** Returns [reference stored in bill_code, url to send the customer to]. */
function pay_create(array $o): array {
  if (gateway() === 'hitpay') return hp_create($o);
  $b = tp_create_bill($o); return [$b, tp_pay_url($b)];
}
function pay_check(string $ref): array { return gateway() === 'hitpay' ? hp_check($ref) : tp_check($ref); }
function pay_url(string $ref): ?string {
  if (gateway() !== 'hitpay') return tp_pay_url($ref);
  try { $j = hp_req('GET', '/payment-requests/' . rawurlencode($ref)); return ($j['status'] ?? '') === 'pending' ? ($j['url'] ?? null) : null; }
  catch (Throwable $e) { return null; }
}

/** Mark an order paid exactly once (callback and status check can race). Returns true if this call changed it. */
function mark_paid(array $o, string $ref, string $channel, float $amount): bool {
  if (abs($amount - (float)$o['total']) > 0.009) { error_log("100blooms: amount mismatch for {$o['code']}: $amount vs {$o['total']}"); return false; }
  $n = q("UPDATE orders SET pay_status='paid', status='baru', paid_at=?, pay_ref=?, pay_channel=? WHERE id=? AND pay_status<>'paid'",
    [date('Y-m-d H:i:s'), mb_substr($ref, 0, 60), mb_substr($channel, 0, 30), $o['id']])->rowCount();
  if ($n) after_paid((int)$o['id']);
  return $n > 0;
}
function notify_paid(array $o): void {
  if (!defined('NOTIFY_EMAIL') || !NOTIFY_EMAIL) return;
  $C = catalog();
  $body = "Order baru DIBAYAR: {$o['code']}\nJumlah: RM{$o['total']}\nTarikh hantar: {$o['del_date']} ({$o['del_type']}" . ($o['del_time'] ? " {$o['del_time']}" : '') . ")\n"
    . "Bouquet: " . ($C['bq'][$o['bouquet']] ?? $o['bouquet']) . "\nPenerima: {$o['r_name']}, {$o['r_post']}\nPembeli: {$o['b_name']} {$o['b_phone']}\n\nLihat di " . SITE_URL . "/admin/";
  $from = defined('MAIL_FROM') && MAIL_FROM ? MAIL_FROM : NOTIFY_EMAIL;
  if (!@mail(NOTIFY_EMAIL, "[100 Blooms] Order dibayar {$o['code']} RM{$o['total']}", $body, "From: 100 Blooms <$from>\r\nContent-Type: text/plain; charset=utf-8", '-f' . $from))
    error_log("100blooms: notify mail failed for {$o['code']}");
}
