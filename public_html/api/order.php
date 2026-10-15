<?php
// POST multipart: data=<json>, photo[]=<jpeg/png files>. Validates, prices, saves the order as pending payment,
// creates a payment request (HitPay or ToyyibPay) and returns where to send the customer to pay.
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST sahaja.', 405);

$in = json_decode((string)($_POST['data'] ?? ''), true);
if (!is_array($in)) fail('Data order tidak lengkap. Muat semula halaman dan cuba lagi.');

// collect + verify uploaded photos before touching the database
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

try {
  $r = build_order($in, count($files));
  if (isset($r['errors'])) fail(implode(' ', $r['errors']), 422);
  $o = $r['order'];
  $o['code'] = new_code();
  $o['token'] = bin2hex(random_bytes(12));
  $o['created_at'] = date('Y-m-d H:i:s');
  $ref = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['ref'] ?? ''));
  $o['agent_ref'] = $ref !== '' ? substr($ref, 0, 20) : null;

  // photos → uploads/<code>/
  $saved = [];
  if ($files) {
    $dir = UPLOAD_DIR . '/' . $o['code'];
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Tak dapat cipta folder uploads');
    foreach ($files as $i => [$tmp, $ext]) {
      $name = 'p' . ($i + 1) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
      if (!move_uploaded_file($tmp, "$dir/$name") && !rename($tmp, "$dir/$name")) throw new RuntimeException('Simpan gambar gagal');
      $saved[] = $name;
    }
  }
  $o['photos'] = json_encode($saved);

  $cols = array_keys($o);
  q('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($o));
  $o['id'] = (int)db()->lastInsertId();
} catch (Throwable $e) {
  error_log('100blooms order: ' . $e->getMessage());
  fail('Order tak dapat disimpan sekarang. Cuba sekali lagi, atau WhatsApp kami.', 500);
}

// The order is saved even if the payment page fails, so the team can follow up.
try {
  [$bill, $url] = pay_create($o);
  q('UPDATE orders SET bill_code=? WHERE id=?', [$bill, $o['id']]);
  json_out(['code' => $o['code'], 'total' => $o['total'], 'payUrl' => $url]);
} catch (Throwable $e) {
  error_log('100blooms bill: ' . $e->getMessage());
  q('UPDATE orders SET notes=? WHERE id=?', ['Gagal cipta bayaran ' . gateway_name() . ': ' . mb_substr($e->getMessage(), 0, 200), $o['id']]);
  json_out(['code' => $o['code'], 'total' => $o['total'], 'payUrl' => null,
    'error' => 'Order ' . $o['code'] . ' dah disimpan, tapi halaman bayaran tak dapat dibuka. WhatsApp kami dengan kod ni dan kami bantu.'], 502);
}
