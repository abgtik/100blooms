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
  return $pdo;
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

// ---------- order validation + pricing (authoritative; the app only previews) ----------
function build_order(array $in, int $photoCount, ?DateTimeImmutable $now = null): array {
  $now = $now ?? new DateTimeImmutable('now');
  $C = catalog(); $off = off_items(); $E = [];
  $occ = (string)($in['occ'] ?? ''); $rel = (string)($in['rel'] ?? '');
  if (!isset($C['occ'][$occ])) $E[] = 'Momen tidak sah.';
  if (!isset($C['rel'][$rel])) $E[] = 'Hubungan tidak sah.';
  $gender = in_array($in['gender'] ?? '', ['l','p'], true) ? $in['gender'] : '';
  if ($gender === '') $E[] = 'Jantina penerima belum dipilih.';
  $call = str_in($in['call'] ?? '', 24); if ($call === '') $E[] = 'Panggilan belum diisi.';

  $bq = (string)($in['bq'] ?? ''); if (!isset($C['bq'][$bq]) || in_array($bq, $off['bq'], true)) $E[] = 'Bouquet tidak tersedia.';
  $bal = (string)($in['bal'] ?? ''); if (!isset($C['bal'][$bal]) || in_array($bal, $off['bal'], true)) $E[] = 'Balloon tidak tersedia.';
  $cakes = array_merge($C['cake_free'], $C['cake_up']);
  $cake = (string)($in['cake'] ?? '');
  if (!in_array($cake, $cakes, true) || in_array($cake, $off['cake'], true)) $E[] = 'Perisa kek tidak tersedia.';
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
  if ($zone === 'out') $E[] = 'Poskod di luar kawasan penghantaran.';
  $bname = str_in($in['bname'] ?? '', 80); if ($bname === '') $E[] = 'Nama awak belum diisi.';
  $bphone = str_in($in['bphone'] ?? '', 20); if (strlen(digits($bphone)) < 9) $E[] = 'Nombor WhatsApp awak tidak sah.';

  // delivery date + window
  $date = (string)($in['date'] ?? ''); $del = (string)($in['del'] ?? ''); $time = (string)($in['time'] ?? '');
  $today = $now->format('Y-m-d'); $hr = (int)$now->format('G') + (int)$now->format('i') / 60;
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
  if (!$d || $d->format('Y-m-d') !== $date || $date < $today) $E[] = 'Tarikh penghantaran tidak sah.';
  elseif ($date > $now->modify('+120 days')->format('Y-m-d')) $E[] = 'Tarikh terlalu jauh. Maksimum 120 hari dari hari ini.';
  elseif (in_array($date, blocked_dates(), true)) $E[] = 'Tarikh ini penuh / ditutup. Sila pilih tarikh lain.';
  if (!isset(DELIVERY[$del])) $E[] = 'Pilihan penghantaran tidak sah.';
  else {
    $isToday = $date === $today;
    if ($isToday && DELIVERY[$del]['close'] !== null && $hr >= DELIVERY[$del]['close']) $E[] = 'Slot ini dah ditutup untuk hari ini.';
    if ($del === 'spec') {
      if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) $E[] = 'Masa khusus belum dipilih.';
      else {
        $t = (int)$m[1] + (int)$m[2] / 60;
        if ($t < 9 || $t > 22) $E[] = 'Masa khusus hanya antara 9 pagi hingga 10 malam.';
        elseif ($isToday && $t < $hr + 5) $E[] = 'Masa khusus perlu ditempah sekurang-kurangnya 5 jam awal.';
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
  $total = array_sum(array_column($L, 1));

  return ['order' => [
    'occasion' => $occ, 'relation' => $rel, 'gender' => $gender, 'call_name' => $call,
    'bouquet' => $bq, 'balloon' => $bal, 'cake' => $cake, 'cake_flavours' => json_encode($otherFl, JSON_UNESCAPED_UNICODE),
    'extra_cakes' => json_encode((object)$extra, JSON_UNESCAPED_UNICODE), 'photo_count' => $photoCount,
    'wish' => $wish, 'sender' => $sender,
    'r_name' => $rname, 'r_phone' => $rphone, 'r_addr' => $raddr, 'r_post' => $rpost, 'zone' => $zone,
    'del_date' => $date, 'del_type' => $del, 'del_time' => $time,
    'b_name' => $bname, 'b_phone' => $bphone,
    'price_lines' => json_encode($L, JSON_UNESCAPED_UNICODE), 'total' => $total,
  ]];
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
  if ($n) notify_paid($o);
  return $n > 0;
}
function notify_paid(array $o): void {
  if (!defined('NOTIFY_EMAIL') || !NOTIFY_EMAIL) return;
  $C = catalog();
  $body = "Order baru DIBAYAR: {$o['code']}\nJumlah: RM{$o['total']}\nTarikh hantar: {$o['del_date']} ({$o['del_type']}" . ($o['del_time'] ? " {$o['del_time']}" : '') . ")\n"
    . "Bouquet: " . ($C['bq'][$o['bouquet']] ?? $o['bouquet']) . "\nPenerima: {$o['r_name']}, {$o['r_post']}\nPembeli: {$o['b_name']} {$o['b_phone']}\n\nLihat di " . SITE_URL . "/admin/";
  @mail(NOTIFY_EMAIL, "[100 Blooms] Order dibayar {$o['code']} RM{$o['total']}", $body, 'From: ' . (defined('MAIL_FROM') ? MAIL_FROM : NOTIFY_EMAIL) . "\r\nContent-Type: text/plain; charset=utf-8");
}
