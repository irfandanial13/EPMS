<?php
/**
 * Penulis XLSX ringkas (tanpa library luar) — memerlukan sambungan PHP zip.
 * Menerima struktur jadual laporan:
 *   head : senarai baris tajuk; setiap sel ['t'=>teks, 'cs'=>colspan, 'rs'=>rowspan]
 *   fmt  : format setiap lajur: text | int | ha | rm
 *   rows : ['cells'=>[...], 'cls'=>''|'sub'|'total']
 */
class SimpleXlsx
{
    // indeks gaya dalam styles.xml
    const S_TEXT = 0, S_HEAD = 1, S_INT = 2, S_HA = 3, S_RM = 4, S_TITLE = 5,
          S_BTEXT = 6, S_BINT = 7, S_BHA = 8, S_BRM = 9, S_CELL = 10;

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function x(string $v): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function download(array $rep, string $filename): void
    {
        $rowsXml = [];
        $merges = [];
        $r = 1;
        $ncol = count($rep['fmt']);

        $cellStr = fn($ref, $v, $s) => '<c r="' . $ref . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">' . self::x((string)$v) . '</t></is></c>';
        $cellNum = fn($ref, $v, $s) => '<c r="' . $ref . '" s="' . $s . '"><v>' . (0 + $v) . '</v></c>';

        foreach (array_filter([$rep['title'] ?? '', $rep['subtitle'] ?? '']) as $t) {
            $rowsXml[] = '<row r="' . $r . '">' . $cellStr('A' . $r, $t, self::S_TITLE) . '</row>';
            $r++;
        }
        $r++;

        // Tajuk berbilang baris: kira kedudukan sel + kawasan merge
        $r0 = $r;
        $texts = [];   // [baris][lajur] => teks ('' untuk sel dalam kawasan merge)
        foreach ($rep['head'] as $hrow) {
            $c = 0;
            foreach ($hrow as $h) {
                while (isset($texts[$r][$c])) $c++;
                $cs = $h['cs'] ?? 1; $rs = $h['rs'] ?? 1;
                for ($dr = 0; $dr < $rs; $dr++) for ($dc = 0; $dc < $cs; $dc++) $texts[$r + $dr][$c + $dc] = '';
                $texts[$r][$c] = $h['t'];
                if ($cs > 1 || $rs > 1) $merges[] = self::col($c) . $r . ':' . self::col($c + $cs - 1) . ($r + $rs - 1);
                $c += $cs;
            }
            $r++;
        }
        for ($rr = $r0; $rr < $r; $rr++) {
            $line = '<row r="' . $rr . '">';
            for ($c = 0; $c < $ncol; $c++) $line .= $cellStr(self::col($c) . $rr, $texts[$rr][$c] ?? '', self::S_HEAD);
            $rowsXml[] = $line . '</row>';
        }

        foreach ($rep['rows'] as $row) {
            $bold = in_array($row['cls'] ?? '', ['sub', 'total'], true);
            $line = '<row r="' . $r . '">';
            foreach ($row['cells'] as $c => $v) {
                $ref = self::col($c) . $r;
                $f = $rep['fmt'][$c] ?? 'text';
                if ($f === 'text' || $v === '' || $v === null || !is_numeric($v)) {
                    $line .= $cellStr($ref, $v ?? '', $bold ? self::S_BTEXT : self::S_CELL);
                } else {
                    $s = ['int' => [self::S_INT, self::S_BINT], 'ha' => [self::S_HA, self::S_BHA], 'rm' => [self::S_RM, self::S_BRM]][$f] ?? [self::S_RM, self::S_BRM];
                    $line .= $cellNum($ref, $v, $s[$bold ? 1 : 0]);
                }
            }
            $rowsXml[] = $line . '</row>';
            $r++;
        }

        $cols = '<cols>';
        foreach ($rep['fmt'] as $i => $f) {
            $w = $rep['widths'][$i] ?? ($f === 'text' ? 18 : 14);
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $cols . '<sheetData>' . implode('', $rowsXml) . '</sheetData>'
            . ($merges ? '<mergeCells count="' . count($merges) . '">' . implode('', array_map(fn($m) => '<mergeCell ref="' . $m . '"/>', $merges)) . '</mergeCells>' : '')
            . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="#,##0.000"/><numFmt numFmtId="166" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="12"/><name val="Arial"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD6E5DA"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF3EF"/></patternFill></fill></fills>'
            . '<borders count="2"><border/><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="11">'
            . '<xf/>'
            . '<xf fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" borderId="1" applyNumberFormat="1" applyBorder="1"/>'
            . '<xf numFmtId="165" borderId="1" applyNumberFormat="1" applyBorder="1"/>'
            . '<xf numFmtId="166" borderId="1" applyNumberFormat="1" applyBorder="1"/>'
            . '<xf fontId="2" applyFont="1"/>'
            . '<xf fontId="1" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="3" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="165" fontId="1" fillId="3" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="166" fontId="1" fillId="3" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf borderId="1" applyBorder="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::x(mb_substr($rep['sheet'] ?? 'Laporan', 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }
}
