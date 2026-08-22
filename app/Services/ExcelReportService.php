<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Admin spreadsheet exports. Mirrors the sheets the Spring app produced with
 * Apache POI — same sheet names, column order and header styling — so the
 * files admins already archive keep the same shape.
 */
class ExcelReportService
{
    private const DATE_FMT = 'd M Y';

    private const DATETIME_FMT = 'd M Y H:i';

    private const HEADER_BG = '1F3864';

    public function usersExcel(): string
    {
        return $this->build('Users', [
            'Full Name', 'Email', 'Phone', 'Role', 'Status',
        ], User::with('role')->orderBy('user_id')->cursor(), static fn (User $user) => [
            $user->full_name,
            $user->email,
            $user->phone,
            $user->roleName(),
            $user->account_status,
        ]);
    }

    public function opportunitiesExcel(): string
    {
        return $this->build('Opportunities', [
            'Title', 'Provider', 'Education Level', 'Field',
            'Country', 'Funding', 'Deadline', 'Status',
        ], Opportunity::orderBy('opportunity_id')->cursor(), fn (Opportunity $opp) => [
            $opp->title,
            $opp->provider_name,
            $opp->education_level,
            $opp->target_field,
            $opp->country,
            $opp->funding_type,
            $this->format($opp->deadline, self::DATE_FMT),
            $opp->status,
        ]);
    }

    public function applicationsExcel(): string
    {
        return $this->build('Applications', [
            'Applicant', 'Email', 'Opportunity', 'Status', 'Submitted',
        ], Application::with(['user', 'opportunity'])->orderBy('application_id')->cursor(),
            fn (Application $app) => [
                $app->user?->full_name,
                $app->user?->email,
                $app->opportunity?->title,
                $app->application_status,
                $this->format($app->submitted_at, self::DATETIME_FMT),
            ]);
    }

    // ---------- helpers ----------

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<object>  $rows
     * @param  callable(object): array<int, ?string>  $mapper
     */
    private function build(string $sheetName, array $headers, iterable $rows, callable $mapper): string
    {
        $spreadsheet = new Spreadsheet();

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle($sheetName);

            $this->writeHeader($sheet, $headers);

            $rowIdx = 2;
            foreach ($rows as $record) {
                $column = 1;
                foreach ($mapper($record) as $value) {
                    $sheet->setCellValueExplicit(
                        [$column++, $rowIdx],
                        (string) ($value ?? ''),
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    );
                }
                $rowIdx++;
            }

            foreach (range(1, count($headers)) as $column) {
                $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
            }

            return $this->toBytes($spreadsheet);
        } finally {
            // PhpSpreadsheet holds cell caches until the object is released.
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @param  array<int, string>  $headers */
    private function writeHeader(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers): void
    {
        $column = 1;
        foreach ($headers as $header) {
            $sheet->setCellValue([$column++, 1], $header);
        }

        $style = $sheet->getStyle([1, 1, count($headers), 1]);
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . self::HEADER_BG);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    private function format(mixed $value, string $format): string
    {
        return $value?->format($format) ?? '';
    }

    private function toBytes(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }
}
