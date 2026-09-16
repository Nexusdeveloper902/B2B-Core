<?php

namespace App\Services\Pdf;

use App\Support\Branding\Brand;
use App\Support\Branding\BrandResolver;
use Dompdf\Dompdf;
use Illuminate\Support\Carbon;

/**
 * The shared shell for the admin report PDFs (PAE, attendance,
 * recycling): one branded document:grid, one data layer per report.
 *
 * Brand rule (ADR-065) — a report generated under a school wears that
 * school: the header band and the table heads render in the brand
 * primary, the school's own crest prints beside the product name, and
 * the school name signs the footer. Everything else (sage/paper
 * grounds, the gold accent, the error family) is the shared design
 * system and stays identical on every report, so a brand can change
 * the ACTION color without being able to break contrast, hierarchy or
 * the meaning of a status color in print.
 *
 * An account with no school (or an unknown profile) resolves to Pulse
 * and renders byte-identically to the pre-brand shell: ink band, Pulse
 * mark, "Pulse" footer.
 */
abstract class BrandedReportPdf
{
    protected const GOLD = '#F5CB5C';

    protected const SAGE = '#CFDBD5';

    protected const PAPER = '#E8EDDF';

    protected const MUTED = '#6B6F6D';

    protected const TEXT = '#242423';

    /** The brand of whoever is generating this report (never null — Pulse is the floor). */
    protected function brand(): Brand
    {
        return app(BrandResolver::class)->current();
    }

    /** The header-band + table-head color: Pulse ink, or the school color. */
    protected function primary(): string
    {
        return $this->brand()->primaryColor();
    }

    protected function render(string $title, string $period, string $body, string $section)
    {
        $html = '<html><head><meta charset="utf-8">'
            .'<style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#242423;}</style>'
            .'</head><body>'
            .$this->header($title, $period)
            .$body
            .$this->footer($section)
            .'</body></html>';

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $slug = 'report-'.str_replace(' ', '-', strtolower($title));

        // dompdf's stream() echoes directly (bypasses Laravel's response
        // pipeline); output() + a real Response keeps headers honest.
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$slug.'.pdf"',
        ]);
    }

    protected function header(string $title, string $period): string
    {
        $brand = $this->brand();

        // Pulse keeps its name; the institution is named beside it —
        // the same lockup grammar as the web shell's topbar.
        $left = $this->logoImg()
            .'<span style="color:'.self::GOLD.';font-size:20px;font-weight:bold;">Pulse</span>';
        if ($brand->tag() !== null) {
            $left .= '<br><span style="font-size:9px;color:'.self::PAPER.';">'.e($brand->tag()).'</span>';
        }

        return '<table style="width:100%;background-color:'.$this->primary().';border-collapse:collapse;" cellpadding="10"><tr>'
            .'<td style="width:30%;">'.$left.'</td>'
            .'<td style="color:'.self::PAPER.';font-size:14px;text-align:right;">'
            .e($title).'<br><span style="font-size:10px;color:'.self::SAGE.';">'.e($period).'</span></td>'
            .'</tr></table><div style="height:14px;"></div>';
    }

    /**
     * The school crest (or the Pulse mark) as an embedded data URI —
     * dompdf runs with remote fetching OFF, so a public URL would come
     * out blank; the bytes travel inside the document instead.
     */
    protected function logoImg(): string
    {
        $brand = $this->brand();
        $path = public_path($brand->logoSrc());

        if (! is_file($path)) {
            return '';
        }

        $mime = match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };

        $data = base64_encode((string) file_get_contents($path));

        return '<img src="data:'.$mime.';base64,'.$data.'" '
            .'width="'.$brand->logoWidth().'" height="'.$brand->logoHeight().'" '
            .'style="vertical-align:middle;margin-right:6px;">';
    }

    protected function summaryRow(string $labelA, string $valueA, string $labelB, string $valueB, string $labelC, string $valueC): string
    {
        $cells = '';
        foreach ([$labelA => $valueA, $labelB => $valueB, $labelC => $valueC] as $label => $value) {
            if ($label === '') {
                continue;
            }
            $cells .= '<td style="background-color:'.self::SAGE.';padding:10px;width:33%;border:1px solid '.self::PAPER.';">'
                .'<span style="font-size:9px;color:'.self::MUTED.';">'.e($label).'</span><br>'
                .'<span style="font-size:20px;color:'.self::TEXT.';">'.e($value).'</span></td>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin-bottom:14px;"><tr>'.$cells.'</tr></table>';
    }

    protected function section(string $title, string $content): string
    {
        return '<p style="margin:0 0 6px;font-size:12px;border-bottom:2px solid '.self::GOLD.';padding-bottom:3px;">'
            .e($title).'</p>'.$content.'<div style="height:12px;"></div>';
    }

    /**
     * Horizontal CSS bars — dompdf-reliable "chart": one row per entry,
     * bar width proportional to the max.
     */
    protected function barRow(string $label, string $value, int $percent, string $color): string
    {
        $percent = max(2, min(100, $percent));

        return '<table style="width:100%;border-collapse:collapse;margin-bottom:2px;"><tr>'
            .'<td style="width:22%;font-size:9px;color:'.self::MUTED.';">'.e($label).'</td>'
            .'<td style="width:66%;background-color:'.self::PAPER.';padding:2px;">'
            .'<div style="background-color:'.$color.';height:10px;width:'.$percent.'%;"></div></td>'
            .'<td style="width:12%;font-size:10px;text-align:right;">'.e($value).'</td>'
            .'</tr></table>';
    }

    /**
     * @param  array<int, array<int, string>>  $headerRow
     * @param  array<int, array<int, string>>  $rows
     */
    protected function tableSection(string $title, array $headerRow, array $rows): string
    {
        $rowsHtml = '';
        foreach ($rows as $i => $row) {
            $bg = $i % 2 === 1 ? self::PAPER : '#FFFFFF';
            $cells = '';
            foreach ($row as $cell) {
                $cells .= '<td style="padding:5px 8px;border:1px solid '.self::SAGE.';font-size:10px;">'.e((string) $cell).'</td>';
            }
            $rowsHtml .= '<tr style="background-color:'.$bg.';">'.$cells.'</tr>';
        }

        if ($rows === []) {
            $span = max(1, count($headerRow[0]));
            $rowsHtml = '<tr><td colspan="'.$span.'" style="padding:8px;border:1px solid '.self::SAGE
                .';color:'.self::MUTED.';font-size:10px;">'.e(__('app.report_empty')).'</td></tr>';
        }

        $headCells = '';
        foreach ($headerRow[0] as $cell) {
            $headCells .= '<td style="padding:5px 8px;background-color:'.$this->primary().';color:'.self::PAPER
                .';font-size:9px;border:1px solid '.$this->primary().';">'.e((string) $cell).'</td>';
        }

        return $this->section($title, '<table style="width:100%;border-collapse:collapse;"><tr>'.$headCells.'</tr>'.$rowsHtml.'</table>');
    }

    protected function footer(string $section): string
    {
        return '<div style="height:18px;"></div><table style="width:100%;border-collapse:collapse;"><tr>'
            .'<td style="font-size:8px;color:'.self::MUTED.';">'.e($this->brand()->name()).' · '.e($section).'</td>'
            .'<td style="font-size:8px;color:'.self::MUTED.';text-align:right;">'
            .e(now()->format('Y-m-d H:i')).'</td></tr></table>';
    }

    protected function prettyDate(string $date): string
    {
        try {
            return Carbon::parse($date)->isoFormat('MMM D, YYYY');
        } catch (\Throwable) {
            return $date;
        }
    }
}
