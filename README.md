# 100 Blooms: Fasa A

Web app tempahan Combo Jimat RM128, bayaran ToyyibPay (DuitNow QR + FPX) dan dashboard admin. Sistem ni dibina untuk hosting cPanel dengan PHP 8 dan MySQL.

## Isi folder `public_html/`

| Fail / folder | Fungsi |
|---|---|
| `index.html` | App customer (semua langkah dari momen sampai bayaran) |
| `assets/` | Gambar bouquet, balloon, kek, testimoni |
| `api/` | Simpan order, kira harga, sambung ToyyibPay, terima callback |
| `admin/` | Dashboard admin: order, status, stok, tutup tarikh, pengguna |
| `uploads/` | Gambar polaroid customer dan gambar penerima. Folder ni peribadi dan tak boleh dibuka terus dari internet |
| `setup.php` | Pemasang sekali guna: cipta jadual database dan akaun Owner pertama |
| `config.sample.php` | Contoh tetapan. Salin jadi `config.php` |

## Pasang di cPanel (lebih kurang 20 minit)

1. **Cipta database.** Buka cPanel → *MySQL® Databases*.
   - Cipta database, contohnya `xxx_blooms`.
   - Cipta user dengan password yang kuat.
   - *Add User To Database* dan tanda **ALL PRIVILEGES**.
   - Catat nama database, nama user dan password.
2. **Upload fail.** Buka cPanel → *File Manager* → `public_html`.
   - Upload `100blooms-faseA.zip` dan *Extract*.
   - Pastikan `index.html` berada terus dalam `public_html`, bukan dalam subfolder.
3. **Tetapan.** Dalam File Manager, salin `config.sample.php` dan namakan salinan tu `config.php`. Kemudian *Edit* `config.php` dan isi:
   - `DB_DSN`: tukar `NAMA_DATABASE` kepada nama database dari langkah 1.
   - `DB_USER` dan `DB_PASS`.
   - `SITE_URL`: contohnya `https://100blooms.my`, tanpa `/` di hujung.
   - `TP_SECRET` dan `TP_CATEGORY`: dari ToyyibPay (lihat bahagian di bawah).
   - `NOTIFY_EMAIL`: email yang akan terima notifikasi setiap order yang dah dibayar.
4. **Jalankan setup.** Buka `https://domainawak/setup.php` dan cipta akaun **Owner**. Lepas siap, padam `setup.php`.
5. **SSL.** Pastikan domain guna `https` (cPanel → *SSL/TLS Status* → *Run AutoSSL*). ToyyibPay hantar callback ke alamat https.
6. **Log masuk admin** di `https://domainawak/admin/`.

## ToyyibPay

**Uji dulu dengan sandbox (percuma, tak perlu verify):**
1. Daftar di https://dev.toyyibpay.com.
2. Cipta satu *Category*, contohnya "Combo Jimat". Salin **Category Code**.
3. Salin **User Secret Key** dari profil akaun.
4. Dalam `config.php`, set `TP_SANDBOX` kepada `true` dan isi dua kod tadi.
5. Buat order percubaan dari app, kemudian bayar guna bank ujian dalam sandbox.
6. Semak di `/admin/` bahawa order tu bertukar jadi **Baru (dibayar)**.

**Bila akaun sebenar dah verified:**
1. Cipta Category di https://toyyibpay.com dan salin kod-kod yang baru.
2. Tukar `TP_SANDBOX` kepada `false`.
3. Minta ToyyibPay aktifkan **DuitNow QR**. Lepas diaktifkan, tukar `TP_DUITNOW_QR` kepada `true`. Sebelum tu, customer bayar guna FPX (online banking) sahaja.

**Macam mana bayaran disahkan:** ToyyibPay hantar callback ke `/api/callback.php`. Sistem semak hash callback tu, kemudian tanya ToyyibPay sendiri (`getBillTransactions`) dan banding jumlah bayaran sebelum tanda order sebagai dibayar. Kalau callback lambat atau tak sampai:
- Halaman terima kasih customer akan semak status sendiri.
- Admin boleh tekan **Semak semula dengan ToyyibPay** dalam order tu.

## Peraturan yang dikuatkuasakan di server

Harga dan peraturan berikut dikira semula di server, jadi customer tak boleh ubah harga dari browser:

- **Harga asas:** RM128.
- **Upgrade kek:** +RM10.
- **Kek tambahan:** RM20 sepotong, maksimum 10.
- **Polaroid tambahan:** RM10 sekeping, gambar pertama percuma.
- **Penghantaran:** percuma (10am–6pm), Slot A/B/C +RM10, masa khusus +RM50.
- **Zon merah:** +RM20 untuk Rawang, Kuang, Selayang dan Sg Buloh. Poskod di luar zon ditolak.
- **Waktu tutup order untuk hari yang sama:**
  - Penghantaran percuma: 5pm.
  - Slot A: 9am.
  - Slot B: 2pm.
  - Slot C: 6pm.
- **Masa khusus:** antara 9am dan 10pm, dan mesti ditempah sekurang-kurangnya 5 jam lebih awal.
- **Tarikh yang ditutup** oleh admin tak boleh dipilih.
- **Item yang ditutup stoknya** tak dipaparkan kepada customer dan ditolak oleh server.

Semua peraturan ni ada dalam `api/_lib.php`, kecuali senarai poskod zon yang juga perlu ditukar dalam `index.html` (cari `ZONE_FREE`). Kalau ubah harga atau zon, tukar di **kedua-dua** fail.

## Peranan admin

| | Owner | Staff |
|---|---|---|
| Lihat order, tukar status, nota, tukar tarikh delivery | ✓ | ✓ |
| Upload gambar penerima, cetak wishcard | ✓ | ✓ |
| Buka/tutup stok, tutup tarikh | ✓ | ✓ |
| Tanda bayaran manual (contoh: transfer bank) | ✓ | |
| Tambah/padam pengguna admin | ✓ | |

## Senarai semak sebelum lancar

- [ ] Order sandbox: bayaran berjaya → status **Baru (dibayar)**, email notifikasi diterima
- [ ] Order sandbox: bayaran gagal → kekal **Menunggu bayaran**
- [ ] Upload polaroid dari telefon Android dan iPhone
- [ ] Poskod zon merah → +RM20; poskod luar zon → disekat
- [ ] Tutup satu bouquet → hilang dari app customer
- [ ] Tutup satu tarikh → tak boleh dipilih
- [ ] Tukar ke ToyyibPay sebenar dan buat satu order RM sebenar sebelum iklan dijalankan

## Fasa seterusnya

- **Fasa B:** graf jualan, report dan kalendar.
- **Fasa C:** sistem agent. Link agent (`?ref=ID`) dah pun direkod dalam setiap order sejak Fasa A, jadi order awal tetap boleh dikira untuk komisyen nanti.
