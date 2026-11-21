<?php
/**
 * Pembantu modul Rekod Penyaluran (Fasa 3.1):
 *  - A3: kekalkan penapis senarai selepas Tambah / Edit / Padam (tanpa open redirect)
 *  - A4: validasi format musim
 */

/** Parameter penapis senarai yang dibenarkan dibawa semula ke penyaluran.php. */
const PENAPIS_KEKAL = ['wilayah', 'ppk', 'musim', 'model', 'status', 'jenis', 'q', 'page'];

/**
 * Bersihkan parameter penapis (tatasusunan atau query string) dan bina semula query string.
 * Hanya kunci dalam PENAPIS_KEKAL dan nilai skalar diterima; semuanya dikodkan semula oleh http_build_query.
 */
function qs_penapis(array|string $src): string
{
    if (is_string($src)) parse_str($src, $src);
    $out = [];
    foreach (PENAPIS_KEKAL as $k) {
        $v = $src[$k] ?? '';
        if (!is_string($v) && !is_int($v)) continue;
        $v = mb_substr(trim((string)$v), 0, 100);
        if (in_array($k, ['wilayah', 'ppk', 'page'], true) && !ctype_digit($v)) continue;   // ID / halaman: digit sahaja
        if ($v !== '') $out[$k] = $v;
    }
    return http_build_query($out);
}

/** URL senarai rekod dengan penapis — sentiasa penyaluran.php (tiada hos/laluan luaran). */
function url_senarai(string $qs = ''): string
{
    $qs = qs_penapis($qs);
    return 'penyaluran.php' . ($qs !== '' ? '?' . $qs : '');
}

/** Musim mula yang sah untuk rekod baharu/diubah: 1/YYYY atau 2/YYYY sahaja. */
function musim_format_sah(string $m): bool
{
    return (bool)preg_match('#^[12]/\d{4}$#', $m);
}

/* ---------- Perjanjian Fasa 2: simpan Perjanjian Asal / Sambungan ----------
 * Keputusan: O-2 (pautan ke perjanjian terdahulu terkini), O-3 (AKTIF / SAMBUNG / TAMAT),
 * O-4 (banyak-ke-banyak), O-5 (Perjanjian Asal Tiada Dalam Rekod), Q1 (pendahulu AKTIF atau
 * SAMBUNG dengan amaran; TAMAT ditolak), Q4 (PPK berbeza dibenarkan dengan amaran).
 * sql_mode pelayan TIDAK strict: semua nilai ENUM dan ID disahkan di sini sebelum ditulis.
 */

const JENIS_SAH = ['ASAL', 'SAMBUNG'];
/** Status yang boleh dipilih secara manual (SAMBUNG hanya ditetapkan oleh aliran sambungan). */
const STATUS_MANUAL = ['AKTIF', 'TAMAT'];
/** Status pendahulu yang boleh disambung (Q1). */
const STATUS_PENDAHULU_DIBENAR = ['AKTIF', 'SAMBUNG'];
const PENDAHULU_MAKS = 50;
/** Lajur penyaluran yang boleh diisi semasa rekod baharu disimpan (senarai putih). */
const KOLUM_REKOD_BAHARU = ['ppk_id', 'jenis', 'musim', 'musim_susun', 'no_blok', 'projek', 'model', 'bil_peladang',
    'luas_ha', 'kump', 'kadar_sewa', 'sewaan', 'modal_operasi', 'musim_pelaksanaan', 'bil_musim', 'fasa',
    'musim_tamat', 'status', 'tindakan', 'catatan'];

/**
 * Sahkan input pautan (tanpa akses DB): format ID, pendua, had, penanda Tiada Dalam Rekod,
 * eksklusif bersama, dan pautan diri.
 * @param mixed $rawIds  nilai mentah (cth $_POST['pendahulu_id']): null / '' / array ID
 * @param mixed $rawTiada nilai mentah penanda: '', '0', '1', 0, 1, true, false, null
 * @return array{ids:int[], tiada:bool, errors:string[]}
 */
function sahkan_input_sambungan(string $jenis, $rawIds, $rawTiada, ?int $idSendiri = null): array
{
    $errors = [];
    if (!in_array($jenis, JENIS_SAH, true)) $errors[] = 'Jenis tidak sah.';

    $ids = [];
    if ($rawIds !== null && $rawIds !== '' && $rawIds !== []) {
        if (!is_array($rawIds)) {
            $errors[] = 'Senarai perjanjian terdahulu tidak sah.';
        } else {
            foreach ($rawIds as $v) {
                $s = is_int($v) ? (string)$v : (is_string($v) ? trim($v) : null);
                if ($s === null || !preg_match('/^[1-9]\d{0,9}$/', $s) || (int)$s > 2147483647) {
                    $errors[] = 'ID perjanjian terdahulu tidak sah.';
                    $ids = [];
                    break;
                }
                $ids[] = (int)$s;
            }
            if ($ids && count($ids) !== count(array_unique($ids))) $errors[] = 'Perjanjian terdahulu yang sama dipilih lebih daripada sekali.';
            if (count($ids) > PENDAHULU_MAKS) $errors[] = 'Terlalu banyak perjanjian terdahulu dipilih (maksimum ' . PENDAHULU_MAKS . ').';
        }
    }

    if (in_array($rawTiada, ['1', 1, true], true)) $tiada = true;
    elseif (in_array($rawTiada, [null, '', '0', 0, false], true)) $tiada = false;
    else { $tiada = false; $errors[] = 'Penanda Perjanjian Asal Tiada Dalam Rekod tidak sah.'; }

    if ($jenis === 'ASAL') {
        if ($ids) $errors[] = 'Perjanjian Asal tidak boleh dipautkan kepada perjanjian terdahulu.';
        if ($tiada) $errors[] = 'Penanda Perjanjian Asal Tiada Dalam Rekod hanya untuk Perjanjian Sambungan.';
    } elseif ($jenis === 'SAMBUNG') {
        if ($ids && $tiada) $errors[] = 'Pilih perjanjian terdahulu ATAU tandakan Perjanjian Asal Tiada Dalam Rekod, bukan kedua-duanya.';
        if (!$ids && !$tiada && !$errors) $errors[] = 'Perjanjian Sambungan mesti dipautkan kepada perjanjian terdahulu, atau ditanda Perjanjian Asal Tiada Dalam Rekod.';
    }
    if ($idSendiri !== null && in_array($idSendiri, $ids, true)) $errors[] = 'Rekod tidak boleh dipautkan kepada dirinya sendiri.';

    return ['ids' => $errors ? [] : $ids, 'tiada' => $tiada, 'errors' => array_values(array_unique($errors))];
}

/**
 * Semak pendahulu dalam DB: wujud, status dibenarkan (TAMAT ditolak), amaran SAMBUNG / PPK berbeza.
 * $kunci = true: SELECT ... FOR UPDATE (mesti dalam transaksi).
 * @return array{errors:string[], amaran:string[], rekod:array<int,array>}
 */
function semak_pendahulu(PDO $pdo, array $ids, ?int $ppkId, bool $kunci = false): array
{
    $errors = $amaran = $rekod = [];
    if (!$ids) return compact('errors', 'amaran', 'rekod');
    $st = $pdo->prepare('SELECT id, ppk_id, jenis, status, musim, projek FROM penyaluran WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')' . ($kunci ? ' FOR UPDATE' : ''));
    $st->execute(array_values($ids));
    foreach ($st as $r) $rekod[(int)$r['id']] = $r;

    foreach ($ids as $id) {
        $r = $rekod[$id] ?? null;
        $label = $r ? "#$id ({$r['projek']}, musim {$r['musim']})" : "#$id";
        if (!$r) { $errors[] = "Perjanjian terdahulu $label tidak wujud."; continue; }
        if ($r['status'] === 'TAMAT') { $errors[] = "Perjanjian terdahulu $label berstatus TAMAT dan tidak boleh disambung."; continue; }
        if (!in_array($r['status'], STATUS_PENDAHULU_DIBENAR, true)) { $errors[] = "Status perjanjian terdahulu $label tidak sah."; continue; }
        if ($r['status'] === 'SAMBUNG') $amaran[] = "Perjanjian terdahulu $label telah pun disambung sebelum ini.";
        if ($ppkId !== null && (int)$r['ppk_id'] !== $ppkId) $amaran[] = "Perjanjian terdahulu $label adalah daripada PPK lain.";
    }
    return compact('errors', 'amaran', 'rekod');
}

/**
 * Simpan rekod penyaluran BAHARU (Perjanjian Asal atau Perjanjian Sambungan).
 * Sambungan: atomik — kunci pendahulu (FOR UPDATE), semak semula status, INSERT rekod
 * (jenis SAMBUNG, status AKTIF), INSERT pautan, UPDATE pendahulu → SAMBUNG, COMMIT.
 * Sebarang ralat: ROLLBACK penuh.
 *
 * @param array $data  nilai lajur (kunci dalam KOLUM_REKOD_BAHARU; '' sudah ditukar kepada null oleh pemanggil)
 * @return array{ok:bool, id:?int, errors:string[], amaran:string[]}
 */
function simpan_rekod_baharu(PDO $pdo, array $data, $rawIds, $rawTiada, ?int $oleh): array
{
    $gagal = fn(array $e) => ['ok' => false, 'id' => null, 'errors' => $e, 'amaran' => []];

    $tidakDikenali = array_diff(array_keys($data), KOLUM_REKOD_BAHARU);
    if ($tidakDikenali) return $gagal(['Medan tidak dikenali: ' . implode(', ', $tidakDikenali) . '.']);
    $jenis = (string)($data['jenis'] ?? '');
    $ppkId = isset($data['ppk_id']) && preg_match('/^[1-9]\d*$/', (string)$data['ppk_id']) ? (int)$data['ppk_id'] : null;
    $errors = [];
    if ($ppkId === null) $errors[] = 'PPK tidak sah.';
    if ($jenis === 'ASAL' && !in_array($data['status'] ?? null, STATUS_MANUAL, true)) $errors[] = 'Status tidak sah.';

    $in = sahkan_input_sambungan($jenis, $rawIds, $rawTiada);
    $errors = array_merge($errors, $in['errors']);
    if ($errors) return $gagal($errors);

    if ($jenis === 'SAMBUNG') {
        $data['status'] = 'AKTIF';                                   // O-3: sambungan baharu sentiasa AKTIF
        // Semakan awal (tanpa kunci) — mesej mesra sebelum transaksi dibuka
        $awal = semak_pendahulu($pdo, $in['ids'], $ppkId);
        if ($awal['errors']) return $gagal($awal['errors']);
    }
    $data['pendahulu_tiada_rekod'] = ($jenis === 'SAMBUNG' && $in['tiada']) ? 1 : 0;

    $kolum = array_keys($data);
    $sqlRekod = 'INSERT INTO penyaluran (' . implode(', ', $kolum) . ') VALUES (' . implode(', ', array_fill(0, count($kolum), '?')) . ')';

    if ($jenis === 'ASAL') {
        $pdo->prepare($sqlRekod)->execute(array_values($data));
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'errors' => [], 'amaran' => []];
    }

    $pdo->beginTransaction();
    try {
        $kunci = semak_pendahulu($pdo, $in['ids'], $ppkId, true);   // kunci + semak semula selepas dikunci
        if ($kunci['errors']) { $pdo->rollBack(); return $gagal($kunci['errors']); }

        $pdo->prepare($sqlRekod)->execute(array_values($data));
        $idBaru = (int)$pdo->lastInsertId();
        if (in_array($idBaru, $in['ids'], true)) throw new RuntimeException('Pautan diri dikesan.');

        if ($in['ids']) {
            $stPautan = $pdo->prepare('INSERT INTO penyaluran_sambungan (sambungan_id, pendahulu_id, status_pendahulu, dicipta_oleh) VALUES (?, ?, ?, ?)');
            foreach ($in['ids'] as $pid) $stPautan->execute([$idBaru, $pid, $kunci['rekod'][$pid]['status'], $oleh]);

            $tanda = implode(',', array_fill(0, count($in['ids']), '?'));
            $pdo->prepare("UPDATE penyaluran SET status = 'SAMBUNG' WHERE id IN ($tanda) AND status IN ('AKTIF', 'SAMBUNG')")
                ->execute($in['ids']);
            $st = $pdo->prepare("SELECT COUNT(*) FROM penyaluran WHERE id IN ($tanda) AND status = 'SAMBUNG'");
            $st->execute($in['ids']);
            if ((int)$st->fetchColumn() !== count($in['ids'])) throw new RuntimeException('Status perjanjian terdahulu gagal dikemas kini.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'id' => $idBaru, 'errors' => [], 'amaran' => $kunci['amaran']];
}

/**
 * Rekod yang terlibat dalam aliran sambungan (ada pautan sebagai sambungan atau pendahulu,
 * atau ditanda Tiada Dalam Rekod): jenis dan status tidak boleh diubah melalui borang Edit.
 */
function rekod_terkunci_sambungan(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('SELECT (SELECT pendahulu_tiada_rekod FROM penyaluran WHERE id = ?)
        OR EXISTS (SELECT 1 FROM penyaluran_sambungan WHERE sambungan_id = ? OR pendahulu_id = ?)');
    $st->execute([$id, $id, $id]);
    return (bool)$st->fetchColumn();
}

/* ---------- Perjanjian Fasa 3: paparan (baca sahaja) ---------- */

const LABEL_TIADA_REKOD = 'Perjanjian Asal Tiada Dalam Rekod';
/** Status → [label, kelas lencana]. SAMBUNG mempunyai lencana sendiri (bukan "Tamat"). */
const LENCANA_STATUS = [
    'AKTIF'   => ['Aktif', 'st-hijau', 'Perjanjian aktif'],
    'SAMBUNG' => ['Disambung', 'st-kuning', 'Status SAMBUNG: perjanjian ini telah disambung oleh perjanjian lain'],
    'TAMAT'   => ['Tamat', 'st-kelabu', 'Perjanjian tamat tanpa disambung'],
];

function lencana_status(?string $status): string
{
    [$label, $kelas, $tajuk] = LENCANA_STATUS[$status] ?? [$status ?: '-', 'st-merah', 'Status tidak dikenali'];
    return '<span class="st-badge ' . $kelas . '" title="' . e($tajuk) . '">' . e($label) . '</span>';
}

/** Rekod ada pautan sambungan (sebagai sambungan atau pendahulu) — tidak boleh dipadam (Q2). */
function rekod_ada_pautan(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM penyaluran_sambungan WHERE sambungan_id = ? OR pendahulu_id = ?)');
    $st->execute([$id, $id]);
    return (bool)$st->fetchColumn();
}

const SQL_RINGKAS_REKOD = 'SELECT x.id, x.ppk_id, p.kod ppk_kod, p.nama ppk_nama, x.jenis, x.projek, x.no_blok, x.model, x.kump, x.kadar_sewa,
    x.musim, x.musim_tamat, x.bil_peladang, x.luas_ha, x.status FROM penyaluran x JOIN ppk p ON p.id = x.ppk_id';

/** Bentuk satu rekod ringkas untuk JSON / paparan pilihan pendahulu. */
function format_ringkas_rekod(array $r): array
{
    $boleh = in_array($r['status'], STATUS_PENDAHULU_DIBENAR, true);
    return [
        'id' => (int)$r['id'], 'ppk_id' => (int)$r['ppk_id'], 'ppk' => $r['ppk_kod'] . ' ' . $r['ppk_nama'],
        'jenis' => $r['jenis'], 'projek' => (string)$r['projek'], 'no_blok' => (string)$r['no_blok'], 'model' => $r['model'],
        'kump' => (string)$r['kump'], 'kadar_sewa' => (float)$r['kadar_sewa'],
        'musim' => $r['musim'], 'musim_tamat' => (string)$r['musim_tamat'], 'bil_peladang' => (int)$r['bil_peladang'],
        'luas_ha' => (float)$r['luas_ha'], 'status' => $r['status'], 'boleh_pilih' => $boleh,
        'sebab' => $boleh ? '' : ($r['status'] === 'TAMAT' ? 'Berstatus TAMAT — tidak boleh disambung' : 'Status tidak sah'),
    ];
}

/**
 * Carian pendahulu (baca sahaja). Skop lalai = PPK pada borang, tetapi PPK tidak dikuatkuasakan
 * (ppk_id null = semua PPK). Rekod TAMAT dipaparkan tetapi tidak boleh dipilih.
 * @return array{rekod:array, lebih:bool}
 */
function cari_pendahulu(PDO $pdo, ?int $ppkId, string $projek, string $noBlok, string $model, int $had = 50): array
{
    $w = []; $p = [];
    $suka = fn(string $s) => '%' . addcslashes($s, '%_\\') . '%';
    if ($ppkId) { $w[] = 'x.ppk_id = ?'; $p[] = $ppkId; }
    if ($projek !== '') { $w[] = 'x.projek LIKE ?'; $p[] = $suka($projek); }
    if ($noBlok !== '') { $w[] = 'x.no_blok LIKE ?'; $p[] = $suka($noBlok); }
    if ($model !== '') { $w[] = 'x.model = ?'; $p[] = $model; }
    $st = $pdo->prepare(SQL_RINGKAS_REKOD . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
        . " ORDER BY (x.status = 'TAMAT'), x.musim_susun DESC, x.id DESC LIMIT " . ($had + 1));
    $st->execute($p);
    $rekod = array_map('format_ringkas_rekod', $st->fetchAll());
    return ['rekod' => array_slice($rekod, 0, $had), 'lebih' => count($rekod) > $had];
}

/** Rekod ringkas mengikut ID (susunan ID input dikekalkan; ID tidak wujud diabaikan). */
function rekod_ringkas_ikut_id(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) return [];
    $st = $pdo->prepare(SQL_RINGKAS_REKOD . ' WHERE x.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    $ikutId = [];
    foreach ($st as $r) $ikutId[(int)$r['id']] = format_ringkas_rekod($r);
    return array_values(array_filter(array_map(fn($i) => $ikutId[$i] ?? null, $ids)));
}

/**
 * Pautan bagi senarai rekod: ['pendahulu' => [id => [rekod...]], 'sambungan' => [id => [rekod...]]].
 * 'pendahulu' = perjanjian terdahulu rekod itu; 'sambungan' = perjanjian yang menyambung rekod itu.
 */
function pautan_rekod(PDO $pdo, array $ids): array
{
    $hasil = ['pendahulu' => [], 'sambungan' => []];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return $hasil;
    $tanda = implode(',', array_fill(0, count($ids), '?'));
    foreach (['pendahulu' => ['sambungan_id', 'pendahulu_id'], 'sambungan' => ['pendahulu_id', 'sambungan_id']] as $jenis => [$milik, $sasar]) {
        $st = $pdo->prepare("SELECT l.$milik milik, l.created_at dipautkan, r.id, r.ppk_id, pk.kod ppk_kod, pk.nama ppk_nama, r.jenis, r.projek,
                r.no_blok, r.model, r.musim, r.musim_tamat, r.bil_peladang, r.luas_ha, r.status
            FROM penyaluran_sambungan l JOIN penyaluran r ON r.id = l.$sasar JOIN ppk pk ON pk.id = r.ppk_id
            WHERE l.$milik IN ($tanda) ORDER BY r.musim_susun, r.id");
        $st->execute($ids);
        foreach ($st as $r) $hasil[$jenis][(int)$r['milik']][] = $r;
    }
    return $hasil;
}

/**
 * Keadaan pautan sesuatu rekod untuk paparan: 'tiada_rekod' (penanda O-5), 'dipautkan',
 * 'lama' (SAMBUNG sejarah tanpa pautan & tanpa penanda — belum dipautkan), atau null (ASAL tanpa pendahulu).
 */
function keadaan_pautan(array $r, int $bilPendahulu): ?string
{
    if ((int)($r['pendahulu_tiada_rekod'] ?? 0) === 1) return 'tiada_rekod';
    if ($bilPendahulu > 0) return 'dipautkan';
    return ($r['jenis'] ?? '') === 'SAMBUNG' ? 'lama' : null;
}

/** Teks ringkas satu rekod berpaut (untuk senarai pautan). */
function teks_rekod_pautan(array $r): string
{
    return "#{$r['id']} · {$r['ppk_kod']} · " . ($r['projek'] ?: '-') . " · {$r['model']} · musim {$r['musim']}";
}
