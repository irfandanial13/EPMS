<?php
/** Pembantu penegasan ringkas untuk ujian CLI EPMS. */
$GLOBALS['__lulus'] = 0;
$GLOBALS['__gagal'] = 0;

function semak(string $nama, bool $ok, string $info = ''): void
{
    $ok ? $GLOBALS['__lulus']++ : $GLOBALS['__gagal']++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $nama . ($info !== '' ? "  [$info]" : '') . "\n";
}

/** Cetak keputusan dan keluar dengan kod 1 jika ada kegagalan. */
function tamat(): never
{
    echo "\nKeputusan: {$GLOBALS['__lulus']} PASS, {$GLOBALS['__gagal']} FAIL\n";
    exit($GLOBALS['__gagal'] ? 1 : 0);
}
