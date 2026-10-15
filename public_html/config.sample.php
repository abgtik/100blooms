<?php
// 100 Blooms: tetapan sistem.
// Salin fail ini sebagai config.php (dalam folder yang sama) dan isi nilai sebenar.
// JANGAN kongsi config.php dengan sesiapa: ia ada password database dan kunci gateway bayaran.

// ---- Database (cPanel → MySQL Databases) ----
define('DB_DSN',  'mysql:host=localhost;dbname=NAMA_DATABASE;charset=utf8mb4');
define('DB_USER', 'NAMA_USER_DATABASE');
define('DB_PASS', 'PASSWORD_DATABASE');

// ---- Alamat laman (tanpa / di hujung) ----
define('SITE_URL', 'https://domainawak.com');

// ---- Gateway bayaran: 'hitpay' atau 'toyyibpay' ----
define('PAY_GATEWAY', 'hitpay');

// ---- HitPay (Dashboard → Developers → API Keys) ----
// Mula dengan sandbox (true). Tukar ke false bila nak terima bayaran sebenar.
define('HP_SANDBOX', true);
define('HP_API_KEY', 'API_KEY_HITPAY');
define('HP_SALT', 'SALT_HITPAY');

// ---- ToyyibPay (simpanan, jika PAY_GATEWAY = 'toyyibpay') ----
// Mula dengan sandbox (true). Tukar ke false bila akaun sebenar dah verified.
define('TP_SANDBOX', true);
define('TP_SECRET', 'USER_SECRET_KEY_TOYYIBPAY');
define('TP_CATEGORY', 'CATEGORY_CODE_TOYYIBPAY');
// Tukar ke true bila DuitNow QR dah diaktifkan oleh ToyyibPay untuk akaun awak.
define('TP_DUITNOW_QR', false);

// ---- Notifikasi order baru (kosongkan untuk matikan) ----
define('NOTIFY_EMAIL', 'emailawak@contoh.com');
define('MAIL_FROM', 'order@domainawak.com');

// ---- Folder gambar customer (pilihan) ----
// Kosongkan untuk automatik: guna folder 'private' di luar httpdocs jika ada (Plesk), jika tidak 'uploads'.
// define('UPLOAD_DIR', '/home/100blooms.my/private/uploads');

// ---- Mod ujian (pilihan) ----
// Buka https://domain/?test=KOD untuk buat order RM1. Padam baris ini atau kosongkan untuk matikan.
// define('TEST_KEY', 'tukar-kepada-kod-rahsia-anda');

// ---- WhatsApp team (dipaparkan kepada customer) ----
define('WA_MAIN', '601165862584');
