# Panduan Deploy ke Vercel

Vercel tidak punya PHP dan MySQL bawaan. Proyek ini memakai runtime komunitas **vercel-php** (PHP 8.3) untuk menjalankan PHP,
dan **database MySQL di layanan lain** (Vercel hanya menjalankan kodenya).

## 1. Siapkan database MySQL online
Pilih penyedia MySQL yang punya TLS dan paket gratis/murah (contoh: TiDB Cloud, Aiven, Railway; cek syarat terbaru masing-masing).
1. Buat database kosong, catat **host, port, nama database, user, password**.
2. Jalankan isi file **`database_online.sql`** pada database itu (lewat SQL editor penyedia, DBeaver, atau MySQL Workbench).
3. Di pengaturan keamanan penyedia, izinkan koneksi dari mana saja (alamat IP Vercel berubah-ubah).

## 2. Unggah proyek ke GitHub
Unggah seluruh isi folder `presensi-ma` ke repositori (private disarankan). Jangan menaruh sandi database di dalam kode.

## 3. Impor di Vercel
1. vercel.com → **Add New → Project** → pilih repositori.
2. **Framework Preset: Other.** Kosongkan Build Command dan Output Directory.
3. Buka **Environment Variables**, isi:

| Nama | Isi |
|---|---|
| `DB_HOST` | host database |
| `DB_PORT` | port (mis. 3306 atau 4000) |
| `DB_NAME` | nama database |
| `DB_USER` | user database |
| `DB_PASS` | sandi database |
| `DB_SSL` | `1` (wajib untuk hampir semua database online) |
| `BIOMETRIK_MODE` | `simulasi` untuk uji coba, `webauthn` untuk sidik jari asli |
| `APP_ORIGIN` | alamat situs, mis. `https://presensi-ma.vercel.app` (wajib bila `webauthn`) |

4. Klik **Deploy**.

## 4. Setelah situs hidup
1. Buka alamat situs → login web panel di `/admin/login.php` dengan NIP `199203142018`, sandi `password123`.
2. **Ganti semua sandi contoh** (menu Pengguna & Perangkat → Ubah).
3. Menu **Lokasi** → atur titik pusat dan radius → Simpan.
4. Mode `webauthn`: setiap pegawai membuka situs di Chrome Android, login, lalu menekan **Daftarkan sidik jari** sekali.

## Jika bermasalah
**Langkah pertama: buka `https://ALAMAT-SITUS-ANDA/api/cek.php`.** Halaman ini memeriksa ekstensi PHP, variabel lingkungan,
koneksi database, dan tabel, lalu menandai baris yang bermasalah dengan ❌. **Hapus `api/cek.php` setelah sistem normal.**

| Gejala | Penyebab umum | Perbaikan |
|---|---|---|
| Login: "Server membalas dengan error (kode 500)" | Fungsi PHP gagal | Buka `/api/cek.php`, lihat baris ❌ |
| "Server tidak dapat menjangkau database" | `DB_HOST`/`DB_PORT` salah, atau database memblokir IP | Periksa nilai; izinkan koneksi dari semua IP |
| "Database menolak user/sandi" | `DB_USER`/`DB_PASS` salah | Perbaiki, lalu **Redeploy** |
| "Koneksi ditolak karena TLS/SSL" | `DB_SSL` belum `1` | Isi `DB_SSL=1`, lalu **Redeploy** |
| "Tabel database belum ada" | `database_online.sql` belum dijalankan | Jalankan pada database yang dipakai `DB_NAME` |
| "Tidak dapat terhubung ke server. Periksa koneksi internet" | Benar-benar gagal jaringan (HP offline / situs belum selesai deploy) | Cek koneksi HP, cek status deploy di Vercel |

- **Setelah mengubah Environment Variables**, wajib **Redeploy** (variabel baru tidak berlaku pada deploy lama).
- Detail teknis ada di Vercel → Project → **Logs**.
- Tabel `sesi` (penyimpan login) dibuat otomatis kalau belum ada.
- `DB_SSL_VERIFY=1` (opsional) mengaktifkan verifikasi sertifikat server database.

## Catatan
- vercel-php adalah runtime komunitas (bukan resmi Vercel). Untuk pemakaian sungguhan di madrasah, hosting PHP + MySQL biasa
  (shared hosting/VPS) lebih sederhana dan lebih stabil.
- Cek syarat paket Vercel yang Anda pakai (paket gratis mensyaratkan pemakaian non-komersial/pribadi).
