<?php
// Public, read-only MCP server for AI assistants (ChatGPT, Gemini, Claude…): https://100blooms.my/mcp/
// Streamable HTTP transport, stateless, JSON responses. Exposes only public shop information:
// products & prices, balloons, cakes, add-ons, delivery slots, closed dates, delivery areas.
// No customer, order or sales data is reachable from here.
require __DIR__ . '/../api/_lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, Mcp-Session-Id, Mcp-Protocol-Version');
header('Access-Control-Expose-Headers: Mcp-Session-Id');
header('X-Robots-Tag: noindex');
$m = $_SERVER['REQUEST_METHOD'];
if ($m === 'OPTIONS') { http_response_code(204); exit; }
if ($m === 'DELETE') { http_response_code(204); exit; }
if ($m === 'GET') { // no server-initiated stream; a short description for people who open the URL
  if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream')) { http_response_code(405); header('Allow: POST'); exit; }
  header('Content-Type: text/plain; charset=utf-8');
  echo "100 Blooms MCP server (read-only, public info).\nAdd this URL as a custom connector / app in ChatGPT, Gemini or Claude: " . SITE_URL . "/mcp/\n";
  exit;
}
if ($m !== 'POST') { http_response_code(405); header('Allow: POST, GET, OPTIONS'); exit; }

const MCP_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
$raw = (string)file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) { mcp_send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]); }
$batch = array_is_list($in); $msgs = $batch ? $in : [$in]; $out = [];
foreach ($msgs as $msg) { $r = mcp_handle(is_array($msg) ? $msg : []); if ($r !== null) $out[] = $r; }
if (!$out) { http_response_code(202); exit; } // notifications only
mcp_send($batch ? $out : $out[0]);

function mcp_send($payload): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
function mcp_handle(array $msg): ?array {
  $id = $msg['id'] ?? null; $method = (string)($msg['method'] ?? ''); $p = is_array($msg['params'] ?? null) ? $msg['params'] : [];
  if (!array_key_exists('id', $msg)) return null; // notification (e.g. notifications/initialized)
  $ok = fn($result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
  $err = fn(int $c, string $t) => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $c, 'message' => $t]];
  try {
    switch ($method) {
      case 'initialize':
        $v = in_array($p['protocolVersion'] ?? '', MCP_VERSIONS, true) ? $p['protocolVersion'] : MCP_VERSIONS[0];
        return $ok(['protocolVersion' => $v, 'capabilities' => ['tools' => ['listChanged' => false]],
          'serverInfo' => ['name' => '100blooms', 'title' => '100 Blooms (info awam)', 'version' => '1.0.0'],
          'instructions' => "100 Blooms ialah kedai hadiah kejutan di Jenjarom, Selangor: Combo Jimat RM128 (bouquet + balloon + kek Secret Recipe + polaroid + wishcard) dihantar ke pintu penerima di Lembah Klang. "
            . "Gunakan tools ini untuk jawab soalan tentang produk, harga, slot penghantaran, tarikh tutup dan kawasan hantar. Server ini read-only dan tiada data pelanggan. Untuk order, beri link " . SITE_URL . " atau WhatsApp " . WA_MAIN . "."]);
      case 'ping': return $ok(new stdClass);
      case 'tools/list': return $ok(['tools' => mcp_tools()]);
      case 'tools/call':
        $name = (string)($p['name'] ?? ''); $a = is_array($p['arguments'] ?? null) ? $p['arguments'] : [];
        $fn = 'tool_' . preg_replace('/[^a-z_]/', '', $name);
        if (!in_array($name, array_column(mcp_tools(), 'name'), true) || !function_exists($fn)) return $err(-32602, "Unknown tool: $name");
        $data = $fn($a);
        $text = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $res = ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
        if (is_array($data) && !array_is_list($data)) $res['structuredContent'] = $data;
        return $ok($res);
      case 'resources/list': return $ok(['resources' => []]);
      case 'prompts/list': return $ok(['prompts' => []]);
      default: return $err(-32601, "Method not found: $method");
    }
  } catch (Throwable $e) {
    error_log('100blooms mcp: ' . $e->getMessage());
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => 'Ralat sementara. Cuba lagi sebentar.']], 'isError' => true]];
  }
}

function mcp_tools(): array {
  $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
  $none = ['type' => 'object', 'properties' => new stdClass, 'additionalProperties' => false];
  return [
    ['name' => 'get_shop_info', 'title' => 'Maklumat kedai', 'description' => 'Tentang 100 Blooms: apa yang dijual, apa ada dalam Combo Jimat RM128, cara order, cara bayar, nombor WhatsApp dan link laman web.', 'inputSchema' => $none, 'annotations' => $ro],
    ['name' => 'list_products', 'title' => 'Senarai produk & harga', 'description' => 'Semua bouquet, balloon, perisa kek (percuma / upgrade), add-on bunga & coklat, dan caj tambahan, dengan harga dan status stok semasa.', 'inputSchema' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['all', 'bouquet', 'balloon', 'cake', 'addon'], 'description' => 'Tapis ikut jenis (lalai: all)']], 'additionalProperties' => false], 'annotations' => $ro],
    ['name' => 'get_delivery_options', 'title' => 'Slot & caj penghantaran', 'description' => 'Slot penghantaran (Percuma 10am–6pm, Slot A/B/C, Masa khusus), caj, waktu tutup tempahan hari yang sama, dan tarikh yang ditutup.', 'inputSchema' => $none, 'annotations' => $ro],
    ['name' => 'check_date', 'title' => 'Semak tarikh', 'description' => 'Slot mana yang masih dibuka untuk tarikh tertentu (ikut masa Malaysia sekarang) dan sama ada tarikh itu ditutup.', 'inputSchema' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string', 'description' => 'Tarikh YYYY-MM-DD']], 'required' => ['date'], 'additionalProperties' => false], 'annotations' => $ro],
    ['name' => 'check_postcode', 'title' => 'Semak kawasan hantar', 'description' => 'Sama ada poskod (5 digit) dalam kawasan penghantaran 100 Blooms, dan caj kawasan jika ada.', 'inputSchema' => ['type' => 'object', 'properties' => ['postcode' => ['type' => 'string', 'description' => 'Poskod Malaysia 5 digit, contoh 43800']], 'required' => ['postcode'], 'additionalProperties' => false], 'annotations' => $ro],
    ['name' => 'search', 'title' => 'Cari', 'description' => 'Cari maklumat 100 Blooms (produk, balloon, kek, add-on, penghantaran, kawasan). Pulangkan senarai hasil dengan id untuk tool fetch.', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Kata carian']], 'required' => ['query'], 'additionalProperties' => false], 'annotations' => $ro],
    ['name' => 'fetch', 'title' => 'Ambil butiran', 'description' => 'Ambil butiran penuh satu hasil carian ikut id (daripada tool search).', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'id daripada search']], 'required' => ['id'], 'additionalProperties' => false], 'annotations' => $ro],
  ];
}

// ---------- tools ----------
function tool_get_shop_info(array $a): array {
  return ['name' => '100 Blooms', 'company' => biz_info()['name'], 'based_in' => 'Jenjarom, Selangor, Malaysia', 'website' => SITE_URL,
    'whatsapp' => '+' . WA_MAIN, 'whatsapp_link' => 'https://wa.me/' . WA_MAIN,
    'main_product' => ['name' => 'Combo Jimat', 'price_rm' => BASE_PRICE,
      'includes' => ['1 bouquet bunga (pilih reka bentuk)', '1 balloon foil bertangkai', '1 potong kek Secret Recipe', '1 gambar polaroid', '1 kad ucapan (wishcard) ditulis tangan'],
      'note' => 'Penghantaran percuma 10am–6pm dalam kawasan biasa. Runner ambil gambar penerima semasa hadiah sampai.'],
    'how_to_order' => 'Order di ' . SITE_URL . ' (pilih momen, bouquet, balloon, kek, tarikh & slot, kemudian bayar online). Boleh juga order melalui WhatsApp.',
    'payment' => ['DuitNow QR / FPX online banking / kad (halaman bayaran selamat)', 'Pemindahan bank (order melalui team)'],
    'after_order' => 'Pembeli dapat link Semak Order untuk upload gambar polaroid, tulis ayat wishcard dan ikuti status penghantaran.'];
}
function tool_list_products(array $a): array {
  $t = (string)($a['type'] ?? 'all'); $C = catalog(); $M = product_meta(); $off = off_items(); $out = [];
  $img = fn(string $p) => SITE_URL . '/' . $p;
  if (in_array($t, ['all', 'bouquet'], true)) foreach ($C['bq'] as $k => $n) $out['bouquets'][] = ['id' => $k, 'name' => $n, 'code' => $M['bq'][$k]['code'] ?? null,
    'in_stock' => !in_array($k, $off['bq'], true), 'image' => $img("assets/img/bouquet/$k.webp"), 'price_note' => 'Termasuk dalam Combo Jimat RM' . BASE_PRICE];
  if (in_array($t, ['all', 'balloon'], true)) foreach ($C['bal'] as $k => $n) $out['balloons'][] = ['id' => $k, 'name' => $n, 'code' => $M['bal'][$k]['code'] ?? null,
    'in_stock' => !in_array($k, $off['bal'], true), 'image' => $img("assets/img/balloon/$k.webp")];
  if (in_array($t, ['all', 'cake'], true)) {
    foreach ($C['cake_free'] as $n) $out['cakes'][] = ['name' => $n, 'extra_rm' => 0, 'in_stock' => !in_array($n, $off['cake'], true)];
    foreach ($C['cake_up'] as $n) $out['cakes'][] = ['name' => $n, 'extra_rm' => CAKE_UPGRADE, 'in_stock' => !in_array($n, $off['cake'], true), 'note' => $n === OTHER_CAKE ? 'Pilih perisa ikut stok kedai' : 'Upgrade perisa'];
  }
  if (in_array($t, ['all', 'addon'], true)) foreach (ADDONS as $k => [$n, $p, $u, $g]) $out['addons'][] = ['id' => $k, 'name' => $n, 'group' => $g, 'price_rm' => $p, 'per' => $u, 'how' => 'Tambah melalui WhatsApp / team sales'];
  if ($t === 'all') $out['extras'] = [['name' => 'Kek tambahan (sepotong)', 'price_rm' => EXTRA_CAKE], ['name' => 'Polaroid tambahan (sekeping)', 'price_rm' => EXTRA_PHOTO]];
  $out['currency'] = 'MYR'; $out['set_price_rm'] = BASE_PRICE;
  return $out;
}
function tool_get_delivery_options(array $a): array {
  $slots = [];
  $desc = ['free' => '10:00 AM – 6:00 PM (masa rawak)', 'A' => '9:00 AM – 12:00 PM', 'B' => '1:00 PM – 4:00 PM', 'C' => '5:00 PM – 8:00 PM', 'spec' => 'Pilih jam tepat 9:00 AM – 10:00 PM, tempah sekurang-kurangnya 5 jam awal'];
  foreach (DELIVERY as $k => $d) $slots[] = ['id' => $k, 'name' => $d['label'], 'time' => $desc[$k] ?? '', 'fee_rm' => $d['fee'],
    'same_day_order_before' => $d['close'] !== null ? sprintf('%d:00 %s', $d['close'] > 12 ? $d['close'] - 12 : $d['close'], $d['close'] >= 12 ? 'PM' : 'AM') : null];
  return ['now_malaysia' => date('Y-m-d H:i'), 'slots' => $slots, 'area_surcharge' => ['rm' => 20, 'areas' => 'Rawang / Kuang / Selayang / Sungai Buloh (poskod tertentu)'],
    'closed_dates' => blocked_dates(), 'note' => 'Guna check_date untuk slot yang masih dibuka pada tarikh tertentu, dan check_postcode untuk kawasan.'];
}
function tool_check_date(array $a): array {
  $d = (string)($a['date'] ?? ''); $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
  if (!$dt || $dt->format('Y-m-d') !== $d) return ['error' => 'Format tarikh mesti YYYY-MM-DD.'];
  $today = date('Y-m-d'); $hr = (int)date('G') + (int)date('i') / 60;
  if ($d < $today) return ['date' => $d, 'open' => false, 'reason' => 'Tarikh dah lepas.'];
  if (in_array($d, blocked_dates(), true)) return ['date' => $d, 'open' => false, 'reason' => 'Tarikh ditutup (cuti atau slot penuh).'];
  $open = []; foreach (DELIVERY as $k => $s) if ($d > $today || $s['close'] === null || $hr < $s['close']) $open[] = ['id' => $k, 'name' => $s['label'], 'fee_rm' => $s['fee']];
  return ['date' => $d, 'day' => fmt_day($d), 'now_malaysia' => date('Y-m-d H:i'), 'open' => (bool)$open, 'open_slots' => $open, 'reason' => $open ? null : 'Semua slot untuk hari ini dah ditutup. Pilih esok.'];
}
function tool_check_postcode(array $a): array {
  $pc = preg_replace('/\D/', '', (string)($a['postcode'] ?? ''));
  if (strlen($pc) !== 5) return ['error' => 'Poskod mesti 5 digit.'];
  $z = zone_of($pc);
  return ['postcode' => $pc, 'deliverable' => $z !== 'out', 'zone' => ['free' => 'Zon biru (tiada caj kawasan)', 'plus20' => 'Zon merah (+RM20)', 'out' => 'Luar kawasan'][$z],
    'area_surcharge_rm' => $z === 'plus20' ? 20 : 0, 'note' => $z === 'out' ? 'Di luar kawasan biasa. WhatsApp ' . WA_MAIN . ' untuk tanya kes khas.' : null];
}
/** Small searchable index of the public info, for ChatGPT-style search/fetch connectors. */
function mcp_docs(): array {
  $docs = [];
  $docs['shop'] = ['title' => 'Tentang 100 Blooms & Combo Jimat', 'data' => tool_get_shop_info([])];
  $docs['delivery'] = ['title' => 'Slot & caj penghantaran', 'data' => tool_get_delivery_options([])];
  $P = tool_list_products([]);
  foreach ($P['bouquets'] ?? [] as $b) $docs['bouquet:' . $b['id']] = ['title' => 'Bouquet ' . $b['name'], 'data' => $b];
  foreach ($P['balloons'] ?? [] as $b) $docs['balloon:' . $b['id']] = ['title' => 'Balloon ' . $b['name'], 'data' => $b];
  foreach ($P['cakes'] ?? [] as $c) $docs['cake:' . md5($c['name'])] = ['title' => 'Kek ' . $c['name'], 'data' => $c];
  foreach ($P['addons'] ?? [] as $x) $docs['addon:' . $x['id']] = ['title' => 'Add-on ' . $x['name'], 'data' => $x];
  return $docs;
}
function tool_search(array $a): array {
  $q = mb_strtolower(trim((string)($a['query'] ?? ''))); $words = array_filter(preg_split('/\s+/', $q), fn($w) => mb_strlen($w) >= 2); $res = [];
  foreach (mcp_docs() as $id => $d) {
    $hay = mb_strtolower($d['title'] . ' ' . json_encode($d['data'], JSON_UNESCAPED_UNICODE)); $score = 0;
    foreach ($words as $w) if (str_contains($hay, $w)) $score += str_contains(mb_strtolower($d['title']), $w) ? 3 : 1;
    if ($score || !$words) $res[] = ['id' => $id, 'title' => $d['title'], 'url' => SITE_URL, 'score' => $score];
  }
  usort($res, fn($x, $y) => $y['score'] <=> $x['score']);
  return ['results' => array_map(fn($r) => ['id' => $r['id'], 'title' => $r['title'], 'url' => $r['url']], array_slice($res, 0, 20))];
}
function tool_fetch(array $a): array {
  $id = (string)($a['id'] ?? ''); $docs = mcp_docs();
  if (!isset($docs[$id])) return ['error' => 'id tidak dijumpai. Guna search dulu.'];
  return ['id' => $id, 'title' => $docs[$id]['title'], 'text' => json_encode($docs[$id]['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 'url' => SITE_URL, 'metadata' => ['source' => '100 Blooms']];
}
