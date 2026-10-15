<?php
// Serves an order's own photos (the buyer's polaroids, and the recipient photo once delivered) to the buyer,
// authorised by the per-order token that api/track.php hands out after verifying code + WhatsApp number.
require __DIR__ . '/_lib.php';
$o = q('SELECT code, token, photos, recipient_photo, status FROM orders WHERE code=?', [(string)($_GET['c'] ?? '')])->fetch();
if (!$o || !hash_equals($o['token'], (string)($_GET['k'] ?? ''))) { http_response_code(404); exit; }
$f = basename((string)($_GET['f'] ?? ''));
$allowed = json_decode((string)$o['photos'], true) ?: [];
if (in_array($o['status'], ['sampai', 'selesai'], true) && $o['recipient_photo']) $allowed[] = $o['recipient_photo'];
if (!in_array($f, $allowed, true)) { http_response_code(404); exit; }
$path = UPLOAD_DIR . '/' . $o['code'] . '/' . $f;
if (!is_file($path)) { http_response_code(404); exit; }
$mime = (string)(@getimagesize($path)['mime'] ?? '');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff');
readfile($path);
