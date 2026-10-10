<?php
/**
 * Render PDF menggunakan domPDF (instalasi manual tanpa composer).
 */
require_once __DIR__ . '/../config/config.php';

function renderPdf(string $html, string $filename = 'dokumen.pdf', string $paper = 'A4', string $orient = 'portrait'): void
{
    require_once LIB_DOMPDF;

    $options = new dompdf\Options();
    $options->set('isRemoteEnabled', true);   // logo & avatar dari URL eksternal
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('chroot', [ROOT_PATH]);
    $options->set('tempDir', DOMPDF_CACHE);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper($paper, $orient);
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $dompdf->output();
    exit;
}

/** Header kop standar untuk dokumen PDF */
function pdfKop(string $judul, string $subjudul = ''): string
{
    $logo = LOGO_BASE . LOGO_FILE;
    $today = tglIndo(date('Y-m-d'));
    return '
    <div style="margin-bottom:16px;">
      <table style="width:100%; border:0; cellpadding:0; cellspacing:0;">
        <tr>
          <td style="width:90px; vertical-align:top;">
            <img src="' . $logo . '" style="width:72px;height:auto;" />
          </td>
          <td style="text-align:center; vertical-align:middle;">
            <div style="font-size:16pt;font-weight:bold;color:#0f3d33;">' . APP_COMPANY . '</div>
            <div style="font-size:9pt;color:#475569;">Jl. Wolter Monginsidi No. 120, Bandar Lampung &bull; Telp. (0721) 267890 &bull; www.lju.co.id</div>
            <div style="border-bottom:2.5px solid #0d9488; margin-top:6px;"></div>
          </td>
        </tr>
      </table>
      <div style="text-align:center;margin-top:12px;">
        <div style="font-size:13pt;font-weight:bold;text-transform:uppercase;">' . htmlspecialchars($judul) . '</div>
        ' . ($subjudul ? '<div style="font-size:10pt;color:#334155;">' . htmlspecialchars($subjudul) . '</div>' : '') . '
      </div>
    </div>';
}

/** Tabel standar PDF */
function pdfTable(array $header, array $rows, array $align = []): string
{
    $th = '';
    foreach ($header as $i => $h) {
        $a = $align[$i] ?? 'left';
        $th .= '<th style="text-align:' . $a . ';padding:6px 8px;background:#0d9488;color:#fff;font-size:9pt;border:1px solid #0b7c72;">' . htmlspecialchars($h) . '</th>';
    }
    $tr = '';
    foreach ($rows as $r) {
        $td = '';
        foreach ($r as $i => $c) {
            $a = $align[$i] ?? 'left';
            $td .= '<td style="text-align:' . $a . ';padding:5px 8px;font-size:9pt;border:1px solid #cbd5e1;">' . nl2br(htmlspecialchars((string)$c)) . '</td>';
        }
        $tr .= '<tr>' . $td . '</tr>';
    }
    return '<table style="width:100%;border-collapse:collapse;"><thead><tr>' . $th . '</tr></thead><tbody>' . $tr . '</tbody></table>';
}

/** Tanda tangan 2 kolom */
function pdfTtd(array $kiri, array $kanan): string
{
    $col = function (array $c) {
        $jab = $c['jabatan'] ?? '';
        $nama = $c['nama'] ?? '';
        $ttd = !empty($c['ttd_img']) ? '<img src="' . $c['ttd_img'] . '" style="height:52px;"/>' : '<div style="height:52px;"></div>';
        return '<td style="width:50%;text-align:center;font-size:9.5pt;padding-top:8px;">'
             . htmlspecialchars($jab) . '<br/>' . $ttd
             . '<div style="border-bottom:1px solid #333;width:180px;margin:2px auto 4px auto;"></div>'
             . '<strong>' . htmlspecialchars($nama) . '</strong></td>';
    };
    return '<table style="width:100%;margin-top:28px;"><tr>' . $col($kiri) . $col($kanan) . '</tr></table>';
}

/** Frame HTML dasar untuk PDF */
function pdfWrap(string $body, string $title): string
{
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head>'
         . '<body style="font-family: DejaVu Sans, sans-serif;color:#0f172a;">' . $body
         . '<div style="position:fixed;bottom:8px;left:0;right:0;font-size:7.5pt;color:#64748b;text-align:center;">'
         . APP_TITLE . ' - ' . APP_COMPANY . ' &bull; Dicetak ' . date('d/m/Y H:i') . ' oleh ' . htmlspecialchars(authName())
         . ' &bull; Halaman <script type="text/php">if (isset($pdf)) { $pdf->page_script(\'$p = $PAGE_NUMBER; $t = $PAGE_COUNT; $TEXT_FONT->select_font("core.times-roman", 8); $x = (float)$PAGE_WIDTH / 2 - 30; $TEXT->draw_text($x, 12, "Hal " . $p . " dari " . $t);\'}); }</script>'
         . '</div></body></html>';
}
