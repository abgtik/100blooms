<?php
// The recipient's own delivery photo, for the recipient page (/hadiah/), authorised by the recipient key (not the buyer's token).
require __DIR__ . '/_lib.php';
$o = q('SELECT code, token, recipient_photo, status FROM orders WHERE code=?', [(string)($_GET['c'] ?? '')])->fetch();
if (!$o || !hash_equals(rcp_key($o), (string)($_GET['r'] ?? '')) || !$o['recipient_photo'] || !in_array($o['status'], ['sampai', 'selesai'], true)) { http_response_code(404); exit; }
$path = UPLOAD_DIR . '/' . $o['code'] . '/' . basename($o['recipient_photo']);
if (!is_file($path)) { http_response_code(404); exit; }
$mime = (string)(@getimagesize($path)['mime'] ?? '');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff');
if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="kenangan-100blooms.jpg"');
readfile($path);
