<?php
/**
 * Backup & restore pangkalan data EPMS menggunakan mysqldump / mysql (XAMPP).
 *
 * Setiap backup = 2 fail dalam BACKUP_DIR (dilindungi storage/.htaccess):
 *   sipadi_YYYYmmdd_HHiiss_<jenis>.sql   — dump SQL
 *   sipadi_YYYYmmdd_HHiiss_<jenis>.json  — metadata: tarikh, jenis, oleh, saiz, sha256, bil. rekod setiap jadual
 *
 * Metadata disimpan sebagai fail (bukan dalam DB) supaya ia tidak hilang/berubah apabila DB dipulihkan.
 * Kelayakan DB dihantar melalui fail --defaults-extra-file sementara, bukan pada baris arahan.
 */
require_once __DIR__ . '/migrasi.php';

const BACKUP_JENIS = [
    'manual'    => 'Manual',
    'jadual'    => 'Berjadual',
    'pra-pulih' => 'Automatik (sebelum restore)',
    'pra-fasa1' => 'Sebelum Fasa 1',
];

function backup_dir(): string
{
    if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0775, true)) {
        throw new RuntimeException('Tidak dapat mencipta folder backup: ' . BACKUP_DIR);
    }
    return BACKUP_DIR;
}

/** Nama fail backup yang sah sahaja (elak path traversal). */
function backup_nama_sah(string $fail): bool
{
    return (bool)preg_match('/^sipadi_\d{8}_\d{6}_[a-z0-9-]+(_\d+)?\.sql$/', $fail);
}

function backup_path(string $fail): string
{
    if (!backup_nama_sah($fail)) throw new InvalidArgumentException('Nama fail backup tidak sah.');
    return backup_dir() . '/' . $fail;
}

function mysql_bin(string $nama): string
{
    $p = rtrim(MYSQL_BIN_DIR, '/\\') . '/' . $nama . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    if (!is_file($p)) throw new RuntimeException("Program $nama tidak dijumpai di " . MYSQL_BIN_DIR . ' (semak MYSQL_BIN_DIR dalam config.php).');
    return $p;
}

/** Fail kelayakan sementara untuk mysqldump/mysql. Pemanggil mesti unlink(). */
function mysql_cnf(): string
{
    $f = tempnam(sys_get_temp_dir(), 'sipcnf');
    $q = fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$v) . '"';
    file_put_contents($f, "[client]\nuser=" . $q(DB_USER) . "\npassword=" . $q(DB_PASS)
        . "\nhost=" . $q(DB_HOST) . "\nport=" . (int)DB_PORT . "\n");
    return $f;
}

/**
 * Jalankan program luaran. $stdinFail (jika ada) disalurkan ke stdin.
 * @return array [int $kodKeluar, string $stderr]
 */
function jalan_proses(array $cmd, ?string $stdinFail = null): array
{
    $spec = [0 => $stdinFail ? ['pipe', 'r'] : ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
             1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('Gagal menjalankan ' . basename($cmd[0]));
    if ($stdinFail) {
        $in = fopen($stdinFail, 'rb');
        while (!feof($in)) fwrite($pipes[0], fread($in, 1 << 16));
        fclose($in);
        fclose($pipes[0]);
    }
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), trim($err)];
}

/** Bilangan rekod setiap jadual dalam DB semasa. */
function kira_jadual(PDO $pdo): array
{
    $st = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name");
    $st->execute([DB_NAME]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $out[$t] = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $t) . '`')->fetchColumn();
    }
    return $out;
}

function backup_meta(string $fail): ?array
{
    $j = preg_replace('/\.sql$/', '.json', backup_path($fail));
    return is_file($j) ? json_decode((string)file_get_contents($j), true) : null;
}

/** Semak fail backup: wujud, lengkap (penanda akhir mysqldump) dan sha256 sepadan dengan metadata. */
function semak_fail_backup(string $fail): array
{
    $p = backup_path($fail);
    if (!is_file($p)) return ['ok' => false, 'mesej' => 'Fail tidak wujud.'];
    $meta = backup_meta($fail);
    if (!$meta) return ['ok' => false, 'mesej' => 'Metadata (.json) tiada.'];
    $fh = fopen($p, 'rb');
    fseek($fh, max(0, filesize($p) - 200));
    $ekor = fread($fh, 200);
    fclose($fh);
    if (strpos($ekor, '-- Dump completed') === false) return ['ok' => false, 'mesej' => 'Fail tidak lengkap (penanda akhir tiada).'];
    if (!hash_equals((string)($meta['sha256'] ?? ''), hash_file('sha256', $p))) return ['ok' => false, 'mesej' => 'Checksum SHA-256 tidak sepadan — fail mungkin telah diubah atau rosak.'];
    return ['ok' => true, 'mesej' => 'Sah'];
}

/**
 * Cipta backup baharu.
 * @return array metadata backup
 */
function buat_backup(string $jenis, string $oleh, string $catatan = ''): array
{
    if (!isset(BACKUP_JENIS[$jenis])) throw new InvalidArgumentException('Jenis backup tidak sah.');
    $dir = backup_dir();
    $asas = 'sipadi_' . date('Ymd_His') . '_' . $jenis;
    $fail = "$asas.sql";
    for ($i = 2; is_file("$dir/$fail"); $i++) $fail = "{$asas}_$i.sql";
    $path = "$dir/$fail";

    $sebelum = kira_jadual(db());
    $cnf = mysql_cnf();
    try {
        [$kod, $err] = jalan_proses([mysql_bin('mysqldump'), "--defaults-extra-file=$cnf",
            '--single-transaction', '--routines', '--triggers', '--add-drop-table',
            '--default-character-set=utf8mb4', "--result-file=$path", DB_NAME]);
    } finally {
        @unlink($cnf);
    }
    if ($kod !== 0 || !is_file($path) || filesize($path) === 0) {
        @unlink($path);
        throw new RuntimeException("mysqldump gagal (kod $kod): $err");
    }
    $selepas = kira_jadual(db());

    $meta = [
        'fail'    => $fail,
        'dicipta' => date('Y-m-d H:i:s'),
        'jenis'   => $jenis,
        'oleh'    => $oleh,
        'saiz'    => filesize($path),
        'sha256'  => hash_file('sha256', $path),
        'jadual'  => $sebelum,
        'catatan' => mb_substr($catatan, 0, 200),
    ];
    if ($sebelum !== $selepas) $meta['amaran'] = 'Data berubah semasa backup dijalankan; bilangan rekod mungkin berbeza sedikit.';
    file_put_contents(preg_replace('/\.sql$/', '.json', $path), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $semak = semak_fail_backup($fail);
    if (!$semak['ok']) throw new RuntimeException('Backup dicipta tetapi gagal semakan: ' . $semak['mesej']);

    app_log("Backup dicipta: $fail ({$meta['saiz']} bait, jenis $jenis)", 'backup.log');
    return $meta;
}

/** Senarai backup, terbaru dahulu. */
function senarai_backup(): array
{
    $list = [];
    foreach (glob(backup_dir() . '/sipadi_*.sql') ?: [] as $p) {
        $fail = basename($p);
        if (!backup_nama_sah($fail)) continue;
        $meta = backup_meta($fail) ?? [];
        $list[] = $meta + ['fail' => $fail, 'dicipta' => date('Y-m-d H:i:s', filemtime($p)), 'jenis' => '?', 'oleh' => '?',
                           'saiz' => filesize($p), 'jadual' => []];
    }
    usort($list, fn($a, $b) => strcmp($b['fail'], $a['fail']));
    return $list;
}

/* ---------- Status backup terakhir (Fasa 3.1 A5) ---------- */

/** Amaran dipaparkan jika tiada backup berjaya dalam tempoh ini (jam). Ditetapkan dalam config.php (diluluskan: 48). */
if (!defined('BACKUP_AMARAN_JAM')) define('BACKUP_AMARAN_JAM', 48);

function backup_status_path(): string { return LOG_DIR . '/backup_status.json'; }

/**
 * Rekod keputusan cubaan backup terakhir (berjaya / gagal) untuk paparan halaman Backup.
 * Fail disimpan dalam storage/logs (tidak boleh dicapai melalui pelayar). Mesej ralat penuh hanya dalam log.
 */
function catat_status_backup(string $jenis, bool $ok, string $mesej = '', string $fail = ''): void
{
    try {
        if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
        $s = is_file(backup_status_path()) ? (json_decode((string)file_get_contents(backup_status_path()), true) ?: []) : [];
        $rekod = ['masa' => date('Y-m-d H:i:s'), 'jenis' => $jenis, 'ok' => $ok, 'fail' => $fail];
        $s['terakhir'] = $rekod;
        $s[$ok ? 'berjaya_terakhir' : 'gagal_terakhir'] = $rekod;
        if ($jenis === 'jadual') $s['jadual_terakhir'] = $rekod;
        @file_put_contents(backup_status_path(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (!$ok) app_log("Backup GAGAL ($jenis): $mesej", 'backup.log');
    } catch (Throwable $e) {
        app_log('Gagal merekod status backup: ' . $e->getMessage(), 'error.log');
    }
}

/**
 * Ringkasan status backup untuk halaman Backup.
 * @return array ['terkini'=>?array, 'jadual'=>?array, 'cubaan'=>?array, 'amaran'=>[[jenis, mesej], ...]]
 */
function status_backup(array $senarai): array
{
    $s = is_file(backup_status_path()) ? (json_decode((string)file_get_contents(backup_status_path()), true) ?: []) : [];
    $terkini = $senarai[0] ?? null;   // senarai_backup(): terbaru dahulu
    $jadual = null;
    foreach ($senarai as $b) if (($b['jenis'] ?? '') === 'jadual') { $jadual = $b; break; }

    $amaran = [];
    $cubaan = $s['terakhir'] ?? null;
    if ($cubaan && !$cubaan['ok'] && (!$terkini || strtotime($cubaan['masa']) >= strtotime($terkini['dicipta']))) {
        $amaran[] = ['danger', 'Cubaan backup terakhir (' . date('d/m/Y H:i', strtotime($cubaan['masa'])) . ', '
            . (BACKUP_JENIS[$cubaan['jenis']] ?? $cubaan['jenis']) . ') GAGAL. Butiran dalam log backup / ralat sistem.'];
    }
    if (!$terkini) {
        $amaran[] = ['danger', 'Tiada backup ditemui. Sila buat backup sekarang.'];
    } elseif (time() - strtotime($terkini['dicipta']) > BACKUP_AMARAN_JAM * 3600) {
        $amaran[] = ['warning', 'Backup terakhir sudah melebihi ' . BACKUP_AMARAN_JAM . ' jam. Sila buat backup baharu.'];
    }
    if (!$jadual || time() - strtotime($jadual['dicipta']) > BACKUP_AMARAN_JAM * 3600) {
        $amaran[] = ['warning', $jadual
            ? 'Backup berjadual tidak berjalan sejak ' . date('d/m/Y H:i', strtotime($jadual['dicipta'])) . '. Semak Windows Task Scheduler.'
            : 'Backup berjadual belum pernah berjalan. Task Scheduler belum didaftarkan (lihat README).'];
    }
    return ['terkini' => $terkini, 'jadual' => $jadual, 'cubaan' => $cubaan, 'amaran' => $amaran];
}

/**
 * Rancang retention (tiada pemadaman): tentukan setiap fail backup dalam BACKUP_DIR sama ada KEKAL atau PADAM, beserta sebab.
 * Polisi (diluluskan pemilik): backup BERJADUAL lebih tua daripada BACKUP_SIMPAN_JADUAL hari layak dipadam. Fail dipadam HANYA jika:
 *   - nama fail sah dan berakhir dengan jenis "jadual", DAN metadata .json juga menyatakan jenis "jadual";
 *   - tarikh pada nama fail ialah tarikh sebenar yang sah, DAN sepadan (±1 jam) dengan tarikh "dicipta" dalam metadata;
 *   - tarikh tidak di masa hadapan, dan umurnya melebihi had;
 *   - fail lulus semakan integriti (tidak dipadam jika rosak — disimpan untuk siasatan);
 *   - ia BUKAN backup berjadual sah yang terbaru (sekurang-kurangnya satu sentiasa disimpan untuk restore).
 * Backup manual, pra-pulih, pra-fasa, fail tidak dikenali dan fail lain tidak pernah dipadam.
 * @return array senarai ['fail','tindakan'=>'PADAM'|'KEKAL','sebab']
 */
function rancang_pangkas_backup(?int $kini = null): array
{
    $kini ??= time();
    $hari = (int)BACKUP_SIMPAN_JADUAL;
    $pelan = [];
    $layak = [];
    $jadualSah = [];   // [fail => masa] backup berjadual yang tarikh & integritinya disahkan
    foreach (glob(backup_dir() . '/*') ?: [] as $p) {
        if (!is_file($p) || str_ends_with($p, '.json')) continue;
        $fail = basename($p);
        $kekal = function (string $sebab) use (&$pelan, $fail) { $pelan[$fail] = ['fail' => $fail, 'tindakan' => 'KEKAL', 'sebab' => $sebab]; };
        if (!backup_nama_sah($fail)) { $kekal('bukan fail backup sistem / tidak dikenali'); continue; }
        if (!preg_match('/^sipadi_(\d{8}_\d{6})_([a-z0-9-]+?)(?:_\d+)?\.sql$/', $fail, $m)) { $kekal('format nama tidak dikenali'); continue; }
        if ($m[2] !== 'jadual') { $kekal('backup ' . (BACKUP_JENIS[$m[2]] ?? $m[2]) . ' — tidak tertakluk kepada retention'); continue; }
        $meta = backup_meta($fail);
        if (!$meta) { $kekal('metadata tiada — tarikh tidak dapat disahkan'); continue; }
        if (($meta['jenis'] ?? '') !== 'jadual') { $kekal('jenis dalam metadata bukan jadual'); continue; }
        $dt = DateTime::createFromFormat('!Ymd_His', $m[1]);
        $tNama = ($dt && $dt->format('Ymd_His') === $m[1]) ? $dt->getTimestamp() : null;
        $tMeta = isset($meta['dicipta']) ? strtotime((string)$meta['dicipta']) : false;
        if ($tNama === null || $tMeta === false) { $kekal('tarikh tidak sah — tidak dapat disahkan'); continue; }
        if (abs($tNama - $tMeta) > 3600) { $kekal('tarikh nama fail dan metadata tidak sepadan'); continue; }
        if ($tNama > $kini + 300) { $kekal('tarikh di masa hadapan'); continue; }
        if (!semak_fail_backup($fail)['ok']) { $kekal('gagal semakan integriti — disimpan untuk siasatan'); continue; }
        $jadualSah[$fail] = $tNama;
        $umur = intdiv($kini - $tNama, 86400);
        if ($hari <= 0) { $kekal('retention dimatikan (BACKUP_SIMPAN_JADUAL = 0)'); continue; }
        if ($kini - $tNama <= $hari * 86400) { $kekal("berjadual, umur $umur hari (had $hari hari)"); continue; }
        $layak[$fail] = "berjadual, umur $umur hari melebihi had $hari hari";
    }
    arsort($jadualSah);
    $terbaru = array_key_first($jadualSah);
    foreach ($layak as $fail => $sebab) {
        $pelan[$fail] = $fail === $terbaru
            ? ['fail' => $fail, 'tindakan' => 'KEKAL', 'sebab' => 'backup berjadual sah terbaru — sentiasa disimpan']
            : ['fail' => $fail, 'tindakan' => 'PADAM', 'sebab' => $sebab];
    }
    ksort($pelan);
    return array_values($pelan);
}

/** Laksanakan retention ikut rancang_pangkas_backup(). Hanya fail .sql + .json pasangannya dalam BACKUP_DIR. */
function pangkas_backup_jadual(): array
{
    $dibuang = [];
    foreach (rancang_pangkas_backup() as $r) {
        if ($r['tindakan'] !== 'PADAM') continue;
        $p = backup_path($r['fail']);   // sahkan semula nama (elak path traversal)
        if (@unlink($p)) {
            @unlink(preg_replace('/\.sql$/', '.json', $p));
            $dibuang[] = $r['fail'];
            app_log("Retention: dibuang {$r['fail']} ({$r['sebab']})", 'backup.log');
        } else {
            app_log("Retention: GAGAL membuang {$r['fail']}", 'backup.log');
        }
    }
    app_log('Retention selesai: ' . count($dibuang) . ' fail dibuang (had ' . BACKUP_SIMPAN_JADUAL . ' hari, backup berjadual sahaja).', 'backup.log');
    return $dibuang;
}

/**
 * Pulihkan DB daripada backup.
 *  1. Semak integriti fail (SHA-256 + penanda akhir)
 *  2. Backup automatik keadaan semasa (pra-pulih) — boleh digunakan untuk undur semula
 *  3. Import dump ke DB
 *  4. Jalankan migrasi skema (backup lama mungkin belum ada medan baharu)
 *  5. Semak integriti: bilangan rekod setiap jadual == metadata backup
 */
function pulih_backup(string $fail, string $oleh): array
{
    $semak = semak_fail_backup($fail);
    if (!$semak['ok']) throw new RuntimeException('Backup tidak sah: ' . $semak['mesej']);
    $meta = backup_meta($fail);

    $pra = buat_backup('pra-pulih', $oleh, "Sebelum restore $fail");
    app_log("Restore bermula: $fail (backup pra-pulih: {$pra['fail']})", 'backup.log');

    $cnf = mysql_cnf();
    try {
        [$kod, $err] = jalan_proses([mysql_bin('mysql'), "--defaults-extra-file=$cnf",
            '--default-character-set=utf8mb4', DB_NAME], backup_path($fail));
    } finally {
        @unlink($cnf);
    }
    if ($kod !== 0) {
        app_log("Restore GAGAL: $fail (kod $kod): $err", 'backup.log');
        throw new RuntimeException("Proses restore gagal (kod $kod). Pangkalan data mungkin separa dipulihkan — "
            . "pulihkan semula backup pra-pulih {$pra['fail']}. Butiran dalam log.");
    }

    $migrasi = pastikan_skema(db());
    $ditanda = tanda_katalaluan_lalai(db());

    $sebenar = kira_jadual(db());
    $semakan = [];
    $ok = true;
    foreach ($meta['jadual'] ?? [] as $t => $jangka) {
        $s = $sebenar[$t] ?? null;
        $semakan[$t] = ['jangka' => $jangka, 'sebenar' => $s, 'ok' => $s === (int)$jangka];
        $ok = $ok && $semakan[$t]['ok'];
    }
    app_log("Restore selesai: $fail; integriti " . ($ok ? 'LULUS' : 'GAGAL') . '; ' . json_encode($semakan), 'backup.log');

    return ['ok' => $ok, 'fail' => $fail, 'pra_pulih' => $pra['fail'], 'semakan' => $semakan,
            'migrasi' => $migrasi, 'wajib_tukar' => $ditanda];
}
