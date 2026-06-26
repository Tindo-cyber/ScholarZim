package com.scholarzim.service.impl;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ExcelReportService;
import org.apache.poi.ss.usermodel.Cell;
import org.apache.poi.ss.usermodel.CellStyle;
import org.apache.poi.ss.usermodel.Font;
import org.apache.poi.ss.usermodel.IndexedColors;
import org.apache.poi.ss.usermodel.Row;
import org.apache.poi.ss.usermodel.Sheet;
import org.apache.poi.ss.usermodel.Workbook;
import org.apache.poi.xssf.usermodel.XSSFWorkbook;
import org.springframework.stereotype.Service;

import java.io.ByteArrayOutputStream;
import java.time.format.DateTimeFormatter;

@Service
public class ExcelReportServiceImpl implements ExcelReportService {

    private static final DateTimeFormatter DATE_FMT =
            DateTimeFormatter.ofPattern("dd MMM yyyy");
    private static final DateTimeFormatter DATETIME_FMT =
            DateTimeFormatter.ofPattern("dd MMM yyyy HH:mm");

    private final UserRepository userRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicationRepository applicationRepository;

    public ExcelReportServiceImpl(
            UserRepository userRepository,
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository) {

        this.userRepository = userRepository;
        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
    }

    @Override
    public byte[] usersExcel() {

        try (Workbook workbook = new XSSFWorkbook()) {

            Sheet sheet = workbook.createSheet("Users");
            CellStyle headerStyle = headerStyle(workbook);

            writeHeader(sheet, headerStyle,
                    "Full Name", "Email", "Phone", "Role", "Status");

            int rowIdx = 1;
            for (User user : userRepository.findAll()) {
                Row row = sheet.createRow(rowIdx++);
                set(row, 0, user.getFullName());
                set(row, 1, user.getEmail());
                set(row, 2, user.getPhone());
                set(row, 3, user.getRole() != null ? user.getRole().getRoleName() : "");
                set(row, 4, user.getAccountStatus());
            }

            return toBytes(sheet, workbook, 5);

        } catch (Exception ex) {
            throw new RuntimeException("Failed to generate Users Excel report", ex);
        }
    }

    @Override
    public byte[] opportunitiesExcel() {

        try (Workbook workbook = new XSSFWorkbook()) {

            Sheet sheet = workbook.createSheet("Opportunities");
            CellStyle headerStyle = headerStyle(workbook);

            writeHeader(sheet, headerStyle,
                    "Title", "Provider", "Education Level", "Field",
                    "Country", "Funding", "Deadline", "Status");

            int rowIdx = 1;
            for (Opportunity opp : opportunityRepository.findAll()) {
                Row row = sheet.createRow(rowIdx++);
                set(row, 0, opp.getTitle());
                set(row, 1, opp.getProviderName());
                set(row, 2, opp.getEducationLevel());
                set(row, 3, opp.getTargetField());
                set(row, 4, opp.getCountry());
                set(row, 5, opp.getFundingType());
                set(row, 6, opp.getDeadline() != null ? opp.getDeadline().format(DATE_FMT) : "");
                set(row, 7, opp.getStatus());
            }

            return toBytes(sheet, workbook, 8);

        } catch (Exception ex) {
            throw new RuntimeException("Failed to generate Opportunities Excel report", ex);
        }
    }

    @Override
    public byte[] applicationsExcel() {

        try (Workbook workbook = new XSSFWorkbook()) {

            Sheet sheet = workbook.createSheet("Applications");
            CellStyle headerStyle = headerStyle(workbook);

            writeHeader(sheet, headerStyle,
                    "Applicant", "Email", "Opportunity", "Status", "Submitted");

            int rowIdx = 1;
            for (Application app : applicationRepository.findAll()) {
                Row row = sheet.createRow(rowIdx++);
                User applicant = app.getUser();
                Opportunity opp = app.getOpportunity();
                set(row, 0, applicant != null ? applicant.getFullName() : "");
                set(row, 1, applicant != null ? applicant.getEmail() : "");
                set(row, 2, opp != null ? opp.getTitle() : "");
                set(row, 3, app.getApplicationStatus());
                set(row, 4, app.getSubmittedAt() != null
                        ? app.getSubmittedAt().format(DATETIME_FMT) : "");
            }

            return toBytes(sheet, workbook, 5);

        } catch (Exception ex) {
            throw new RuntimeException("Failed to generate Applications Excel report", ex);
        }
    }

    // ---------- helpers ----------

    private CellStyle headerStyle(Workbook workbook) {
        CellStyle style = workbook.createCellStyle();
        Font font = workbook.createFont();
        font.setBold(true);
        font.setColor(IndexedColors.WHITE.getIndex());
        style.setFont(font);
        style.setFillForegroundColor(IndexedColors.DARK_BLUE.getIndex());
        style.setFillPattern(org.apache.poi.ss.usermodel.FillPatternType.SOLID_FOREGROUND);
        return style;
    }

    private void writeHeader(Sheet sheet, CellStyle style, String... headers) {
        Row header = sheet.createRow(0);
        for (int i = 0; i < headers.length; i++) {
            Cell cell = header.createCell(i);
            cell.setCellValue(headers[i]);
            cell.setCellStyle(style);
        }
    }

    private void set(Row row, int column, String value) {
        row.createCell(column).setCellValue(value != null ? value : "");
    }

    private byte[] toBytes(Sheet sheet, Workbook workbook, int columnCount) throws Exception {
        for (int i = 0; i < columnCount; i++) {
            sheet.autoSizeColumn(i);
        }
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        workbook.write(out);
        return out.toByteArray();
    }
}
