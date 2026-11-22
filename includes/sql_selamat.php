<?php
/**
 * Keselamatan import SQL (fungsi tulen, tiada sambungan DB). Digunakan oleh install.php dan alat ujian (tests/lib/pengawal_db.php).
 * Fail SQL tidak boleh mencipta, memilih, mengubah atau memadam pangkalan data, atau merujuk jadual dalam pangkalan data lain.
 */

/** Nama pangkalan data yang dibenarkan untuk dipetik dengan backtick (tiada aksara khas). */
function nama_db_sah(string $db): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $db);
}

/**
 * Kod SQL sahaja: literal rentetan ('…', "…") dan komen biasa (-- , #, /* … *\/) dibuang supaya kata kunci
 * di dalamnya tidak dikira. Komen boleh-laksana MySQL (/*!NNNNN … *\/) DIKEKALKAN kerana pelayan melaksanakannya.
 */
function sql_kod_sahaja(string $sql): string
{
    $out = '';
    $n = strlen($sql);
    $bolehLaksana = 0;
    for ($i = 0; $i < $n;) {
        $c = $sql[$i];
        if ($c === "'" || $c === '"') {                                    // literal rentetan → diganti ''
            for ($i++; $i < $n; $i++) {
                if ($sql[$i] === '\\') { $i++; continue; }
                if ($sql[$i] === $c) { if (($sql[$i + 1] ?? '') === $c) { $i++; continue; } $i++; break; }
            }
            $out .= " '' ";
            continue;
        }
        if ($c === '`') {                                                  // pengecam dipetik → dikekalkan
            $j = strpos($sql, '`', $i + 1);
            $j = $j === false ? $n - 1 : $j;
            $out .= substr($sql, $i, $j - $i + 1);
            $i = $j + 1;
            continue;
        }
        if ($c === '#' || ($c === '-' && ($sql[$i + 1] ?? '') === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2])))) {
            $j = strpos($sql, "\n", $i);                                   // komen baris
            $i = $j === false ? $n : $j;
            $out .= ' ';
            continue;
        }
        if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
            if (($sql[$i + 2] ?? '') === '!') {                            // komen boleh-laksana: kandungan ialah kod
                for ($i += 3; $i < $n && ctype_digit($sql[$i]); $i++);
                $bolehLaksana++;
                $out .= ' ';
                continue;
            }
            $j = strpos($sql, '*/', $i + 2);                               // komen blok biasa
            $i = $j === false ? $n : $j + 2;
            $out .= ' ';
            continue;
        }
        if ($bolehLaksana && $c === '*' && ($sql[$i + 1] ?? '') === '/') { $bolehLaksana--; $i += 2; $out .= ' '; continue; }
        $out .= $c;
        $i++;
    }
    return $out;
}

/**
 * Semak fail SQL untuk import. @return ?string sebab ditolak, atau null jika selamat.
 * Ditolak: USE / CONNECT / \u (menukar pangkalan data), CREATE|DROP|ALTER DATABASE|SCHEMA,
 * dan rujukan jadual dengan nama pangkalan data (db.jadual) yang memintas pangkalan data terpilih.
 */
function sql_arahan_dilarang(string $sql): ?string
{
    $kod = sql_kod_sahaja($sql);
    foreach (explode(';', $kod) as $p) {
        $p = ltrim($p);
        if (preg_match('/^(USE|CONNECT)\b/i', $p) || str_starts_with($p, '\\u')) {
            return 'mengandungi arahan menukar pangkalan data (' . strtoupper(strtok($p, " \t\r\n`")) . ').';
        }
        if (preg_match('/^(CREATE(\s+OR\s+REPLACE)?|DROP|ALTER)\s+(DATABASE|SCHEMA)\b/i', $p, $m)) {
            return 'mengandungi arahan ' . strtoupper(preg_replace('/\s+/', ' ', $m[0])) . '.';
        }
    }
    if (preg_match('/\b(INTO|TABLE|UPDATE|FROM|JOIN|REFERENCES|EXISTS)\s+(`[^`]*`|[A-Za-z_]\w*)\s*\.\s*(`|[A-Za-z_])/i', $kod)) {
        return 'merujuk jadual dalam pangkalan data lain (nama_db.jadual).';
    }
    return null;
}
