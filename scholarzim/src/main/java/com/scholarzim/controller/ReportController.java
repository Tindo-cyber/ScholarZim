package com.scholarzim.controller;

import com.scholarzim.service.ExcelReportService;
import com.scholarzim.service.ReportService;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.stereotype.Controller;
import org.springframework.web.bind.annotation.GetMapping;

@Controller
public class ReportController {

    private static final MediaType XLSX = MediaType.parseMediaType(
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");

    private final ReportService reportService;
    private final ExcelReportService excelReportService;

    public ReportController(
            ReportService reportService,
            ExcelReportService excelReportService) {

        this.reportService = reportService;
        this.excelReportService = excelReportService;
    }

    @GetMapping("/admin/reports/users.pdf")
    public ResponseEntity<byte[]> usersReport() {
        return pdf(reportService.usersReportPdf(), "users-report.pdf");
    }

    @GetMapping("/admin/reports/opportunities.pdf")
    public ResponseEntity<byte[]> opportunitiesReport() {
        return pdf(reportService.opportunitiesReportPdf(), "opportunities-report.pdf");
    }

    @GetMapping("/admin/reports/applications.pdf")
    public ResponseEntity<byte[]> applicationsReport() {
        return pdf(reportService.applicationsReportPdf(), "applications-report.pdf");
    }

    @GetMapping("/admin/reports/recommendations.pdf")
    public ResponseEntity<byte[]> recommendationsReport() {
        return pdf(reportService.recommendationsReportPdf(), "recommendations-report.pdf");
    }

    @GetMapping("/admin/reports/users.xlsx")
    public ResponseEntity<byte[]> usersExcel() {
        return excel(excelReportService.usersExcel(), "users-report.xlsx");
    }

    @GetMapping("/admin/reports/opportunities.xlsx")
    public ResponseEntity<byte[]> opportunitiesExcel() {
        return excel(excelReportService.opportunitiesExcel(), "opportunities-report.xlsx");
    }

    @GetMapping("/admin/reports/applications.xlsx")
    public ResponseEntity<byte[]> applicationsExcel() {
        return excel(excelReportService.applicationsExcel(), "applications-report.xlsx");
    }

    private ResponseEntity<byte[]> pdf(byte[] body, String filename) {
        return ResponseEntity.ok()
                .header(HttpHeaders.CONTENT_DISPOSITION,
                        "attachment; filename=\"" + filename + "\"")
                .contentType(MediaType.APPLICATION_PDF)
                .body(body);
    }

    private ResponseEntity<byte[]> excel(byte[] body, String filename) {
        return ResponseEntity.ok()
                .header(HttpHeaders.CONTENT_DISPOSITION,
                        "attachment; filename=\"" + filename + "\"")
                .contentType(XLSX)
                .body(body);
    }
}
