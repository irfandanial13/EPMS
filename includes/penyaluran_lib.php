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
