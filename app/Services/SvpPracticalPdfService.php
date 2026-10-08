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
     * @return list<array{text: string, size: int, bold: bool, section: bool}>
     */
    private function reportLines(array $reservation, array $category, array $result, string $reservationId): array
    {
        $lines = [];
        $add = static function (array &$target, string $text, int $size = 10, bool $bold = false, bool $section = false): void {
            $target[] = ['text' => $text, 'size' => $size, 'bold' => $bold, 'section' => $section];
        };
        $blank = static function (array &$target): void {
            $target[] = ['text' => '', 'size' => 10, 'bold' => false, 'section' => false];
        };
        $section = static function (array &$target, string $text) use ($add): void {
            $add($target, $text, 11, true, true);
        };
        $field = static function (array &$target, string $label, ?string $value) use ($add): void {
            $add($target, $label.': '.(($value !== null && trim($value) !== '') ? trim($value) : 'Not provided'));
        };

        $examResult = (array) (data_get($reservation, 'examination_result')
            ?? data_get($reservation, 'exam_result_details')
            ?? []);
        $practicalScore = $this->firstValue($examResult, ['calculated_practical_score', 'practical_score']);
        $computerScore = $this->firstValue($examResult, ['calculated_cbt_score', 'cbt_score', 'computer_score']);
        $rawPracticalScore = $this->firstValue($examResult, ['original_practical_score', 'raw_practical_score']);
        $rawComputerScore = $this->firstValue($examResult, ['original_cbt_score', 'raw_cbt_score', 'original_computer_score']);
        $correctAnswers = $this->firstValue($examResult, ['cbt_correct_answers_count', 'computer_correct_answers_count']);
        $totalScore = $this->firstValue($examResult, ['total_score', 'calculated_total_score']);
        $practicalWeight = $this->firstValue($category, ['practical_weight']);
        $computerWeight = $this->firstValue($category, ['cbt_weight']);
        $occupation = $this->first($reservation, [
            'occupation.english_name', 'occupation.name', 'occupation_name', 'job_title',
            'candidate.occupation.english_name', 'category.english_name', 'category.name',
        ]) ?? $this->first($category, ['english_name', 'name']);
        $testCenter = $this->first($reservation, [
            'test_center_name', 'center_name', 'test_center.name',
            'test_center.english_name', 'test_center.data.attributes.name',
            'exam_session.test_center.name', 'exam_session.test_center.data.attributes.name',
        ]);
        $city = $this->first($reservation, [
            'city', 'city_name', 'test_center.city', 'test_center.city.name',
            'test_center.data.attributes.city', 'test_center.data.attributes.city.name',
            'exam_session.test_center.city', 'exam_session.test_center.city.name',
        ]);
        $examDate = $this->first($reservation, [
            'exam_date', 'test_date', 'date', 'exam_session.test_date', 'exam_session.exam_date',
            'exam_session.start_date_in_browser_time_zone', 'examSession.exam_date',
        ]);

        $add($lines, 'SVP Exam Results', 18, true);
        $add($lines, 'Practical and computer / CBT score summary', 9, false);
        $blank($lines);

        $section($lines, 'Result overview');
        $field($lines, 'Reservation ID', $reservationId);
        $field($lines, 'Candidate', $this->first($reservation, [
            'full_name', 'fullName', 'candidate_name', 'name',
            'candidate.full_name', 'user.full_name', 'candidate.name', 'user.name',
        ]));
        $field($lines, 'Occupation', $occupation);
        $field($lines, 'Test center', $testCenter);
        $field($lines, 'City', $city);
        $field($lines, 'Exam date', $examDate);
        $field($lines, 'Final result', $result['label']);
        $blank($lines);

        $section($lines, 'Practical examination');
        $field($lines, 'Status', $this->humanStatus($this->first($reservation, ['practical_exam_status'])));
        $field($lines, 'Weighted score', $this->scoreWithMaximum($practicalScore, $practicalWeight));
        $field($lines, 'Original score', $this->scalar($rawPracticalScore));
        $blank($lines);

        $section($lines, 'Computer examination (CBT)');
        $field($lines, 'Status', $this->humanStatus($this->first($reservation, ['cbt_exam_status', 'computer_exam_status'])));
        $field($lines, 'Weighted score', $this->scoreWithMaximum($computerScore, $computerWeight));
        $field($lines, 'Original score', $this->scalar($rawComputerScore));
        $field($lines, 'Correct answers', $this->scalar($correctAnswers));
        $blank($lines);

        $section($lines, 'Score summary');
        $field($lines, 'Total score', $this->scoreWithMaximum($totalScore, 100));
        $add($lines, $result['passed']
            ? 'The final result is passed.'
            : 'The practical and computer scores are not available for this reservation.');
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
            'final_result', 'result_status', 'exam_result', 'result', 'outcome', 'exam_status',
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

    /** @param list<array{text: string, size: int, bold: bool, section: bool}> $lines */
    private function buildPdf(array $lines): string
    {
        $wrapped = [];
        foreach ($lines as $line) {
            $section = (bool) ($line['section'] ?? false);
            if ($line['text'] === '') {
                $wrapped[] = ['text' => '', 'size' => 10, 'bold' => false, 'section' => false];
                continue;
            }
            foreach (explode("\n", wordwrap($this->ascii($line['text']), 92, "\n", true)) as $part) {
                $wrapped[] = [
                    'text' => $part,
                    'size' => $line['size'],
                    'bold' => $line['bold'],
                    'section' => $section,
                ];
            }
        }

        $pages = array_chunk($wrapped, 45);
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
            // UNICEF-inspired palette: bright blue, deep navy text, and a yellow accent.
            $stream = "q\n0.00 0.68 0.89 rg\n50 750 495 65 re f\n1 0.72 0.11 rg\n50 810 495 5 re f\nQ\n";
            $y = 725;

            foreach ($pageLines as $lineIndex => $line) {
                if ($line['text'] === '') {
                    $y -= 10;
                    continue;
                }

                if ($index === 0 && $lineIndex === 0) {
                    $x = 65;
                    $y = 795;
                    $size = 18;
                    $font = 2;
                    $color = '1 1 1';
                } elseif ($index === 0 && $lineIndex === 1) {
                    $x = 65;
                    $y = 775;
                    $size = 9;
                    $font = 1;
                    $color = '0.85 0.91 0.98';
                } else {
                    $x = 50;
                    $size = $line['size'];
                    $font = $line['bold'] ? 2 : 1;
                    $color = '0.10 0.14 0.20';
                    if (($line['section'] ?? false) === true) {
                        $stream .= "q\n0.88 0.96 0.99 rg\n50 ".($y - 5)." 495 19 re f\n1 0.72 0.11 rg\n50 ".($y - 5)." 5 19 re f\nQ\n";
                    } elseif (str_starts_with($line['text'], 'Final result: Passed')) {
                        $color = '0.03 0.45 0.25';
                    } elseif (str_starts_with($line['text'], 'Final result: Failed')) {
                        $color = '0.75 0.12 0.12';
                    }
                }

                $stream .= "BT\n".$color." rg\n/F".$font.' '.$size." Tf\n1 0 0 1 ".$x.' '.$y." Tm\n(".$this->pdfEscape($line['text']).") Tj\nET\n";
                if ($index === 0 && $lineIndex === 1) {
                    $y = 725;
                } elseif (! ($index === 0 && $lineIndex < 2)) {
                    $y -= $line['size'] >= 12 ? 20 : 15;
                }
            }
            $stream .= "\n";

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

    private function scoreWithMaximum(mixed $score, mixed $maximum): ?string
    {
        $score = $this->scalar($score);
        if ($score === null) {
            return null;
        }

        $maximum = $this->scalar($maximum);

        return $maximum !== null ? $score.' / '.$maximum : $score;
    }

    private function humanStatus(?string $status): ?string
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        return ucfirst(str_replace(['_', '-'], ' ', strtolower(trim($status))));
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
