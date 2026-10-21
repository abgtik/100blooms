<?php
// Policies page (refund & cancellation, delivery, payment, privacy, terms, contact). BM by default, English with ?lang=en.
// Anchors: #refund #penghantaran #bayaran #privasi #terma #hubungi (required by Billplz and other payment providers).
require __DIR__ . '/../api/_lib.php';
header('Content-Type: text/html; charset=utf-8');
$en = ($_GET['lang'] ?? '') === 'en';
$B = biz_info();
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$L = fn($ms, $en_) => $en ? $en_ : $ms;
$phone = digits($B['phone']); $phoneTxt = '+' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 2) . '-' . substr($phone, 4, 3) . ' ' . substr($phone, 7);
$wa = 'https://wa.me/' . $phone;
$upd = '21 Oktober 2026'; $updE = '21 October 2026';
?><!doctype html>
<html lang="<?= $en ? 'en' : 'ms' ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $e($L('Polisi & Terma · 100 Blooms', 'Policies & Terms · 100 Blooms')) ?></title>
<meta name="description" content="<?= $e($L('Polisi refund & pembatalan, penghantaran, bayaran, privasi dan terma 100 Blooms Enterprise.', 'Refund & cancellation, delivery, payment, privacy and terms of 100 Blooms Enterprise.')) ?>">
<link rel="canonical" href="<?= $e(SITE_URL . '/polisi/' . ($en ? '?lang=en' : '')) ?>">
<link rel="alternate" hreflang="ms" href="<?= $e(SITE_URL) ?>/polisi/"><link rel="alternate" hreflang="en" href="<?= $e(SITE_URL) ?>/polisi/?lang=en">
<link rel="icon" href="/favicon.ico?v=2" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/assets/icon-32.png?v=2"><link rel="apple-touch-icon" href="/assets/apple-touch-icon.png?v=2">
<meta name="theme-color" content="#8e1b2f">
<style>
:root{--ink:#3b2732;--soft:#7d6370;--line:#ecdde2;--bg:#f7f1f3;--card:#fff;--acc:#8e1b2f}
*{box-sizing:border-box}body{margin:0;font:16px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:var(--bg)}
main{max-width:760px;margin:0 auto;padding:24px 16px 60px}
header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}
header a.logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--acc);font:600 20px Georgia,serif;letter-spacing:2px}
header img{width:40px;height:40px}
.lang{font-size:13px;border:1px solid var(--line);border-radius:999px;padding:4px 12px;background:#fff;color:var(--ink);text-decoration:none}
h1{font-size:28px;line-height:1.25;margin:0 0 6px}
.upd{color:var(--soft);font-size:14px;margin:0 0 18px}
nav.toc{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 20px}
nav.toc a{font-size:14px;padding:6px 12px;border-radius:999px;background:#fff;border:1px solid var(--line);color:var(--acc);text-decoration:none}
section{background:var(--card);border-radius:18px;padding:20px 22px;margin:0 0 14px;scroll-margin-top:12px}
section h2{margin:0 0 10px;font-size:20px;color:var(--acc)}
section ul{margin:0;padding-left:20px}section li{margin:6px 0}
.box{background:#fff7e8;border:1px solid #f1d38f;border-radius:12px;padding:10px 14px;margin:0 0 12px}
dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}dt{color:var(--soft)}dd{margin:0}
a{color:var(--acc)}
footer{text-align:center;color:var(--soft);font-size:14px;margin-top:24px}
@media(max-width:520px){dl{grid-template-columns:1fr}dt{margin-top:6px}}
</style></head><body><main>
<header><a class="logo" href="/<?= $en ? 'en/' : '' ?>"><img src="/assets/logo-icon.svg?v=20261018" alt="" width="40" height="40">100 BLOOMS</a>
<a class="lang" href="<?= $en ? '/polisi/' : '/polisi/?lang=en' ?>"><?= $en ? 'Bahasa Melayu' : 'English' ?></a></header>
<h1><?= $L('Polisi & Terma', 'Policies & Terms') ?></h1>
<p class="upd"><?= $e($B['name']) ?><?= $B['reg'] ? ' (' . $e($B['reg']) . ')' : '' ?> · <?= $L("Dikemas kini $upd", "Last updated $updE") ?></p>
<nav class="toc" aria-label="<?= $L('Kandungan', 'Contents') ?>">
 <a href="#refund"><?= $L('Refund & pembatalan', 'Refund & cancellation') ?></a><a href="#penghantaran"><?= $L('Penghantaran', 'Delivery') ?></a>
 <a href="#bayaran"><?= $L('Bayaran', 'Payment') ?></a><a href="#privasi"><?= $L('Privasi', 'Privacy') ?></a><a href="#terma"><?= $L('Terma & syarat', 'Terms & conditions') ?></a><a href="#hubungi"><?= $L('Hubungi kami', 'Contact us') ?></a>
</nav>

<section id="refund"><h2><?= $L('Polisi refund & pembatalan', 'Refund & cancellation policy') ?></h2>
<?php if (!$en): ?>
 <div class="box">Hadiah kami disediakan khas untuk setiap order (bunga segar, kek dan barang dibeli selepas bayaran), jadi <b>order yang dah dibayar tidak boleh dibatalkan atau di-refund</b>.</div>
 <ul>
  <li><b>Tukar tarikh:</b> awak boleh minta tukar tarikh penghantaran melalui WhatsApp, tertakluk kepada kekosongan tarikh baru. Beza caj slot (jika ada) perlu dibayar.</li>
  <li><b>Kami tak dapat penuhi order:</b> jika 100 Blooms tidak dapat menghantar order yang dah dibayar (contohnya alamat di luar kawasan liputan), kami akan tawarkan tarikh lain atau <b>bayaran balik penuh</b> ke akaun asal dalam tempoh 7 hari bekerja.</li>
  <li><b>Masalah pada hadiah:</b> jika hadiah rosak atau tidak sama dengan order, WhatsApp kami secepat mungkin bersama gambar. Team kami akan semak dan bantu, termasuk penggantian atau voucher.</li>
  <li><b>Penerima tiada / maklumat salah:</b> jika penghantaran gagal kerana alamat atau nombor penerima salah, atau penerima tidak dapat dihubungi, order dikira selesai. Team akan hubungi pembeli untuk aturan seterusnya.</li>
 </ul>
<?php else: ?>
 <div class="box">Every gift is prepared specially for each order (fresh flowers, cake and items bought after payment), so <b>paid orders cannot be cancelled or refunded</b>.</div>
 <ul>
  <li><b>Change of date:</b> you may ask to change the delivery date on WhatsApp, subject to availability on the new date. Any difference in slot charges must be paid.</li>
  <li><b>If we cannot fulfil your order:</b> if 100 Blooms cannot deliver a paid order (for example, an address outside our delivery area), we will offer another date or a <b>full refund</b> to the original account within 7 working days.</li>
  <li><b>Problems with the gift:</b> if the gift is damaged or not as ordered, WhatsApp us as soon as possible with a photo. Our team will review it and help, including a replacement or a voucher.</li>
  <li><b>Recipient unavailable / wrong details:</b> if delivery fails because the recipient's address or number is wrong, or the recipient cannot be reached, the order is considered fulfilled. Our team will contact the buyer to arrange next steps.</li>
 </ul>
<?php endif ?>
</section>

<section id="penghantaran"><h2><?= $L('Polisi penghantaran', 'Delivery policy') ?></h2>
<ul>
<?php if (!$en): ?>
 <li><b>Kawasan:</b> Kuala Lumpur, Putrajaya dan kebanyakan Lembah Klang. Rawang, Kuang, Selayang dan Sungai Buloh dikenakan caj kawasan RM20. Poskod disemak semasa order.</li>
 <li><b>Penghantaran percuma</b> antara 10 pagi – 6 petang (masa tepat tidak dijanjikan).</li>
 <li><b>Slot tetap</b> (+RM10): Slot A 9 pagi–12 tengah hari, Slot B 1–4 petang, Slot C 5–8 malam. <b>Masa khusus</b> (+RM50) antara 9 pagi – 10 malam, ditempah sekurang-kurangnya 5 jam awal.</li>
 <li><b>Hari yang sama:</b> tertakluk kepada waktu tutup setiap slot dan kapasiti harian.</li>
 <li>Item katalog penuh (di luar Combo Jimat) dikenakan caj penghantaran berasingan seperti dinyatakan dalam invoice.</li>
 <li>Runner kami mengambil gambar penerima bersama hadiah sebagai kenangan untuk pembeli.</li>
<?php else: ?>
 <li><b>Area:</b> Kuala Lumpur, Putrajaya and most of the Klang Valley. Rawang, Kuang, Selayang and Sungai Buloh carry an RM20 area charge. Postcodes are checked when you order.</li>
 <li><b>Free delivery</b> between 10am – 6pm (exact time not guaranteed).</li>
 <li><b>Fixed slots</b> (+RM10): Slot A 9am–12pm, Slot B 1–4pm, Slot C 5–8pm. <b>Specific time</b> (+RM50) between 9am – 10pm, booked at least 5 hours ahead.</li>
 <li><b>Same day:</b> subject to each slot's cut-off time and daily capacity.</li>
 <li>Full-catalogue items (outside Combo Jimat) carry a separate delivery charge as stated on the invoice.</li>
 <li>Our runner takes a photo of the recipient with the gift as a keepsake for the buyer.</li>
<?php endif ?>
</ul></section>

<section id="bayaran"><h2><?= $L('Bayaran', 'Payment') ?></h2>
<ul>
<?php if (!$en): ?>
 <li>Semua harga dalam Ringgit Malaysia (RM) dan dipaparkan sebelum bayaran.</li>
 <li>Bayaran penuh diperlukan sebelum hadiah disiapkan. Tiada bayaran separuh atau COD.</li>
 <li>Bayaran online (FPX, DuitNow, kad) diproses oleh penyedia gerbang pembayaran berlesen. 100 Blooms tidak menyimpan maklumat kad atau akaun bank awak.</li>
 <li>Bayaran melalui pemindahan bank / QR disahkan selepas kami terima resit.</li>
 <li>Invoice dan resit (PDF) dihantar melalui WhatsApp dan boleh dibuka di link semak order.</li>
<?php else: ?>
 <li>All prices are in Malaysian Ringgit (RM) and shown before payment.</li>
 <li>Full payment is required before we prepare the gift. No deposits or cash on delivery.</li>
 <li>Online payments (FPX, DuitNow, card) are processed by a licensed payment gateway. 100 Blooms does not store your card or bank account details.</li>
 <li>Bank transfer / QR payments are confirmed once we receive your receipt.</li>
 <li>The invoice and receipt (PDF) are sent on WhatsApp and available from your order tracking link.</li>
<?php endif ?>
</ul></section>

<section id="privasi"><h2><?= $L('Polisi privasi', 'Privacy policy') ?></h2>
<ul>
<?php if (!$en): ?>
 <li><b>Data yang dikumpul:</b> nama dan nombor telefon pembeli, nama, telefon dan alamat penerima, ayat wishcard, gambar polaroid yang di-upload, dan butiran order.</li>
 <li><b>Tujuan:</b> untuk memproses, menyiapkan dan menghantar order, menghubungi awak tentang order, serta menghantar invoice dan resit.</li>
 <li><b>Gambar polaroid</b> disimpan secara peribadi dan hanya digunakan untuk dicetak dalam hadiah. Gambar tidak dikongsi secara umum tanpa kebenaran.</li>
 <li><b>Pihak ketiga:</b> maklumat penerima dikongsi dengan runner penghantaran setakat yang perlu untuk menghantar hadiah. Bayaran diproses oleh gerbang pembayaran. Kami tidak menjual data awak.</li>
 <li><b>Mesej:</b> kami mungkin menghantar mesej WhatsApp tentang promosi atau tarikh penting. Balas <b>STOP</b> untuk berhenti menerimanya.</li>
 <li><b>Hak awak:</b> awak boleh minta salinan, pembetulan atau pemadaman data peribadi awak dengan menghubungi kami. Data diurus selaras dengan Akta Perlindungan Data Peribadi 2010.</li>
<?php else: ?>
 <li><b>Data we collect:</b> buyer's name and phone number; recipient's name, phone and address; card message; uploaded polaroid photos; and order details.</li>
 <li><b>Purpose:</b> to process, prepare and deliver your order, contact you about it, and send your invoice and receipt.</li>
 <li><b>Polaroid photos</b> are stored privately and used only to print for the gift. They are not shared publicly without permission.</li>
 <li><b>Third parties:</b> recipient details are shared with the delivery runner only as needed to deliver the gift. Payments are processed by the payment gateway. We do not sell your data.</li>
 <li><b>Messages:</b> we may send WhatsApp messages about promotions or important dates. Reply <b>STOP</b> to opt out.</li>
 <li><b>Your rights:</b> you may request a copy, correction or deletion of your personal data by contacting us. Data is handled in line with Malaysia's Personal Data Protection Act 2010.</li>
<?php endif ?>
</ul></section>

<section id="terma"><h2><?= $L('Terma & syarat', 'Terms & conditions') ?></h2>
<ul>
<?php if (!$en): ?>
 <li>Dengan membuat order, awak bersetuju dengan polisi di halaman ini.</li>
 <li>Gambar produk adalah contoh. Bunga segar, warna wrapping dan reben mungkin sedikit berbeza mengikut stok semasa, dengan nilai yang sama.</li>
 <li>Jika perisa kek atau item tertentu kehabisan stok, kami akan gantikan dengan pilihan yang setara.</li>
 <li>Pembeli bertanggungjawab atas ketepatan maklumat penerima dan kandungan ayat wishcard.</li>
 <li>Voucher tertakluk kepada syarat dan tarikh tamat masing-masing, dan tidak boleh ditukar kepada tunai.</li>
<?php else: ?>
 <li>By placing an order, you agree to the policies on this page.</li>
 <li>Product photos are examples. Fresh flowers, wrapping and ribbon colours may vary slightly with current stock, at the same value.</li>
 <li>If a cake flavour or item is out of stock, we will replace it with an equivalent option.</li>
 <li>The buyer is responsible for the accuracy of the recipient's details and the card message.</li>
 <li>Vouchers are subject to their own terms and expiry dates and cannot be exchanged for cash.</li>
<?php endif ?>
</ul></section>

<section id="hubungi"><h2><?= $L('Hubungi kami', 'Contact us') ?></h2>
<dl>
 <dt><?= $L('Nama syarikat', 'Company') ?></dt><dd><?= $e($B['name']) ?></dd>
 <?php if ($B['reg']): ?><dt><?= $L('No. pendaftaran', 'Registration no.') ?></dt><dd><?= $e($B['reg']) ?></dd><?php endif ?>
 <?php if ($B['addr']): ?><dt><?= $L('Alamat', 'Address') ?></dt><dd><?= nl2br($e($B['addr'])) ?></dd><?php endif ?>
 <dt>WhatsApp</dt><dd><a href="<?= $e($wa) ?>" target="_blank" rel="noopener"><?= $e($phoneTxt) ?></a></dd>
 <?php if ($B['email']): ?><dt>Email</dt><dd><a href="mailto:<?= $e($B['email']) ?>"><?= $e($B['email']) ?></a></dd><?php endif ?>
</dl></section>
<footer><a href="/<?= $en ? 'en/' : '' ?>"><?= $L('← Kembali ke 100blooms.my', '← Back to 100blooms.my') ?></a></footer>
</main></body></html>
