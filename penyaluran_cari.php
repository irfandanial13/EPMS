<?php
/**
 * Perjanjian Fasa 3: carian perjanjian terdahulu untuk borang Perjanjian Sambungan (JSON, baca sahaja).
 * GET: ppk (ID PPK; kosong = semua PPK), projek, no_blok, model.
 * Rekod TAMAT dipulangkan dengan boleh_pilih = false (tidak boleh dipilih).
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/penyaluran_lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$jawab = function (int $kod, array $data): never {
    http_response_code($kod);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'GET') $jawab(405, ['ok' => false, 'ralat' => 'Kaedah tidak dibenarkan.']);

$teks = fn(string $k) => is_string($_GET[$k] ?? null) ? mb_substr(trim($_GET[$k]), 0, 100) : '';
$ppkRaw = $teks('ppk');
$model = $teks('model');
if ($ppkRaw !== '' && !ctype_digit($ppkRaw)) $jawab(400, ['ok' => false, 'ralat' => 'PPK tidak sah.']);
$ppkId = $ppkRaw !== '' ? (int)$ppkRaw : null;
if ($ppkId && !in_array($ppkId, array_map('intval', array_column(senarai_ppk(), 'id')), true)) $jawab(400, ['ok' => false, 'ralat' => 'PPK tidak sah.']);
if ($model !== '' && !isset(MODELS[$model])) $jawab(400, ['ok' => false, 'ralat' => 'Model tidak sah.']);

$hasil = cari_pendahulu(db(), $ppkId, $teks('projek'), $teks('no_blok'), $model);
$jawab(200, ['ok' => true, 'rekod' => $hasil['rekod'], 'lebih' => $hasil['lebih']]);
