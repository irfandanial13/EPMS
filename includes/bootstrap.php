<?php
require_once __DIR__ . '/../config.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

/* ---------- Log ralat: ralat teknikal ke fail, bukan ke skrin ---------- */
if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_DIR . '/php-error.log');
error_reporting(E_ALL);

/** Tulis mesej ke log aplikasi. Jangan sekali-kali hantar kata laluan ke sini. */
function app_log(string $msg, string $fail = 'app.log'): void
{
    $user = $_SESSION['user']['username'] ?? '-';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    @file_put_contents(LOG_DIR . '/' . $fail, date('Y-m-d H:i:s') . " [$ip] [$user] $msg\n", FILE_APPEND | LOCK_EX);
}

/** Papar mesej ralat umum kepada pengguna dengan nombor rujukan; butiran disimpan dalam log. */
function ralat_umum(Throwable $e, int $kod = 500): void
{
    $ref = strtoupper(bin2hex(random_bytes(4)));
    app_log("REF $ref " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'error.log');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Ralat (rujukan $ref). Lihat storage/logs/error.log\n");
        exit(1);
    }
    if (!headers_sent()) http_response_code($kod);
    echo '<!doctype html><meta charset="utf-8"><title>Ralat</title>'
        . '<div style="font-family:sans-serif;max-width:560px;margin:60px auto">'
        . '<h2>Maaf, berlaku ralat sistem.</h2>'
        . '<p>Sila cuba lagi. Jika masalah berterusan, maklumkan kepada pentadbir dengan nombor rujukan: <b>' . $ref . '</b>.</p>'
        . '<p><a href="index.php">Kembali ke Dashboard</a></p></div>';
    exit;
}
set_exception_handler('ralat_umum');

/* ---------- Sesi yang dikukuhkan ---------- */
function https_aktif(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? null) == 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('SIPADISESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => https_aktif(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

const MODELS = [
    'SP'    => 'Sewaan / Pajakan',
    'BP'    => 'Bayaran Pengurusan',
    'PK'    => 'Perkongsian Keuntungan',
    'TP'    => 'Tanah PPK',
    'LKPPK' => 'Ladang Kontrak PPK',
    'LKSLB' => 'Ladang Kontrak Skim Ladang Benih',
    'EP'    => 'Entiti Pengurusan',
];

// Warna kategori tetap ikut model (susunan tervalidasi CVD)
const MODEL_COLORS = [
    'SP' => '#2a78d6', 'BP' => '#eb6834', 'PK' => '#1baf7a', 'TP' => '#eda100',
    'LKPPK' => '#e87ba4', 'LKSLB' => '#008300', 'EP' => '#6250d6',
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            // Butiran sebenar ke log sahaja; pengguna hanya melihat mesej umum
            ralat_umum(new RuntimeException('Sambungan DB gagal: ' . $e->getMessage(), 0, $e), 503);
        }
    }
    return $pdo;
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function rm($v): string  { return number_format((float)$v, 2); }
function ha($v): string  { return number_format((float)$v, 3); }
function nint($v): string { return number_format((float)$v, 0); }

function musim_susun(string $m): int
{
    return preg_match('#^\s*(\d)\s*/\s*(\d{4})#', $m, $x) ? (int)$x[2] * 10 + (int)$x[1] : 0;
}

/* ---------- Auth ---------- */
function current_user(): ?array { return $_SESSION['user'] ?? null; }

/**
 * Wajib log masuk. Juga:
 *  - log keluar automatik selepas SESSION_IDLE_MINIT minit tidak aktif
 *  - paksa tukar kata laluan jika akaun ditanda wajib_tukar
 */
function require_login(bool $benarkanWajibTukar = false): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
    $now = time();
    $last = $_SESSION['last_activity'] ?? $now;
    if ($now - $last > SESSION_IDLE_MINIT * 60) {
        app_log('Sesi tamat (tidak aktif)');
        tamatkan_sesi();
        session_start();
        flash('Sesi anda telah tamat kerana tidak aktif. Sila log masuk semula.', 'warning');
        header('Location: login.php');
        exit;
    }
    $_SESSION['last_activity'] = $now;

    if (!$benarkanWajibTukar && !empty($_SESSION['user']['wajib_tukar'])) {
        header('Location: tukar_katalaluan.php');
        exit;
    }
}

/** Musnahkan sesi semasa sepenuhnya (data + cookie). */
function tamatkan_sesi(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax']);
    }
    session_destroy();
}

/** Peraturan kata laluan; pulangkan senarai ralat (kosong jika sah). */
function semak_katalaluan(string $baru, string $sahkan, string $username): array
{
    $err = [];
    if (strlen($baru) < 8) $err[] = 'Kata laluan mesti sekurang-kurangnya 8 aksara.';
    if (!preg_match('/[A-Za-z]/', $baru) || !preg_match('/\d/', $baru)) $err[] = 'Kata laluan mesti mengandungi huruf dan nombor.';
    if (strcasecmp($baru, $username) === 0) $err[] = 'Kata laluan tidak boleh sama dengan nama pengguna.';
    if ($baru !== $sahkan) $err[] = 'Pengesahan kata laluan tidak sepadan.';
    return $err;
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Token tidak sah. Sila muat semula halaman.');
    }
}

/* ---------- Flash ---------- */
function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function redirect(string $url): void { header("Location: $url"); exit; }

/**
 * Skop pengiraan (ikut Excel asal):
 *  kumulatif = JUMLAH ASAL (semua rekod asal termasuk yang tamat; sambung perjanjian tidak dikira dua kali)
 *  semasa    = JUMLAH SEMASA (rekod aktif: asal tolak tamat + sambung perjanjian aktif)
 */
function skop_sql(string $skop, string $alias = 'x'): string
{
    return $skop === 'semasa' ? "$alias.status = 'AKTIF'" : "$alias.jenis = 'ASAL'";
}

/* ---------- Data rujukan ---------- */
function senarai_wilayah(): array
{
    return db()->query('SELECT * FROM wilayah ORDER BY id')->fetchAll();
}
function senarai_ppk(): array
{
    return db()->query('SELECT p.*, w.kod AS wilayah_kod FROM ppk p JOIN wilayah w ON w.id = p.wilayah_id ORDER BY p.wilayah_id, p.susunan, p.kod')->fetchAll();
}
function senarai_musim(): array
{
    return db()->query('SELECT DISTINCT musim FROM penyaluran WHERE musim_susun > 0 ORDER BY musim_susun')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Bina klausa WHERE daripada penapis GET yang dikongsi.
 * @return array [string $where, array $params]
 */
function filter_where(array $f): array
{
    $w = ['1=1'];
    $p = [];
    if (!empty($f['wilayah'])) { $w[] = 'p.wilayah_id = ?'; $p[] = (int)$f['wilayah']; }
    if (!empty($f['ppk']))     { $w[] = 'x.ppk_id = ?';     $p[] = (int)$f['ppk']; }
    if (!empty($f['musim']))   { $w[] = 'x.musim = ?';      $p[] = $f['musim']; }
    if (!empty($f['model']) && isset(MODELS[$f['model']])) { $w[] = 'x.model = ?'; $p[] = $f['model']; }
    if (!empty($f['status']) && in_array($f['status'], ['AKTIF', 'TAMAT'], true)) { $w[] = 'x.status = ?'; $p[] = $f['status']; }
    if (!empty($f['jenis']) && in_array($f['jenis'], ['ASAL', 'SAMBUNG'], true))  { $w[] = 'x.jenis = ?';  $p[] = $f['jenis']; }
    if (!empty($f['q'])) {
        $w[] = '(x.projek LIKE ? OR x.no_blok LIKE ? OR x.fasa LIKE ?)';
        $q = '%' . $f['q'] . '%';
        array_push($p, $q, $q, $q);
    }
    return [implode(' AND ', $w), $p];
}
