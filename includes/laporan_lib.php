<?php
/**
 * Penjana laporan EPMS. Setiap laporan menghasilkan struktur:
 *   ['title','subtitle','sheet','head'=>[[...],[...]],'fmt'=>[...],'rows'=>[['cells'=>[],'cls'=>'']]]
 * yang dipaparkan sebagai HTML (render_report) atau dieksport ke XLSX (SimpleXlsx).
 */

const LAPORAN = [
    'ringkasan' => 'Ringkasan Penyaluran Ikut PPK & Model',
    'modal'     => 'Modal Pusingan PPSB',
    'keluasan'  => 'Keluasan PPSB',
    'musim'     => 'Penyaluran Ikut Musim',
];

const SKOP = [
    'kumulatif' => 'Kumulatif (Jumlah Asal, termasuk yang tamat)',
    'semasa'    => 'Semasa (Tolak Tamat + Sambung Perjanjian)',
];

/** Agregat ikut PPK x kunci ($key = 'model' atau 'musim'). */
function agregat(string $key, string $skop, array $extra = []): array
{
    $w = ['1=1'];
    $p = [];
    $w[] = skop_sql($skop);
    if (!empty($extra['model']) && isset(MODELS[$extra['model']])) { $w[] = 'x.model = ?'; $p[] = $extra['model']; }
    if (!empty($extra['wilayah'])) { $w[] = 'p.wilayah_id = ?'; $p[] = (int)$extra['wilayah']; }
    $col = $key === 'musim' ? 'x.musim' : 'x.model';
    $sql = "SELECT x.ppk_id, $col AS k, MIN(x.musim_susun) AS susun,
                   SUM(x.bil_peladang) bil, SUM(x.luas_ha) luas, SUM(x.sewaan) sewa, SUM(x.modal_operasi) mo,
                   COUNT(*) n
            FROM penyaluran x JOIN ppk p ON p.id = x.ppk_id
            WHERE " . implode(' AND ', $w) . " GROUP BY x.ppk_id, $col";
    $st = db()->prepare($sql);
    $st->execute($p);
    $data = [];
    $keys = [];
    foreach ($st as $r) {
        $data[$r['ppk_id']][$r['k']] = $r;
        $keys[$r['k']] = $r['susun'];
    }
    asort($keys);
    return [$data, array_keys($keys)];
}

/**
 * Bina baris PPK dikumpul ikut wilayah dengan jumlah kecil & jumlah keseluruhan.
 * $groups: [['t'=>tajuk kumpulan, 'cols'=>[['t'=>..., 'fmt'=>..., 'v'=>fn(array $agg): float]]]]
 */
function bina_laporan(array $groups, array $data, array $meta, ?int $wilayahId = null): array
{
    $head1 = [['t' => 'BIL', 'rs' => 2], ['t' => 'PPK', 'rs' => 2]];
    $head2 = [];
    $fmt = ['text', 'text'];
    $widths = [6, 28];
    $fns = [];
    foreach ($groups as $g) {
        $head1[] = ['t' => $g['t'], 'cs' => count($g['cols'])];
        foreach ($g['cols'] as $c) {
            $head2[] = ['t' => $c['t']];
            $fmt[] = $c['fmt'];
            $widths[] = 14;
            $fns[] = $c['v'];
        }
    }

    $rows = [];
    $grand = array_fill(0, count($fns), 0.0);
    $bil = 0;
    $ppkByW = [];
    foreach (senarai_ppk() as $p) {
        if ($wilayahId && (int)$p['wilayah_id'] !== $wilayahId) continue;
        $ppkByW[$p['wilayah_id']][] = $p;
    }
    $wil = array_column(senarai_wilayah(), 'nama', 'id');
    foreach ($ppkByW as $wid => $list) {
        $sub = array_fill(0, count($fns), 0.0);
        foreach ($list as $p) {
            $agg = $data[$p['id']] ?? [];
            $vals = array_map(fn($f) => (float)$f($agg), $fns);
            foreach ($vals as $i => $v) { $sub[$i] += $v; $grand[$i] += $v; }
            $rows[] = ['cells' => array_merge([++$bil, $p['kod'] . ' ' . $p['nama']], $vals), 'cls' => ''];
        }
        $rows[] = ['cells' => array_merge(['', 'JUMLAH ' . $wil[$wid]], $sub), 'cls' => 'sub'];
    }
    $rows[] = ['cells' => array_merge(['', 'JUMLAH KESELURUHAN'], $grand), 'cls' => 'total'];

    return $meta + ['head' => [$head1, $head2], 'fmt' => $fmt, 'widths' => $widths, 'rows' => $rows];
}

/** Lajur: nilai medan $f untuk kunci $k (cth model 'SP'). */
function col_k(string $k, string $f): callable { return fn($a) => (float)($a[$k][$f] ?? 0); }
/** Lajur: jumlah medan $f merentas semua kunci. */
function col_sum(string $f): callable { return fn($a) => array_sum(array_map(fn($r) => (float)$r[$f], $a)); }
/**
 * Lajur penyaluran Model EP (Entiti Pengurusan).
 * Nilai EP disimpan dalam medan `sewaan` (Excel: Luas × Kadar Sewaan RM/Ha × Tempoh), bukan `modal_operasi`.
 * sewaan + modal_operasi diambil supaya tiada nilai EP tercicir; setiap medan dikira sekali sahaja.
 */
function col_ep(): callable { return fn($a) => (float)($a['EP']['sewa'] ?? 0) + (float)($a['EP']['mo'] ?? 0); }

/**
 * Pecahan Sewaan/Pajakan dan Modal Operasi ikut klasifikasi EP Fasa 2.1 — sama seperti lajur
 * JUMLAH laporan Ringkasan (penyaluran EP dikira dalam Modal Operasi). Digunakan oleh KPI
 * Dashboard dan Senarai Rekod supaya angka selaras dengan laporan. Jumlah penyaluran tidak berubah.
 * $a: agregat ikut model, cth ['SP' => ['sewa' => .., 'mo' => ..], 'EP' => [...]]
 */
function pecahan_sewa_modal(array $a): array
{
    return [
        'sewa' => col_sum('sewa')($a) - col_k('EP', 'sewa')($a),
        'mo'   => col_sum('mo')($a) + col_k('EP', 'sewa')($a),
    ];
}

/** Agregat Sewaan/Modal ikut model untuk klausa FROM ... WHERE yang diberi (format sama seperti agregat()). */
function agregat_ikut_model(string $fromWhere, array $params = []): array
{
    $st = db()->prepare("SELECT x.model, SUM(x.sewaan) sewa, SUM(x.modal_operasi) mo $fromWhere GROUP BY x.model");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_UNIQUE);
}

function laporan_ringkasan(string $skop, array $opt): array
{
    [$data] = agregat('model', $skop, $opt);
    $groups = [];
    foreach (MODELS as $code => $nama) {
        $cols = [['t' => 'Bil Peladang', 'fmt' => 'int', 'v' => col_k($code, 'bil')],
                 ['t' => 'Luas (Ha)', 'fmt' => 'ha', 'v' => col_k($code, 'luas')]];
        if ($code === 'SP') $cols[] = ['t' => 'Sewaan/Pajakan (RM)', 'fmt' => 'rm', 'v' => col_k($code, 'sewa')];
        $cols[] = $code === 'EP'
            ? ['t' => 'Penyaluran (RM)', 'fmt' => 'rm', 'v' => col_ep()]
            : ['t' => 'Modal Operasi (RM)', 'fmt' => 'rm', 'v' => col_k($code, 'mo')];
        $groups[] = ['t' => "MODEL " . mb_strtoupper($nama) . " ($code)", 'cols' => $cols];
    }
    $groups[] = ['t' => 'JUMLAH', 'cols' => [
        ['t' => 'Bil Rekod', 'fmt' => 'int', 'v' => col_sum('n')],
        ['t' => 'Bil Peladang', 'fmt' => 'int', 'v' => col_sum('bil')],
        ['t' => 'Keluasan (Ha)', 'fmt' => 'ha', 'v' => col_sum('luas')],
        // Klasifikasi ikut Excel SUMMARY: penyaluran EP (disimpan dalam medan sewaan) dikira dalam
        // Jumlah Modal Operasi, bukan Jumlah Sewaan/Pajakan. Nilai EP dipindah, tidak digandakan.
        ['t' => 'Sewaan/Pajakan (RM)', 'fmt' => 'rm', 'v' => fn($a) => col_sum('sewa')($a) - col_k('EP', 'sewa')($a)],
        ['t' => 'Modal Operasi (RM)', 'fmt' => 'rm', 'v' => fn($a) => col_sum('mo')($a) + col_k('EP', 'sewa')($a)],
        ['t' => 'Jumlah Penyaluran (RM)', 'fmt' => 'rm', 'v' => fn($a) => col_sum('sewa')($a) + col_sum('mo')($a)],
    ]];
    return bina_laporan($groups, $data, [
        'title' => 'RINGKASAN PENYALURAN PPSB IKUT PPK & MODEL',
        'subtitle' => mb_strtoupper(SKOP[$skop]),
        'sheet' => 'Ringkasan',
    ], $opt['wilayah'] ?? null);
}

function laporan_modal(string $skop, array $opt): array
{
    [$data] = agregat('model', $skop, $opt);
    $cols = [['t' => 'SP - Sewaan/Pajakan', 'fmt' => 'rm', 'v' => col_k('SP', 'sewa')],
             ['t' => 'SP - Modal Operasi', 'fmt' => 'rm', 'v' => col_k('SP', 'mo')]];
    foreach (MODELS as $code => $nama) {
        if ($code === 'SP') continue;
        $cols[] = ['t' => "$code - $nama", 'fmt' => 'rm', 'v' => $code === 'EP' ? col_ep() : col_k($code, 'mo')];
    }
    $groups = [
        ['t' => 'PERUNTUKAN IKUT MODEL (RM)', 'cols' => $cols],
        ['t' => 'JUMLAH', 'cols' => [['t' => 'Modal Pusingan (RM)', 'fmt' => 'rm', 'v' => fn($a) => col_sum('sewa')($a) + col_sum('mo')($a)]]],
    ];
    return bina_laporan($groups, $data, [
        'title' => 'MODAL PUSINGAN PPSB',
        'subtitle' => mb_strtoupper(SKOP[$skop]),
        'sheet' => 'Modal Pusingan',
    ], $opt['wilayah'] ?? null);
}

function laporan_keluasan(string $skop, array $opt): array
{
    [$data] = agregat('model', $skop, $opt);
    $cols = [];
    foreach (MODELS as $code => $nama) $cols[] = ['t' => "$code - $nama", 'fmt' => 'ha', 'v' => col_k($code, 'luas')];
    $groups = [
        ['t' => 'KELUASAN IKUT MODEL (HA)', 'cols' => $cols],
        ['t' => 'JUMLAH', 'cols' => [
            ['t' => 'Bil Peladang', 'fmt' => 'int', 'v' => col_sum('bil')],
            ['t' => 'Keluasan (Ha)', 'fmt' => 'ha', 'v' => col_sum('luas')],
        ]],
    ];
    return bina_laporan($groups, $data, [
        'title' => 'KELUASAN PPSB',
        'subtitle' => mb_strtoupper(SKOP[$skop]),
        'sheet' => 'Keluasan',
    ], $opt['wilayah'] ?? null);
}

const METRIK = ['rm' => 'Jumlah Penyaluran (RM)', 'bil' => 'Bil Peladang', 'luas' => 'Keluasan (Ha)'];

function laporan_musim(string $skop, array $opt): array
{
    $metrik = isset(METRIK[$opt['metrik'] ?? '']) ? $opt['metrik'] : 'rm';
    [$data, $musims] = agregat('musim', $skop, $opt);
    $fmt = ['rm' => 'rm', 'bil' => 'int', 'luas' => 'ha'][$metrik];
    $val = fn($r) => $metrik === 'rm' ? (float)$r['sewa'] + (float)$r['mo'] : (float)$r[$metrik];
    $cols = [];
    foreach ($musims as $m) $cols[] = ['t' => "Musim $m", 'fmt' => $fmt, 'v' => fn($a) => isset($a[$m]) ? $val($a[$m]) : 0];
    $groups = [
        ['t' => 'MUSIM MULA PELAKSANAAN', 'cols' => $cols],
        ['t' => 'JUMLAH', 'cols' => [['t' => METRIK[$metrik], 'fmt' => $fmt, 'v' => fn($a) => array_sum(array_map($val, $a))]]],
    ];
    $sub = mb_strtoupper(SKOP[$skop]) . ' · ' . mb_strtoupper(METRIK[$metrik])
        . (!empty($opt['model']) ? ' · MODEL ' . $opt['model'] : '');
    return bina_laporan($groups, $data, [
        'title' => 'PENYALURAN PPSB IKUT MUSIM',
        'subtitle' => $sub,
        'sheet' => 'Ikut Musim',
    ], $opt['wilayah'] ?? null);
}

function jana_laporan(string $jenis, string $skop, array $opt): array
{
    $fn = 'laporan_' . (isset(LAPORAN[$jenis]) ? $jenis : 'ringkasan');
    return $fn(isset(SKOP[$skop]) ? $skop : 'kumulatif', $opt);
}

function format_sel($v, string $fmt): string
{
    if ($fmt === 'text') return e($v);
    if ((float)$v == 0) return '<span class="text-muted">-</span>';
    return ['int' => 'nint', 'ha' => 'ha', 'rm' => 'rm'][$fmt]($v);
}

function render_report(array $rep): string
{
    $h = '<div class="table-wrap"><table class="table table-bordered table-sm table-data mb-0"><thead>';
    foreach ($rep['head'] as $row) {
        $h .= '<tr>';
        foreach ($row as $c) {
            $h .= '<th' . (($c['cs'] ?? 1) > 1 ? ' colspan="' . $c['cs'] . '"' : '') . (($c['rs'] ?? 1) > 1 ? ' rowspan="' . $c['rs'] . '"' : '') . '>' . e($c['t']) . '</th>';
        }
        $h .= '</tr>';
    }
    $h .= '</thead><tbody>';
    foreach ($rep['rows'] as $r) {
        $h .= '<tr class="' . e($r['cls']) . '">';
        foreach ($r['cells'] as $i => $v) {
            $f = $rep['fmt'][$i];
            $h .= '<td' . ($f !== 'text' ? ' class="num"' : '') . '>' . format_sel($v, $f) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}
