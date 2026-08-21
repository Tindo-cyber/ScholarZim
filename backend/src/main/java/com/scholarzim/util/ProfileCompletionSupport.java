package com.scholarzim.util;

import com.scholarzim.entity.ApplicantProfile;
import org.springframework.util.StringUtils;

import java.util.ArrayList;
import java.util.List;
import java.util.function.Function;


public final class ProfileCompletionSupport {

    public static final List<Integer> BADGE_THRESHOLDS = List.of(25, 50, 75, 100);

    private ProfileCompletionSupport() {
    }

    public record ChecklistItem(String key, String label, String hint, boolean complete) {
    }

    public record DocumentItem(
            String key,
            String label,
            String icon,
            boolean uploaded,
            String filename,
            String uploadPath) {
    }

    public record BadgeItem(int threshold, String label, String icon, boolean earned) {
    }

    public record Snapshot(
            int percent,
            List<ChecklistItem> checklist,
            List<DocumentItem> documents,
            List<DocumentItem> missingDocuments,
            List<BadgeItem> badges) {
    }

    public static Snapshot build(ApplicantProfile profile, Function<String, Boolean> fileExists) {
        List<ChecklistItem> checklist = buildChecklist(profile);
        List<DocumentItem> documents = buildDocuments(profile, fileExists);
        List<DocumentItem> missingDocuments = documents.stream()
                .filter(document -> !document.uploaded())
                .toList();

        int totalItems = checklist.size() + documents.size();
        long completed = checklist.stream().filter(ChecklistItem::complete).count()
                + documents.stream().filter(DocumentItem::uploaded).count();
        int percent = totalItems == 0
                ? 0
                : (int) Math.round((completed * 100.0) / totalItems);

        return new Snapshot(
                percent,
                checklist,
                documents,
                missingDocuments,
                buildBadges(percent));
    }

    public static int computePercent(ApplicantProfile profile, Function<String, Boolean> fileExists) {
        return build(profile, fileExists).percent();
    }

    private static List<ChecklistItem> buildChecklist(ApplicantProfile profile) {
        return List.of(
                item("educationLevel", "Education level", "Select your current study level", profile, ApplicantProfile::getEducationLevel),
                item("institution", "Institution", "Add your school or university", profile, ApplicantProfile::getInstitutionName),
                item("fieldOfStudy", "Field of study", "Tell us what you are studying", profile, ApplicantProfile::getFieldOfStudy),
                item("academicResults", "Exam results", "Add O/A-Level points or grades", profile, ApplicantProfile::getAcademicResults),
                item("country", "Country", "Where you are based", profile, ApplicantProfile::getCountry),
                item("biography", "Biography", "Share your goals and achievements", profile, ApplicantProfile::getBiography));
    }

    private static List<DocumentItem> buildDocuments(
            ApplicantProfile profile,
            Function<String, Boolean> fileExists) {

        return List.of(
                document("cv", "CV", "bi-file-earmark-person",
                        profile != null ? profile.getCvPath() : null,
                        profile != null ? profile.getCvFilename() : null,
                        "/applicant/profile/documents/cv", fileExists),
                document("transcript", "Transcript", "bi-file-earmark-text",
                        profile != null ? profile.getResultsCertificatePath() : null,
                        profile != null ? profile.getResultsCertificateFilename() : null,
                        "/applicant/profile/documents/transcript", fileExists),
                document("passport", "Passport", "bi-passport",
                        profile != null ? profile.getPassportPath() : null,
                        profile != null ? profile.getPassportFilename() : null,
                        "/applicant/profile/documents/passport", fileExists),
                document("recommendation-letter", "Recommendation Letter", "bi-envelope-paper",
                        profile != null ? profile.getRecommendationLetterPath() : null,
                        profile != null ? profile.getRecommendationLetterFilename() : null,
                        "/applicant/profile/documents/recommendation-letter", fileExists));
    }

    private static List<BadgeItem> buildBadges(int percent) {
        return List.of(
                badge(25, "Getting Started", "bi-seedling", percent),
                badge(50, "Halfway Hero", "bi-lightning-charge", percent),
                badge(75, "Scholar Ready", "bi-award", percent),
                badge(100, "Profile Champion", "bi-trophy-fill", percent));
    }

    private static ChecklistItem item(
            String key,
            String label,
            String hint,
            ApplicantProfile profile,
            Function<ApplicantProfile, String> getter) {

        String value = profile == null ? null : getter.apply(profile);
        return new ChecklistItem(key, label, hint, StringUtils.hasText(value));
    }

    private static DocumentItem document(
            String key,
            String label,
            String icon,
            String path,
            String filename,
            String uploadPath,
            Function<String, Boolean> fileExists) {

        boolean uploaded = StringUtils.hasText(path) && Boolean.TRUE.equals(fileExists.apply(path));
        return new DocumentItem(key, label, icon, uploaded, filename, uploadPath);
    }

    private static BadgeItem badge(int threshold, String label, String icon, int percent) {
        return new BadgeItem(threshold, label, icon, percent >= threshold);
    }

    public static String documentLabel(String documentType) {
        return switch (normalizeDocumentType(documentType)) {
            case "CV" -> "CV";
            case "TRANSCRIPT" -> "Transcript";
            case "PASSPORT" -> "Passport";
            case "RECOMMENDATION_LETTER" -> "Recommendation letter";
            default -> "Document";
        };
    }

    public static String normalizeDocumentType(String documentType) {
        if (documentType == null) {
            return "";
        }
        return documentType.trim()
                .replace('-', '_')
                .toUpperCase();
    }

    public static String storagePrefix(String documentType, Long userId) {
        return switch (normalizeDocumentType(documentType)) {
            case "CV" -> "applicant-cv-" + userId;
            case "TRANSCRIPT" -> "applicant-results-" + userId;
            case "PASSPORT" -> "applicant-passport-" + userId;
            case "RECOMMENDATION_LETTER" -> "applicant-recommendation-" + userId;
            default -> throw new IllegalArgumentException("Unsupported document type.");
        };
    }
}
