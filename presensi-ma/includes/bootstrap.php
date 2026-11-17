<?php
require_once __DIR__ . '/../config.php';
date_default_timezone_set(APP_TZ);

/**
 * Penanganan error terpusat. display_errors dimatikan, jadi tanpa ini pengguna hanya melihat
 * halaman kosong/HTML dan halaman login salah menyimpulkan "tidak dapat terhubung ke server".
 * Permintaan API/JSON dijawab JSON yang jelas; halaman biasa dijawab pesan singkat. Detail teknis ke log.
 */
function __pesan_galat(Throwable $e): string {
    $m = $e->getMessage();
    if ($e instanceof PDOException) {
        if (stripos($m, 'getaddrinfo') !== false || stripos($m, 'php_network') !== false || strpos($m, '2002') !== false || stripos($m, 'timed out') !== false)
            return 'Server tidak dapat menjangkau database. Periksa DB_HOST dan DB_PORT, serta izin akses jaringan database.';
        if (strpos($m, '1045') !== false) return 'Database menolak user/sandi. Periksa DB_USER dan DB_PASS.';
        if (strpos($m, '1049') !== false) return 'Nama database tidak ditemukan. Periksa DB_NAME.';
        if (stripos($m, 'ssl') !== false || stripos($m, 'tls') !== false || stripos($m, 'secure transport') !== false || strpos($m, '3159') !== false)
            return 'Koneksi database ditolak karena TLS/SSL. Atur DB_SSL=1 (database online) atau 0 (database lokal).';
        if (strpos($m, '42S02') !== false) return 'Tabel database belum ada. Jalankan database_online.sql pada database tersebut.';
        return 'Terjadi kesalahan pada database.';
    }
    return 'Terjadi kesalahan di server.';
}
set_exception_handler(function (Throwable $e) {
    error_log('[presensi] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $json = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0 || strpos($uri, '/api/') !== false;
    $pesan = __pesan_galat($e);
    if ($json) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'tipe' => 'err', 'pesan' => $pesan . ' (kesalahan server)'], JSON_UNESCAPED_UNICODE);
    } else {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kesalahan server</title>'
           . '<body style="font-family:sans-serif;padding:32px;max-width:560px;margin:auto"><h2>Terjadi kesalahan di server</h2><p>'
           . htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') . '</p><p><a href="/api/cek.php">Buka halaman diagnosa</a></p></body>';
    }
});

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';   // di balik proxy (Vercel, dll)
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (DB_SSL) {                                            // koneksi TLS ke database online
            $ca = DB_SSL_CA;
            if ($ca === '') foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $c) if (is_file($c)) { $ca = $c; break; }
            if ($ca !== '') $opt[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            else $opt[PDO::MYSQL_ATTR_SSL_CAPATH] = '/etc/ssl/certs';
            // Verifikasi sertifikat server hanya bila DB_SSL_VERIFY=1 (default mati agar tidak gagal di runtime tanpa bundel CA)
            $opt[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = getenv('DB_SSL_VERIFY') === '1';
        }
        $opt[PDO::ATTR_TIMEOUT] = 8;                             // jangan menggantung bila database tidak terjangkau
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opt);
        try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) { /* abaikan bila server tidak mendukung */ }
    }
    return $pdo;
}

/**
 * Sesi disimpan di database (bukan file) agar tetap hidup di hosting serverless
 * seperti Vercel, di mana tiap permintaan bisa dilayani server yang berbeda.
 */
class DbSession implements SessionHandlerInterface {
    private const SQL = 'CREATE TABLE IF NOT EXISTS sesi (id VARCHAR(128) NOT NULL PRIMARY KEY, data MEDIUMTEXT NOT NULL, diperbarui INT UNSIGNED NOT NULL, KEY idx_diperbarui (diperbarui)) ENGINE=InnoDB';
    private function run(callable $f) {
        try { return $f(); }
        catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;               // tabel belum ada -> buat otomatis
            db()->exec(self::SQL); return $f();
        }
    }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false {
        return $this->run(function () use ($id) {
            $st = db()->prepare('SELECT data FROM sesi WHERE id = ? AND diperbarui > ?');
            $st->execute([$id, time() - 8 * 3600]);
            $d = $st->fetchColumn();
            return $d === false ? '' : $d;
        });
    }
    public function write(string $id, string $data): bool {
        return $this->run(function () use ($id, $data) {
            db()->prepare('REPLACE INTO sesi (id, data, diperbarui) VALUES (?,?,?)')->execute([$id, $data, time()]);
            return true;
        });
    }
    public function destroy(string $id): bool {
        $this->run(fn() => db()->prepare('DELETE FROM sesi WHERE id = ?')->execute([$id]));
        return true;
    }
    public function gc(int $max): int|false {
        return $this->run(fn() => db()->prepare('DELETE FROM sesi WHERE diperbarui < ?')->execute([time() - 8 * 3600]) ? 1 : 0);
    }
}

if (!defined('SKIP_SESSION') && session_status() === PHP_SESSION_NONE) {
    session_set_save_handler(new DbSession(), true);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
    session_name('presensi_ma');
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
