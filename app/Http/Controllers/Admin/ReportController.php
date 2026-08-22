<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ExcelReportService;
use App\Services\ReportService;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly ReportService $reportService,
        private readonly ExcelReportService $excelReportService,
    ) {
    }

    public function hub()
    {
        return view('admin.reports');
    }

    public function usersPdf(): Response
    {
        return $this->download($this->reportService->usersReportPdf(), 'users-report.pdf', 'application/pdf');
    }

    public function opportunitiesPdf(): Response
    {
        return $this->download($this->reportService->opportunitiesReportPdf(), 'opportunities-report.pdf', 'application/pdf');
    }

    public function applicationsPdf(): Response
    {
        return $this->download($this->reportService->applicationsReportPdf(), 'applications-report.pdf', 'application/pdf');
    }

    public function recommendationsPdf(): Response
    {
        return $this->download($this->reportService->recommendationsReportPdf(), 'recommendations-report.pdf', 'application/pdf');
    }

    public function usersExcel(): Response
    {
        return $this->download($this->excelReportService->usersExcel(), 'users-report.xlsx', self::XLSX);
    }

    public function opportunitiesExcel(): Response
    {
        return $this->download($this->excelReportService->opportunitiesExcel(), 'opportunities-report.xlsx', self::XLSX);
    }

    public function applicationsExcel(): Response
    {
        return $this->download($this->excelReportService->applicationsExcel(), 'applications-report.xlsx', self::XLSX);
    }

    private function download(string $body, string $filename, string $contentType): Response
    {
        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($body),
        ]);
    }
}
