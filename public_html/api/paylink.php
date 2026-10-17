<?php
// Buyer asks for an online payment link from the tracking page (orders sent as invoice + bank QR).
//   POST JSON {c, k} → {url}. One payment link per order; reused if it already exists.
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST sahaja.', 405);
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$o = q('SELECT * FROM orders WHERE code=?', [(string)($in['c'] ?? '')])->fetch();
if (!$o || !hash_equals($o['token'], (string)($in['k'] ?? ''))) fail('Link tidak sah. Buka semula halaman semak order.', 404);
if ($o['pay_status'] === 'paid') fail('Order ni dah dibayar. Terima kasih!', 409);
if ($o['status'] === 'batal') fail('Order ni dah dibatalkan.', 409);
try {
  if ($o['bill_code'] && ($u = pay_url($o['bill_code']))) json_out(['url' => $u]);
  [$bill, $url] = pay_create($o);
  q('UPDATE orders SET bill_code=? WHERE id=?', [$bill, $o['id']]);
  json_out(['url' => $url]);
} catch (Throwable $e) {
  error_log('100blooms paylink: ' . $e->getMessage());
  fail('Halaman bayaran online tak dapat dibuka sekarang. Guna QR bank di bawah, atau WhatsApp kami.', 502);
}
