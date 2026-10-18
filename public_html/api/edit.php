<?php
// Buyer updates from the tracking page, authorised by the order token (c + k), like photo.php.
//   POST JSON {c,k,a:'wish', wish, sender}      → write / edit the wishcard (first write free, then WISH_EDITS edits)
//   POST multipart c,k,a=photo_add, photo[]     → add polaroids (up to the number paid for)
//   POST JSON {c,k,a:'photo_del', f}            → remove one polaroid
//   POST JSON {c,k,a:'bill', bill_to}           → company name/address printed on the invoice and receipt
// Everything locks once the item is ticked in production or the order has left for delivery.
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST sahaja.', 405);
$in = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') ? (json_decode(file_get_contents('php://input') ?: '', true) ?: []) : $_POST;

$o = q('SELECT * FROM orders WHERE code=?', [(string)($in['c'] ?? '')])->fetch();
if (!$o || !hash_equals($o['token'], (string)($in['k'] ?? ''))) fail('Link tidak sah. Buka semula halaman semak order.', 404);
$e = buyer_edit($o);
$a = (string)($in['a'] ?? '');

if ($a === 'wish') {
  if (!$e['wish']) fail($e['wish_left'] <= 0 ? 'Had edit wishcard dah habis. WhatsApp kami jika perlu ubah lagi.' : 'Wishcard dah ditulis oleh team kami, jadi tak boleh diubah lagi. WhatsApp kami jika perlu.', 409);
  $wish = str_in($in['wish'] ?? '', WISH_MAX); $sender = str_in($in['sender'] ?? '', 60);
  if (mb_strlen($wish) < 2) fail('Tulis ayat wishcard dulu.');
  if ($wish === $o['wish'] && $sender === (string)$o['sender']) fail('Tiada perubahan.');
  q('UPDATE orders SET wish=?, sender=?, wish_edits=wish_edits+?, updated_by=? WHERE id=?', [$wish, $sender !== '' ? $sender : null, $e['wish_first'] ? 0 : 1, 'Pembeli', $o['id']]);
} elseif ($a === 'photo_add') {
  if (!$e['photo']) fail('Polaroid dah dicetak, jadi gambar tak boleh ditukar lagi. WhatsApp kami jika perlu.', 409);
  $files = collect_photos(); if (!$files) fail('Pilih gambar dulu.');
  add_photos($o, $files);
  q("UPDATE orders SET updated_by='Pembeli' WHERE id=?", [$o['id']]);
} elseif ($a === 'photo_del') {
  if (!$e['photo']) fail('Polaroid dah dicetak, jadi gambar tak boleh ditukar lagi.', 409);
  remove_photo($o, (string)($in['f'] ?? ''));
} elseif ($a === 'bill') { // company name/address for the invoice and receipt (any time)
  $b = str_in($in['bill_to'] ?? '', 400);
  q('UPDATE orders SET bill_to=?, updated_by=? WHERE id=?', [$b !== '' ? $b : null, 'Pembeli', $o['id']]);
} else fail('Tindakan tidak sah.');

json_out(['ok' => true]);
