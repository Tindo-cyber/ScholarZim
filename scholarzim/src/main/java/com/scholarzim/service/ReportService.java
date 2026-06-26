package com.scholarzim.service;

public interface ReportService {

    byte[] usersReportPdf();

    byte[] opportunitiesReportPdf();

    byte[] applicationsReportPdf();

    byte[] recommendationsReportPdf();
}
