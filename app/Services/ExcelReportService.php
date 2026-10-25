<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\Catalogue\ListingScopes;
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
        $scopes = app(ListingScopes::class);

        return $this->build('Opportunities', [
            'Title', 'Provider', 'Education Level', 'Field',
            'Location', 'Funding', 'Deadline', 'Status', 'Open to', 'Institutions',
        ], Opportunity::notDraft()->with(['scopes.programme.field', 'scopes.field', 'scopes.institution'])->orderBy('opportunity_id')->lazy(200), fn (Opportunity $opp) => [
            $opp->title,
            $opp->provider_name,
            $opp->education_level ? \App\Support\EducationLevel::label($opp->education_level) : null,
            $scopes->fieldLabels($opp->scopes, $opp->target_field),
            $opp->target_locality ?: $opp->required_province,
            $opp->funding_type,
            $this->format($opp->deadline, self::DATE_FMT),
            // What the listing's state really is: one that is waiting for review or was declined is not "active".
            $opp->lifecycleLabel(),
            // The Field column names the field a programme belongs to; this says whether the listing is open to
            // those programmes only, to the whole field, or (stated on purpose) to every programme. Blank: not stated.
            $opp->open_to_all_programmes === true ? 'All programmes' : $scopes->describeProgrammes($opp->scopes),
            // Catalogue institutions, then any the provider typed in because the catalogue does not list them.
            $scopes->describeInstitutions($opp->scopes, (array) $opp->custom_institutions),
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

    /**
     * System activities: every audit entry, newest first, with the before-and-after values where they were recorded.
     * The values were redacted when written (AuditService), so nothing secret is in them.
     */
    public function activitiesExcel(): string
    {
        $encode = static fn (?array $values): string => blank($values) ? '' : (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->build('System Activities', [
            'Date and time', 'Actor', 'Actor ID', 'Action', 'Record type', 'Record ID', 'Details', 'Reason',
            'Old values', 'New values', 'IP address', 'Request ID',
        ], AuditLog::query()->orderByDesc('created_at')->orderByDesc('audit_id')->cursor(), fn (AuditLog $entry) => [
            $this->format($entry->created_at, 'd M Y H:i:s'),
            $entry->actor_email ?: 'System',
            $entry->actor_user_id,
            \App\Support\AuditAction::displayLabel($entry->action),
            $entry->entity_type,
            $entry->entity_id,
            $entry->details,
            $entry->reason,
            $encode($entry->old_values),
            $encode($entry->new_values),
            $entry->ip_address,
            $entry->request_id,
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
