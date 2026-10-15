<?php
// Serves private order photos (customer polaroids, recipient photo) to logged-in admins only.
require __DIR__ . '/../api/_lib.php';
session_name('hbadmin'); session_start();
if (empty($_SESSION['uid'])) { http_response_code(401); exit; }
$code = preg_replace('/[^A-Z0-9-]/', '', (string)($_GET['o'] ?? ''));
$f = basename((string)($_GET['f'] ?? ''));
$path = UPLOAD_DIR . "/$code/$f";
if ($code === '' || $f === '' || !is_file($path)) { http_response_code(404); exit; }
$mime = (string)(@getimagesize($path)['mime'] ?? '');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime); header('Cache-Control: private, max-age=3600'); header('X-Content-Type-Options: nosniff');
if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="' . $code . '-' . $f . '"');
readfile($path);
