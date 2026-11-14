<?php

namespace Modules\Core\Support;

use App\Models\Company;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** PDF documents with the company header and a footer naming the document and who printed it; opened in the browser, not downloaded. */
class PdfDocument
{
    private const DEFAULT_COLOR = '#2563eb';

    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     * @param  list<mixed>|null  $totals
     */
    public function table(User $user, string $title, ?string $subtitle, array $headings, array $rows, ?array $totals = null): Response
    {
        $isNumber = fn ($v) => is_int($v) || is_float($v);
        $numeric = array_map(fn ($i) => collect($rows)->contains(fn ($r) => $isNumber($r[$i]))
            && collect($rows)->every(fn ($r) => $r[$i] === null || $r[$i] === '' || $isNumber($r[$i])), array_keys($headings));

        return $this->view($user, 'pdf.table', $title, [
            'subtitle' => $subtitle, 'headings' => $headings, 'rows' => $rows, 'totals' => $totals, 'numeric' => $numeric,
        ], count($headings) > 7 ? 'landscape' : 'portrait');
    }

    /** @param  list<array{key: string, label: string}>  $columns  @param  list<array<string, mixed>>  $rows */
    public function keyed(User $user, string $title, ?string $subtitle, array $columns, array $rows): Response
    {
        return $this->table($user, $title, $subtitle, array_column($columns, 'label'),
            array_map(fn ($r) => array_map(fn ($c) => $r[$c['key']] ?? null, $columns), $rows));
    }

    public function view(User $user, string $view, string $title, array $data = [], string $orientation = 'portrait'): Response
    {
        $company = $user->company;
        $primary = $company?->primary_color ?: self::DEFAULT_COLOR;
        $secondary = $company?->secondary_color ?: $primary;
        $pdf = Pdf::loadView($view, $data + [
            'title' => $title,
            'company' => $company,
            'primary' => $primary,
            'secondary' => $secondary,
            'onSecondary' => $this->readableOn($secondary),
            'tint' => $this->tint($secondary),
            'logo' => $this->logo($company),
            'printedBy' => $user->display_name,
            'printedAt' => now()->format('d M Y, H:i'),
        ])->setPaper('a4', $orientation);
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $label = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $width = $dompdf->getFontMetrics()->getTextWidth('Page 00 of 00', $font, 6.5);
        $canvas->page_text($canvas->get_width() - 24 - $width, $canvas->get_height() - 30, $label, $font, 6.5, [0.34, 0.38, 0.42]);
        $name = Str::slug($title).'-'.now()->format('Y-m-d').'.pdf';

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$name}\""]);
    }

    private function readableOn(string $hex): string
    {
        [$r, $g, $b] = array_map(fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, sscanf($hex, '#%02x%02x%02x'));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b > 0.4 ? '#111827' : '#ffffff';
    }

    private function tint(string $hex): string
    {
        return vsprintf('#%02x%02x%02x', array_map(fn ($c) => (int) round($c + (255 - $c) * 0.92), sscanf($hex, '#%02x%02x%02x')));
    }

    private function logo(?Company $company): ?string
    {
        $disk = Storage::disk('public');
        if (! $company?->logo || ! $disk->exists($company->logo)) {
            return null;
        }

        return 'data:'.$disk->mimeType($company->logo).';base64,'.base64_encode($disk->get($company->logo));
    }
}
