<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI sahaja'); }
require_once __DIR__ . '/../includes/penyaluran_lib.php';
require_once __DIR__ . '/../includes/laporan_lib.php';
require_once __DIR__ . '/lib/semak.php';

echo "jana_musim_pelaksanaan\n";
foreach ([
    ['2/2025', '1/2027', '2/2025-1/2027', 4],
    ['1/2024', '1/2026', '1/2024-1/2026', 5],
    ['1/2026', '2/2028', '1/2026-2/2028', 6],
    ['1/2026', '1/2026', '1/2026-1/2026', 1],
] as [$mula, $tamat, $pelaksanaan, $bil]) {
    $j = jana_musim_pelaksanaan($mula, $tamat);
    semak("$mula → $tamat = $pelaksanaan · $bil", $j['ok'] === true && $j['pelaksanaan'] === $pelaksanaan && $j['bil'] === $bil, json_encode($j, JSON_UNESCAPED_UNICODE));
}
foreach ([
    ['', '2/2028'], ['1/2026', ''], ['3/2026', '2/2028'], ['1/1999', '2/2028'],
    ['1/2026', '2/2101'], ['2/2027', '1/2026'], ['1/2026', 'x/2028'], ['1/2026', '2/2028 '],
] as [$mula, $tamat]) {
    $j = jana_musim_pelaksanaan($mula, $tamat);
    semak('ditolak: ' . json_encode([$mula, $tamat], JSON_UNESCAPED_UNICODE), $j['ok'] === false && $j['errors'] !== [] && $j['pelaksanaan'] === null && $j['bil'] === null);
}
semak('musim_indeks: 2/2025 → 1/2027 ialah 3 langkah', musim_indeks('2/2025') !== null && musim_indeks('1/2027') - musim_indeks('2/2025') === 3);
semak('musim_indeks: format/tahun tidak sah → null', musim_indeks('3/2026') === null && musim_indeks('1/1999') === null && musim_indeks('1/2026 ') === null);

echo "\nValidasi julat musim semasa simpan\n";
$asal = ['musim_pelaksanaan' => '2/2025 - 1/2027', 'bil_musim' => 'lama'];
$h = semak_julat_musim('2/2025', '1/2027', true);
semak('format standard diubah: sah & dijana', $h['ok'] && $h['errors'] === [] && $h['pelaksanaan'] === '2/2025-1/2027' && $h['bil'] === '4' && $h['diubah'] === true, json_encode($h, JSON_UNESCAPED_UNICODE));
$h = semak_julat_musim('0/2025', '1/2027', false, $asal);
semak('format lama, tiada medan diubah: nilai lama dikekalkan', $h['ok'] && $h['errors'] === [] && $h['pelaksanaan'] === '2/2025 - 1/2027' && $h['bil'] === 'lama' && $h['diubah'] === false, json_encode($h, JSON_UNESCAPED_UNICODE));
$h = semak_julat_musim('3/2026', '2/2028', true);
semak('nombor musim tidak sah ditolak', !$h['ok'] && $h['pelaksanaan'] === null && $h['bil'] === null && str_contains(implode(' ', $h['errors']), 'nombor musim mesti 1 atau 2'), json_encode($h, JSON_UNESCAPED_UNICODE));
$h = semak_julat_musim('1/1999', '2/2028', true);
semak('tahun tidak sah ditolak', !$h['ok'] && $h['pelaksanaan'] === null && str_contains(implode(' ', $h['errors']), 'tahun mesti antara'), json_encode($h, JSON_UNESCAPED_UNICODE));
$h = semak_julat_musim('1/2026', '1/2027', true);
semak('Musim Mula diubah: kedua-dua nilai disahkan & dijana semula', $h['ok'] && $h['pelaksanaan'] === '1/2026-1/2027' && $h['bil'] === '3', json_encode($h, JSON_UNESCAPED_UNICODE));
$h = semak_julat_musim('2/2025', '2/2026', true);
semak('Musim Tamat diubah: susunan sah dijana semula', $h['ok'] && $h['pelaksanaan'] === '2/2025-2/2026' && $h['bil'] === '3', json_encode($h, JSON_UNESCAPED_UNICODE));
$h = semak_julat_musim('0/2025', '1/2027', true, $asal);
semak('Musim Tamat diubah tetapi Musim Mula format lama: ditolak, tidak guna nilai lama', !$h['ok'] && $h['pelaksanaan'] === null && $h['bil'] === null && str_contains(implode(' ', $h['errors']), 'Musim Mula'), json_encode($h, JSON_UNESCAPED_UNICODE));
$h = semak_julat_musim('1/2024', '2/2026', true);
semak('kedua-dua medan diubah: sah & dijana', $h['ok'] && $h['pelaksanaan'] === '1/2024-2/2026' && $h['bil'] === '6', json_encode($h, JSON_UNESCAPED_UNICODE));
$h = semak_julat_musim('2/2025', '1/2027', false, $asal);
semak('tiada medan diubah walaupun format standard: nilai lama dikekalkan', $h['ok'] && $h['pelaksanaan'] === '2/2025 - 1/2027' && $h['bil'] === 'lama', json_encode($h, JSON_UNESCAPED_UNICODE));

echo "\nPenapis & susunan rekod\n";
$qs = qs_penapis(['q' => '  projek A  ', 'wilayah' => '3', 'tahun' => '2026', 'susun' => 'jumlah', 'arah' => 'desc', 'saiz' => '25', 'page' => '2', 'eksport' => 'xlsx', 'benar' => '1', 'laser' => '<x>']);
parse_str($qs, $nilai);
$kunci = array_keys($nilai); $dibenarkan = ['wilayah', 'tahun', 'susun', 'arah', 'saiz', 'q', 'page']; sort($kunci); sort($dibenarkan);
semak('qs_penapis: hanya kunci dibenarkan dikekalkan', $kunci === $dibenarkan, implode(',', $kunci));
semak('qs_penapis: nilai dibilang dan dikodkan semula', $nilai['q'] === 'projek A' && $nilai['tahun'] === '2026' && $nilai['susun'] === 'jumlah' && $nilai['arah'] === 'desc' && $nilai['saiz'] === '25' && $nilai['page'] === '2');
parse_str(qs_penapis(['susun' => 'id;DROP', 'arah' => 'sideways', 'tahun' => '202a', 'q' => '']), $buruk);
semak('qs_penapis: susun/arah/tahun tidak sah dan carian kosong dibuang', $buruk === []);
semak('SUSUNAN_REKOD: ungkapan SQL hanya senarai putih', (function () {
    foreach (SUSUNAN_REKOD as [$label, $ungkapan]) {
        if ($ungkapan === '(x.sewaan + x.modal_operasi)' || $ungkapan === MUSIM_TAMAT_SUSUN_SQL) continue;
        if (!preg_match('/^[a-z_]+\.[a-z_]+$/D', $ungkapan)) return false;
    }
    return true;
})());
semak('SAIZ_HALAMAN: 25/50/100 sahaja', SAIZ_HALAMAN === [25, 50, 100]);
semak('url_senarai: kekal pada penyaluran.php', str_starts_with(url_senarai('q=https%3A%2F%2Fluar.test%2Fx'), 'penyaluran.php?q='));

echo "\nSusunan kronologi Musim Tamat\n";
$tamat = ['1/2026', '1/2027', '2/2025', '2/2026'];
$menaik = $tamat;
usort($menaik, fn($a, $b) => musim_indeks($a) <=> musim_indeks($b));
semak('Musim Tamat: menaik mengikut kronologi', $menaik === ['2/2025', '1/2026', '2/2026', '1/2027'], implode(',', $menaik));
$menurun = $tamat;
usort($menurun, fn($a, $b) => musim_indeks($b) <=> musim_indeks($a));
semak('Musim Tamat: menurun mengikut kronologi', $menurun === ['1/2027', '2/2026', '1/2026', '2/2025'], implode(',', $menurun));
semak('Musim Tamat: nilai tidak sah / lama → indeks null', musim_indeks('0/2026') === null && musim_indeks('2/2025 ') === null && musim_indeks('1/1999') === null && musim_indeks('') === null);
semak('Musim Tamat: SQL guna indeks angka yang sama', SUSUNAN_REKOD['tamat'][1] === MUSIM_TAMAT_SUSUN_SQL
    && str_contains(MUSIM_TAMAT_SUSUN_SQL, "REGEXP '^[12]/[0-9]{4}$'")
    && str_contains(MUSIM_TAMAT_SUSUN_SQL, 'CAST(SUBSTRING(x.musim_tamat, 3, 4) AS UNSIGNED) * 2'));
semak('sql_order_rekod: arah ASC/DESC dikekalkan', str_contains(sql_order_rekod('tamat', 'asc'), MUSIM_TAMAT_SUSUN_SQL . ' ASC, p.wilayah_id')
    && str_contains(sql_order_rekod('tamat', 'desc'), MUSIM_TAMAT_SUSUN_SQL . ' DESC, p.wilayah_id'));
semak('sql_order_rekod: kunci luar senarai putih ditolak', sql_order_rekod('tamat; DROP', 'desc') === 'ORDER BY p.wilayah_id, p.susunan, x.jenis, x.musim_susun, x.id');

echo "\nKlasifikasi kewangan EP\n";
$bukanEp = ['SP' => ['sewa' => 152082.0, 'mo' => 32589.0], 'LKPPK' => ['sewa' => 0.0, 'mo' => 1200.0]];
$p = pecahan_sewa_modal($bukanEp);
semak('rekod bukan EP: Sewaan/Modal asal tidak berubah', abs($p['sewa'] - 152082.0) < 0.001 && abs($p['mo'] - 33789.0) < 0.001, json_encode($p));
semak('rekod bukan EP: jumlah RM kekal', abs(($p['sewa'] + $p['mo']) - 185871.0) < 0.001);

$ep = ['EP' => ['sewa' => 556111.20, 'mo' => 0.0]];
$p = pecahan_sewa_modal($ep);
semak('rekod EP: sewaan dipindah ke Modal Operasi', abs($p['sewa']) < 0.001 && abs($p['mo'] - 556111.20) < 0.001, json_encode($p));
semak('rekod EP: jumlah RM tidak berubah', abs(($p['sewa'] + $p['mo']) - 556111.20) < 0.001);

$campur = ['SP' => ['sewa' => 100.0, 'mo' => 20.0], 'EP' => ['sewa' => 80.0, 'mo' => 10.0], 'LKPPK' => ['sewa' => 0.0, 'mo' => 40.0]];
$p = pecahan_sewa_modal($campur);
semak('campuran EP + bukan EP: tiada double counting', abs($p['sewa'] - 100.0) < 0.001 && abs($p['mo'] - 150.0) < 0.001, json_encode($p));
semak('campuran EP + bukan EP: jumlah RM kekal', abs(($p['sewa'] + $p['mo']) - 250.0) < 0.001);

$bootstrap = file_get_contents(__DIR__ . '/../includes/bootstrap.php');
semak('carian global: LIKE utilizes ESCAPE CHAR(92)', str_contains($bootstrap, 'LIKE ? ESCAPE CHAR(92)'));
semak('penapis tahun: had tahun daripada peraturan musim digunakan', str_contains($bootstrap, 'MUSIM_TAHUN_MIN') && str_contains($bootstrap, 'MUSIM_TAHUN_MAKS'));

tamat();
