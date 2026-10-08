<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\Response;

/**
 * Builds a small, dependency-free PDF report from the authoritative SVP
 * reservation response. The report is an informational companion to the
 * official SVP ticket/certificate and exposes the practical-exam metadata that
 * is not consistently printed on the upstream PDF.
 */
final class SvpPracticalPdfService
{
    public function download(array $reservation, string $reservationId): Response
    {
        $category = $this->category($reservation);
        $result = $this->result($reservation);
        $lines = $this->reportLines($reservation, $category, $result, $reservationId);
        $pdf = $this->buildPdf($lines);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($reservation, $category, $reservationId).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $category
     */
    public function filename(array $reservation, array $category, string $reservationId): string
    {
        $fullName = $this->first($reservation, [
            'full_name', 'fullName', 'candidate_name', 'name',
            'candidate.full_name', 'user.full_name', 'candidate.name', 'user.name',
        ]);
        $occupation = $this->first($reservation, [
            'occupation.english_name', 'occupation.name_en', 'occupation.name',
            'occupation_name', 'exam_name', 'exam.english_name', 'exam.name',
        ]) ?? $this->first($category, ['english_name', 'name']);

        $parts = array_values(array_filter([
            $this->slug($fullName),
            $this->slug($occupation),
        ]));
        $base = implode('_', $parts);

        return ($base !== '' ? $base : 'SVP_Reservation_'.$reservationId).'_Practical_Details.pdf';
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $category
     * @param array{label: string, passed: bool} $result
     * @return list<array{text: string, size: int, bold: bool}>
     */
    private function reportLines(array $reservation, array $category, array $result, string $reservationId): array
    {
        $lines = [];
        $add = static function (array &$target, string $text, int $size = 10, bool $bold = false): void {
            $target[] = ['text' => $text, 'size' => $size, 'bold' => $bold];
        };
        $blank = static function (array &$target): void {
            $target[] = ['text' => '', 'size' => 10, 'bold' => false];
        };
        $field = static function (array &$target, string $label, ?string $value) use ($add): void {
            $add($target, $label.': '.(($value !== null && trim($value) !== '') ? trim($value) : 'Not provided'));
        };

        $add($lines, 'SVP Practical Examination Details', 18, true);
        $add($lines, 'System-generated reservation report', 9, false);
        $blank($lines);

        $add($lines, 'Reservation', 12, true);
        $field($lines, 'Reservation ID', $reservationId);
        $field($lines, 'Candidate', $this->first($reservation, [
            'full_name', 'fullName', 'candidate_name', 'name',
            'candidate.full_name', 'user.full_name', 'candidate.name', 'user.name',
        ]));
        $field($lines, 'Result', $result['label']);
        $field($lines, 'Reservation status', $this->first($reservation, ['status', 'reservation_status', 'exam_status']));
        $field($lines, 'Exam date', $this->first($reservation, [
            'exam_date', 'test_date', 'date', 'exam_session.exam_date',
            'exam_session.start_date_in_browser_time_zone', 'examSession.exam_date',
        ]));
        $field($lines, 'Test center', $this->first($reservation, [
            'test_center_name', 'center_name', 'test_center.name',
            'test_center.english_name', 'test_center.data.attributes.name',
            'exam_session.test_center.name', 'exam_session.test_center.data.attributes.name',
        ]));
        $field($lines, 'Methodology', $this->first($reservation, ['methodology', 'exam_methodology']));
        $blank($lines);

        $add($lines, 'Occupation and category', 12, true);
        $field($lines, 'Category', $this->first($category, ['english_name', 'name']));
        $field($lines, 'Exam type', $this->first($category, ['exam_type']));
        $field($lines, 'Non-targeted exam type', $this->first($category, ['exam_type_non_targeted']));
        $field($lines, 'Practical form', $this->first($category, ['practical_form_name']));
        $field($lines, 'Minimum score', $this->scalar($this->firstValue($category, ['min_score'])));
        $field($lines, 'Practical weight', $this->percentage($this->firstValue($category, ['practical_weight'])));
        $field($lines, 'CBT weight', $this->percentage($this->firstValue($category, ['cbt_weight'])));
        $field($lines, 'Non-targeted practical weight', $this->percentage($this->firstValue($category, ['practical_exam_weight_non_targeted'])));
        $field($lines, 'Non-targeted CBT weight', $this->percentage($this->firstValue($category, ['cbt_weight_non_targeted'])));

        $occupations = $this->firstValue($category, ['occupations']);
        if (is_array($occupations)) {
            $occupationText = implode(', ', array_values(array_filter(array_map(
                fn ($item): string => $this->scalar($item) ?? '',
                $occupations,
            ))));
        } else {
            $occupationText = $this->scalar($occupations);
        }
        $field($lines, 'Category occupations', $occupationText);

        $codes = $this->firstValue($category, ['prometric_codes']);
        if (is_array($codes)) {
            $codeValues = array_values(array_filter(array_map(
                fn ($item): string => is_array($item)
                    ? ($this->first($item, ['code', 'prometric_code', 'name', 'id']) ?? '')
                    : ($this->scalar($item) ?? ''),
                $codes,
            )));
            $codes = implode(', ', $codeValues);
        }
        $field($lines, 'Prometric codes', $this->scalar($codes));
        $blank($lines);

        $add($lines, 'Notes', 12, true);
        $add($lines, $result['passed']
            ? 'The reservation result is passed and the official certificate can be downloaded separately.'
            : 'This report contains the practical-exam metadata returned by the SVP reservation service.');
        $add($lines, 'Generated at: '.now()->toDateTimeString(), 9, false);

        return $lines;
    }

    /** @return array<string, mixed> */
    private function category(array $reservation): array
    {
        $category = data_get($reservation, 'category')
            ?? data_get($reservation, 'exam.category')
            ?? data_get($reservation, 'data.category')
            ?? [];
        $category = is_array($category) ? $category : [];

        $attributes = data_get($category, 'data.attributes')
            ?? data_get($category, 'attributes');
        if (is_array($attributes)) {
            $category = array_replace($category, $attributes);
        }

        return $category;
    }

    /** @return array{label: string, passed: bool} */
    private function result(array $reservation): array
    {
        $value = $this->first($reservation, [
            'result_status', 'exam_result', 'result', 'outcome', 'exam_status',
            'reservation_status', 'status',
        ]) ?? '';
        $value = strtolower(trim($value));
        $certificate = data_get($reservation, 'certificate');
        $hasCertificate = is_array($certificate)
            ? count(array_filter($certificate, static fn ($item): bool => $item !== null && $item !== '')) > 0
            : is_string($certificate) && trim($certificate) !== '';

        if ($hasCertificate || preg_match('/(^|[^a-z])(pass|passed|successful|success)([^a-z]|$)/', $value)) {
            return ['label' => 'Passed', 'passed' => true];
        }
        if (preg_match('/fail|reject|unsuccess|not[ _-]?pass/', $value)) {
            return ['label' => 'Failed', 'passed' => false];
        }

        return ['label' => 'Pending', 'passed' => false];
    }

    /** @param list<array{text: string, size: int, bold: bool}> $lines */
    private function buildPdf(array $lines): string
    {
        $wrapped = [];
        foreach ($lines as $line) {
            if ($line['text'] === '') {
                $wrapped[] = $line;
                continue;
            }
            foreach (explode("\n", wordwrap($this->ascii($line['text']), 92, "\n", true)) as $part) {
                $wrapped[] = ['text' => $part, 'size' => $line['size'], 'bold' => $line['bold']];
            }
        }

        $pages = array_chunk($wrapped, 43);
        $pageCount = max(1, count($pages));
        $fontRegular = 3 + (2 * $pageCount);
        $fontBold = $fontRegular + 1;
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(
                static fn (int $index): string => (string) (3 + (2 * $index)).' 0 R',
                range(0, $pageCount - 1),
            )).'] /Count '.$pageCount.' >>',
        ];

        foreach ($pages as $index => $pageLines) {
            $pageId = 3 + (2 * $index);
            $contentId = $pageId + 1;
            $stream = "q\nBT\n50 790 Td\n";
            $first = true;
            foreach ($pageLines as $line) {
                $leading = $line['size'] >= 16 ? 25 : ($line['size'] >= 12 ? 20 : 15);
                if (! $first) {
                    $stream .= '0 -'.$leading." Td\n";
                }
                $first = false;
                if ($line['text'] === '') {
                    $stream .= "0 -10 Td\n";
                    continue;
                }
                $font = $line['bold'] ? 2 : 1;
                $stream .= sprintf("/F%d %d Tf\n(%s) Tj\n", $font, $line['size'], $this->pdfEscape($line['text']));
            }
            $stream .= "ET\nQ\n";

            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.$fontRegular.' 0 R /F2 '.$fontBold.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = "<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream";
        }
        $objects[$fontRegular] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxId + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".($maxId + 1).' /Root 1 0 R >>' . "\nstartxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }

    /** @param array<string, mixed> $data */
    private function first(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            $scalar = $this->scalar($value);
            if ($scalar !== null && trim($scalar) !== '') {
                return trim($scalar);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function firstValue(array $data, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? trim((string) $value) : null;
    }

    private function percentage(mixed $value): ?string
    {
        $value = $this->scalar($value);
        if ($value === null) {
            return null;
        }

        return str_ends_with($value, '%') ? $value : $value.'%';
    }

    private function slug(?string $value): string
    {
        $value = $this->ascii((string) ($value ?? ''));
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?: '';

        return trim($value, '_');
    }

    private function ascii(string $value): string
    {
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\x20-\x7E]/', ' ', $value) ?: '') ?: '');
    }

    private function pdfEscape(string $value): string
    {
        return str_replace(["\\", '(', ')'], ["\\\\", '\\(', '\\)'], $this->ascii($value));
    }
}
