package com.scholarzim.service.impl;

import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.service.ReportService;
import org.springframework.stereotype.Service;

import java.awt.Color;
import java.io.ByteArrayOutputStream;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.List;

import com.lowagie.text.Document;
import com.lowagie.text.Element;
import com.lowagie.text.Font;
import com.lowagie.text.FontFactory;
import com.lowagie.text.PageSize;
import com.lowagie.text.Paragraph;
import com.lowagie.text.Phrase;
import com.lowagie.text.pdf.PdfPCell;
import com.lowagie.text.pdf.PdfPTable;
import com.lowagie.text.pdf.PdfWriter;

@Service
public class ReportServiceImpl implements ReportService {

    private static final DateTimeFormatter DATE_FMT =
            DateTimeFormatter.ofPattern("dd MMM yyyy");
    private static final DateTimeFormatter DATETIME_FMT =
            DateTimeFormatter.ofPattern("dd MMM yyyy HH:mm");
    private static final Color HEADER_BG = new Color(43, 108, 176);

    private final UserRepository userRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicationRepository applicationRepository;
    private final RecommendationService recommendationService;

    public ReportServiceImpl(
            UserRepository userRepository,
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository,
            RecommendationService recommendationService) {

        this.userRepository = userRepository;
        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
        this.recommendationService = recommendationService;
    }

    @Override
    public byte[] usersReportPdf() {

        return buildDocument("Users Report", document -> {

            PdfPTable table = newTable(new float[]{3, 4, 2, 2, 2});
            addHeaders(table, "Full Name", "Email", "Phone", "Role", "Status");

            for (User user : userRepository.findAll()) {
                addCell(table, user.getFullName());
                addCell(table, user.getEmail());
                addCell(table, user.getPhone());
                addCell(table, user.getRole() != null ? user.getRole().getRoleName() : "");
                addCell(table, user.getAccountStatus());
            }

            document.add(table);
        });
    }

    @Override
    public byte[] opportunitiesReportPdf() {

        return buildDocument("Opportunities Report", document -> {

            PdfPTable table = newTable(new float[]{3, 3, 2, 2, 2, 2});
            addHeaders(table, "Title", "Provider", "Education", "Field", "Country", "Deadline");

            for (Opportunity opp : opportunityRepository.findAll()) {
                addCell(table, opp.getTitle());
                addCell(table, opp.getProviderName());
                addCell(table, opp.getEducationLevel());
                addCell(table, opp.getTargetField());
                addCell(table, opp.getCountry());
                addCell(table, opp.getDeadline() != null
                        ? opp.getDeadline().format(DATE_FMT) : "—");
            }

            document.add(table);
        });
    }

    @Override
    public byte[] applicationsReportPdf() {

        return buildDocument("Applications Report", document -> {

            PdfPTable table = newTable(new float[]{3, 4, 2, 3});
            addHeaders(table, "Applicant", "Opportunity", "Status", "Submitted");

            for (Application app : applicationRepository.findAll()) {
                addCell(table, app.getUser() != null ? app.getUser().getFullName() : "");
                addCell(table, app.getOpportunity() != null ? app.getOpportunity().getTitle() : "");
                addCell(table, app.getApplicationStatus());
                addCell(table, app.getSubmittedAt() != null
                        ? app.getSubmittedAt().format(DATETIME_FMT) : "—");
            }

            document.add(table);
        });
    }

    @Override
    public byte[] recommendationsReportPdf() {

        return buildDocument("Recommendation Report", document -> {

            List<User> applicants =
                    userRepository.findByRoleRoleName("ROLE_APPLICANT");

            Font sectionFont = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 12);

            for (User applicant : applicants) {

                List<ScoredOpportunityDTO> recommendations =
                        recommendationService.recommendForApplicant(applicant.getEmail());

                if (recommendations.isEmpty()) {
                    continue;
                }

                Paragraph heading = new Paragraph(
                        applicant.getFullName() + " (" + applicant.getEmail() + ")",
                        sectionFont);
                heading.setSpacingBefore(12);
                heading.setSpacingAfter(6);
                document.add(heading);

                PdfPTable table = newTable(new float[]{4, 3, 2, 2});
                addHeaders(table, "Opportunity", "Provider", "Match %", "Deadline");

                for (ScoredOpportunityDTO scored : recommendations) {
                    Opportunity opp = scored.getOpportunity();
                    addCell(table, opp.getTitle());
                    addCell(table, opp.getProviderName());
                    addCell(table, scored.getMatchScore() + "%");
                    addCell(table, opp.getDeadline() != null
                            ? opp.getDeadline().format(DATE_FMT) : "—");
                }

                document.add(table);
            }
        });
    }

    // ---------- helpers ----------

    @FunctionalInterface
    private interface DocumentBody {
        void write(Document document) throws Exception;
    }

    private byte[] buildDocument(String title, DocumentBody body) {

        Document document = new Document(PageSize.A4.rotate(), 36, 36, 42, 36);
        ByteArrayOutputStream out = new ByteArrayOutputStream();

        try {
            PdfWriter.getInstance(document, out);
            document.open();

            Font titleFont = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 18, HEADER_BG);
            Paragraph heading = new Paragraph(title, titleFont);
            heading.setSpacingAfter(4);
            document.add(heading);

            Font subFont = FontFactory.getFont(FontFactory.HELVETICA, 9, Color.GRAY);
            Paragraph generated = new Paragraph(
                    "ScholarZim • Generated " + LocalDateTime.now().format(DATETIME_FMT),
                    subFont);
            generated.setSpacingAfter(12);
            document.add(generated);

            body.write(document);

            document.close();
            return out.toByteArray();

        } catch (Exception ex) {
            throw new RuntimeException("Failed to generate PDF report: " + title, ex);
        }
    }

    private PdfPTable newTable(float[] widths) {
        PdfPTable table = new PdfPTable(widths);
        table.setWidthPercentage(100);
        table.setSpacingBefore(4);
        return table;
    }

    private void addHeaders(PdfPTable table, String... headers) {
        Font font = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 10, Color.WHITE);
        for (String header : headers) {
            PdfPCell cell = new PdfPCell(new Phrase(header, font));
            cell.setBackgroundColor(HEADER_BG);
            cell.setPadding(6);
            cell.setHorizontalAlignment(Element.ALIGN_LEFT);
            table.addCell(cell);
        }
    }

    private void addCell(PdfPTable table, String value) {
        Font font = FontFactory.getFont(FontFactory.HELVETICA, 9);
        PdfPCell cell = new PdfPCell(new Phrase(value != null ? value : "—", font));
        cell.setPadding(5);
        table.addCell(cell);
    }
}
