<?php
/**
 * Pembuat berkas Excel (.xlsx) berisi tabel data + GAMBAR GRAFIK.
 *
 * Excel versi ringan (HTML) tidak dapat memuat grafik, jadi untuk "Laporan
 * Lengkap + Grafik" dibuat berkas .xlsx sungguhan dengan gambar grafik
 * ditempelkan pada sheet "Grafik".
 *
 * ZIP dibuat dengan ZipArchive bila tersedia (runtime aplikasi memilikinya),
 * dengan cadangan penulis ZIP sendiri (dipakai mis. saat pengujian di CLI).
 */
declare(strict_types=1);

function xml_esc($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
function xlsx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = (int)(($i - $m) / 26);
    }
    return $s;
}

/* ------------------------------------------------------------------ *
 * Penulis ZIP (cadangan tanpa ekstensi zip)
 * ------------------------------------------------------------------ */
function zip_build(array $entries): string
{
    $local = '';
    $central = '';
    foreach ($entries as $name => $e) {
        $data = (string)$e['data'];
        $store = !empty($e['store']);
        $crc = crc32($data);
        if ($store) {
            $method = 0;
            $comp = $data;
        } else {
            $method = 8;
            $comp = gzdeflate($data, 6);
        }
        $offset = strlen($local);
        $local .= "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', $method)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', strlen($comp)) . pack('V', strlen($data))
            . pack('v', strlen($name)) . pack('v', 0) . $name . $comp;
        $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', $method)
            . pack('v', 0) . pack('v', 0) . pack('V', $crc) . pack('V', strlen($comp)) . pack('V', strlen($data))
            . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', 0) . pack('V', $offset) . $name;
    }
    $n = count($entries);
    return $local . $central . "PK\x05\x06" . pack('v', 0) . pack('v', 0)
        . pack('v', $n) . pack('v', $n) . pack('V', strlen($central)) . pack('V', strlen($local)) . pack('v', 0);
}

function zip_write_file(string $path, array $entries): bool
{
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($entries as $name => $e) {
                $z->addFromString($name, (string)$e['data']);
            }
            return $z->close();
        }
    }
    return file_put_contents($path, zip_build($entries)) !== false;
}

/* ------------------------------------------------------------------ *
 * Sheet XML
 * ------------------------------------------------------------------ */
function xlsx_sheet_xml(array $rows, array $widths = [], bool $header = true, int $drawingRef = 0): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    if ($widths) {
        $xml .= '<cols>';
        foreach ($widths as $i => $w) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }
    $xml .= '<sheetData>';
    foreach ($rows as $ri => $row) {
        $r = $ri + 1;
        $xml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $ci => $val) {
            $ref = xlsx_col($ci) . $r;
            $style = ($header && $ri === 0) ? ' s="1"' : '';
            $v = (string)$val;
            if ($v === '') {
                $xml .= '<c r="' . $ref . '"' . $style . '/>';
            } elseif (is_numeric($v) && !preg_match('/^0\d/', $v)) {
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                     . xml_esc($v) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    if ($drawingRef > 0) $xml .= '<drawing r:id="rId' . $drawingRef . '"/>';
    return $xml . '</worksheet>';
}

/**
 * Bangun XLSX.
 *
 * @param array $sheets  [['name'=>'Ringkasan','rows'=>[[..]],'widths'=>[..]], ...]
 * @param array $images  gambar untuk sheet "Grafik":
 *                       ['name'=>'Grafik','items'=>[['title'=>..,'png'=>bytes], ...]]
 * @return string isi berkas XLSX
 */

/* ------------------------------------------------------------------ *
 * GRAFIK NATIVE EXCEL (DrawingML)
 *
 * Grafik dibuat dari SEL di lembar kerja (bukan gambar), sehingga bila angka
 * pada lembar tersebut diubah, grafiknya ikut berubah otomatis saat dibuka di
 * Excel/LibreOffice. Ukurannya memakai oneCellAnchor + ext (ukuran tetap
 * dalam EMU), jadi grafik tidak pernah diregangkan mengikuti lebar sel.
 * ------------------------------------------------------------------ */

/** Nama lembar untuk dipakai di dalam formula (diberi tanda kutip bila perlu). */
function xlsx_sheet_ref(string $name): string
{
    return "'" . str_replace("'", "''", $name) . "'";
}

/**
 * Bangun satu bagian grafik (chartN_K.xml) dari sel lembar kerja.
 *
 * @param array $c [
 *   'title'  => judul,
 *   'sheet'  => nama lembar sumber (diisi otomatis),
 *   'cat'    => rentang kategori (mis. '$A$2:$A$30'),
 *   'series' => [['name'=>'Treatment','name_ref'=>'$C$1','range'=>'$C$2:$C$30','color'=>'C2185B'], ...],
 *   'type'   => 'col' (batang tegak, berjajaran) | 'line' | 'pie'
 * ]
 */
function xlsx_chart_xml(array $c): string
{
    $ref = xlsx_sheet_ref((string)$c['sheet']);
    $cat = $ref . '!' . (string)$c['cat'];
    $type = (string)($c['type'] ?? 'col');
    if (!in_array($type, ['col', 'line', 'pie'], true)) $type = 'col';
    $axis1 = 111111111; $axis2 = 222222222;

    $serXml = '';
    foreach (array_values((array)($c['series'] ?? [])) as $i => $ser) {
        $color = preg_replace('/[^0-9A-Fa-f]/', '', (string)($ser['color'] ?? 'C2185B'));
        if (strlen($color) !== 6) $color = 'C2185B';
        $serXml .= '<c:ser><c:idx val="' . $i . '"/><c:order val="' . $i . '"/>';
        if (!empty($ser['name_ref'])) {
            $serXml .= '<c:tx><c:strRef><c:f>' . xml_esc($ref . '!' . (string)$ser['name_ref']) . '</c:f></c:strRef></c:tx>';
        }
        /* Pie: warna tiap potongan ditentukan Excel (varyColors) — memberi
           satu warna pada serinya akan membuat seluruh pie satu warna. */
        if ($type !== 'pie') {
            $serXml .= '<c:spPr><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
                . '<a:ln><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:ln></c:spPr>';
            if ($type === 'col') $serXml .= '<c:invertIfNegative val="0"/>';
            else $serXml .= '<c:marker><c:symbol val="circle"/><c:size val="6"/></c:marker>';
        }
        $serXml .= '<c:cat><c:strRef><c:f>' . xml_esc($cat) . '</c:f></c:strRef></c:cat>'
            . '<c:val><c:numRef><c:f>' . xml_esc($ref . '!' . (string)$ser['range']) . '</c:f></c:numRef></c:val>'
            . '</c:ser>';
    }

    if ($type === 'col') {
        $plot = '<c:barChart><c:barDir val="col"/><c:grouping val="clustered"/><c:varyColors val="0"/>' . $serXml
            . '<c:gapWidth val="90"/><c:overlap val="-27"/>'
            . '<c:axId val="' . $axis1 . '"/><c:axId val="' . $axis2 . '"/></c:barChart>';
    } elseif ($type === 'line') {
        $plot = '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>' . $serXml
            . '<c:marker val="1"/><c:axId val="' . $axis1 . '"/><c:axId val="' . $axis2 . '"/></c:lineChart>';
    } else {
        $plot = '<c:pieChart><c:varyColors val="1"/>' . $serXml
            . '<c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/>'
            . '<c:showSerName val="0"/><c:showPercent val="1"/><c:showBubbleSize val="0"/></c:dLbls>'
            . '<c:firstSliceAng val="0"/></c:pieChart>';
    }

    $axes = '';
    if ($type !== 'pie') {
        $axes = '<c:catAx><c:axId val="' . $axis1 . '"/><c:scaling><c:orientation val="minMax"/></c:scaling>'
            . '<c:delete val="0"/><c:axPos val="b"/><c:numFmt formatCode="General" sourceLinked="1"/>'
            . '<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            . '<c:crossAx val="' . $axis2 . '"/><c:crosses val="autoZero"/><c:auto val="1"/>'
            . '<c:lblAlgn val="ctr"/><c:lblOffset val="100"/></c:catAx>'
            . '<c:valAx><c:axId val="' . $axis2 . '"/><c:scaling><c:orientation val="minMax"/></c:scaling>'
            . '<c:delete val="0"/><c:axPos val="l"/>'
            . '<c:numFmt formatCode="#,##0" sourceLinked="0"/>'
            . '<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            . '<c:crossAx val="' . $axis1 . '"/><c:crosses val="autoZero"/><c:crossBetween val="between"/></c:valAx>';
    }

    $title = '';
    if (trim((string)($c['title'] ?? '')) !== '') {
        $title = '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="id-ID"/>'
            . '<a:t>' . xml_esc((string)$c['title']) . '</a:t></a:r></a:p></c:rich></c:tx>'
            . '<c:overlay val="0"/></c:title>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"'
        . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<c:chart>' . $title . '<c:autoTitleDeleted val="' . ($title === '' ? '1' : '0') . '"/>'
        . '<c:plotArea><c:layout/>' . $plot . $axes . '</c:plotArea>'
        . '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>'
        . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart>'
        . '</c:chartSpace>';
}

/**
 * Bingkai grafik native pada lembar kerja.
 *
 * Ukuran memakai ext tetap (EMU) dengan oneCellAnchor: grafik TIDAK mengikuti
 * lebar sel sehingga tidak pernah terlihat "tertarik lebar ke samping".
 */
function xlsx_chart_anchor(int $id, int $rid, int $fromRow, int $fromCol = 0, int $wPx = 660, int $hPx = 330): string
{
    $cx = $wPx * 9525; $cy = $hPx * 9525;
    return '<xdr:oneCellAnchor><xdr:from><xdr:col>' . $fromCol . '</xdr:col><xdr:colOff>0</xdr:colOff>'
        . '<xdr:row>' . $fromRow . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
        . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr>'
        . '<xdr:cNvPr id="' . $id . '" name="Grafik' . $id . '"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
        . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
        . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId' . $rid . '"/>'
        . '</a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:oneCellAnchor>';
}

/** Baris (0-based) yang ditempati satu grafik di bawah tabel data. */
const XLSX_CHART_ROWS = 17;

function xlsx_build(array $sheets, array $images = []): string
{
    $entries = [];
    $sheetCount = count($sheets);
    if ($images && !empty($images['items'])) $sheetCount++;

    /* Grafik native: satu bagian gambar (drawing) per lembar yang punya grafik;
       satu lembar boleh memuat lebih dari satu grafik. */
    $chartSheets = [];
    foreach ($sheets as $i => $sh) {
        $list = [];
        if (!empty($sh['chart'])) $list[] = $sh['chart'];
        if (!empty($sh['charts'])) foreach ($sh['charts'] as $c) $list[] = $c;
        if ($list) $chartSheets[$i + 1] = $list;
    }

    // ---- content types ----
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $sheetCount; $i++) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    if ($images && !empty($images['items'])) {
        $ct .= '<Override PartName="/xl/drawings/imageGallery.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }
    foreach ($chartSheets as $si => $list) {
        $ct .= '<Override PartName="/xl/drawings/chartSheet' . $si . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        foreach ($list as $k => $c) {
            $ct .= '<Override PartName="/xl/charts/chart' . $si . '_' . ($k + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
        }
    }
    $ct .= '</Types>';
    $entries['[Content_Types].xml'] = ['data' => $ct];

    $entries['_rels/.rels'] = ['data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>'];

    // ---- workbook & rels ----
    $wbSheets = '';
    $wbRels = '';
    $idx = 0;
    foreach ($sheets as $sh) {
        $idx++;
        $wbSheets .= '<sheet name="' . xml_esc(substr((string)$sh['name'], 0, 31)) . '" sheetId="' . $idx . '" r:id="rId' . $idx . '"/>';
        $wbRels .= '<Relationship Id="rId' . $idx . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $idx . '.xml"/>';
    }
    $chartSheetIdx = 0;
    if ($images && !empty($images['items'])) {
        $idx++;
        $chartSheetIdx = $idx;
        $wbSheets .= '<sheet name="' . xml_esc(substr((string)($images['name'] ?? 'Grafik'), 0, 31)) . '" sheetId="' . $idx . '" r:id="rId' . $idx . '"/>';
        $wbRels .= '<Relationship Id="rId' . $idx . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $idx . '.xml"/>';
    }
    $styleId = $idx + 1;
    $wbRels .= '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $entries['xl/workbook.xml'] = ['data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $wbSheets . '</sheets></workbook>'];
    $entries['xl/_rels/workbook.xml.rels'] = ['data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $wbRels . '</Relationships>'];

    $entries['xl/styles.xml'] = ['data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0369A1"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '</styleSheet>'];

    // ---- sheets (termasuk grafik native bila ada) ----
    $n = 0;
    foreach ($sheets as $sh) {
        $n++;
        $hasChart = isset($chartSheets[$n]);
        $entries['xl/worksheets/sheet' . $n . '.xml'] = ['data' => xlsx_sheet_xml(
            $sh['rows'] ?? [], $sh['widths'] ?? [], true, $hasChart ? 1 : 0)];
        if (!$hasChart) continue;
        /* Grafik native dari SEL: ikut berubah bila angka pada lembar diubah.
           Semua grafik satu lembar ditempatkan berurutan di bawah tabel data. */
        $list = $chartSheets[$n];
        $row = count((array)($sh['rows'] ?? [])) + 1;      // 0-based, satu baris kosong setelah tabel
        $anchors = '';
        $rels = '';
        foreach ($list as $k => $ch) {
            $ch['sheet'] = (string)$sh['name'];
            $part = 'chart' . $n . '_' . ($k + 1);
            $entries['xl/charts/' . $part . '.xml'] = ['data' => xlsx_chart_xml($ch)];
            $anchors .= xlsx_chart_anchor($k + 1, $k + 1, $row);
            $rels .= '<Relationship Id="rId' . ($k + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart"'
                . ' Target="../charts/' . $part . '.xml"/>';
            $row += XLSX_CHART_ROWS;
        }
        $entries['xl/drawings/chartSheet' . $n . '.xml'] = ['data' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . $anchors . '</xdr:wsDr>'];
        $entries['xl/drawings/_rels/chartSheet' . $n . '.xml.rels'] = ['data' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>'];
        $entries['xl/worksheets/_rels/sheet' . $n . '.xml.rels'] = ['data' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing"'
            . ' Target="../drawings/chartSheet' . $n . '.xml"/></Relationships>'];
    }

    // ---- sheet galeri grafik (gambar siap cetak) ----
    if ($chartSheetIdx > 0) {
        $items = $images['items'];
        $rows = [];
        $anchors = '';
        $rels = '';
        /* Baris 1 = keterangan; judul tiap grafik diletakkan TEPAT DI ATAS
           gambarnya. Sebelumnya semua judul menumpuk di baris paling atas
           sementara gambarnya tersebar jauh di bawah (tampak berantakan). */
        $rows[0] = ['Grafik di bawah ini adalah GAMBAR (siap dicetak). '
            . 'Grafik yang ikut berubah saat angkanya diubah ada di lembar data masing-masing.'];
        $row = 2;                                  // 0-based
        $imgIdx = 0;
        foreach ($items as $it) {
            $rows[$row] = [(string)($it['title'] ?? 'Grafik')];
            $imgIdx++;
            $mediaName = 'image' . $imgIdx . '.png';
            $entries['xl/media/' . $mediaName] = ['data' => (string)$it['png'], 'store' => true];
            $rid = 'rId' . $imgIdx;
            $rels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $mediaName . '"/>';
            /* Ukuran gambar DIHITUNG dari dimensi PNG aslinya supaya rasionya
               terjaga (tidak diregangkan). Bawaan lebar 660 px (grafik laporan),
               tetapi pemanggil boleh memberi 'width'/'height' sendiri — dipakai
               untuk FOTO yang harus kecil agar banyak foto tetap muat dalam satu
               lembar Excel. 1 px = 9525 EMU. */
            $dim = @getimagesizefromstring((string)$it['png']);
            $iw = max(1, (int)($dim[0] ?? 900)); $ih = max(1, (int)($dim[1] ?? 380));
            $maxW = (int)($it['width'] ?? 660);
            if ($maxW < 40) $maxW = 660;
            $maxH = (int)($it['height'] ?? 0);
            $scale = $maxW / $iw;
            if ($maxH > 0) $scale = min($scale, $maxH / $ih);
            $dispW = (int)max(30, round($iw * $scale));
            $dispH = (int)max(24, round($ih * $scale));
            $cx = $dispW * 9525; $cy = $dispH * 9525;
            $anchors .= '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff>'
                . '<xdr:row>' . ($row + 1) . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
                . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="' . ($imgIdx + 1) . '" name="Grafik' . $imgIdx . '"/>'
                . '<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $rid . '"/>'
                . '<a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
                . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
                . '<xdr:clientData/></xdr:oneCellAnchor>';
            /* Baris Excel ±20 px → sisakan satu baris untuk judul berikutnya. */
            $row += 1 + (int)ceil($dispH / 20) + 1;
        }
        for ($i = 1; $i < $row; $i++) if (!isset($rows[$i])) $rows[$i] = [''];
        ksort($rows);
        $entries['xl/worksheets/sheet' . $chartSheetIdx . '.xml'] = ['data' => xlsx_sheet_xml(array_values($rows), [30], true, 1)];
        $entries['xl/worksheets/_rels/sheet' . $chartSheetIdx . '.xml.rels'] = ['data' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/imageGallery.xml"/>'
            . '</Relationships>'];
        $entries['xl/drawings/imageGallery.xml'] = ['data' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">' . $anchors . '</xdr:wsDr>'];
        $entries['xl/drawings/_rels/imageGallery.xml.rels'] = ['data' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>'];
    }

    return zip_build($entries);
}
