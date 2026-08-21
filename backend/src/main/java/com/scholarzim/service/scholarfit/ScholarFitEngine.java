package com.scholarzim.service.scholarfit;

import com.scholarzim.dto.MatchBreakdownDTO;
import com.scholarzim.dto.MatchReasonDTO;
import com.scholarzim.dto.ScoredOpportunityDTO;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Opportunity;
import org.springframework.stereotype.Component;
import org.springframework.util.StringUtils;

import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;


@Component
public class ScholarFitEngine {

    private static final Map<String, Set<String>> RELATED_FIELDS = Map.of(
            "Computer Science", Set.of("Information Technology", "Software Engineering", "Data Science"),
            "Information Technology", Set.of("Computer Science", "Software Engineering"),
            "Medicine", Set.of("Nursing", "Pharmacy", "Public Health"),
            "Accounting", Set.of("Finance", "Economics", "Business Administration"),
            "Engineering", Set.of("Mechanical Engineering", "Civil Engineering", "Electrical Engineering")
    );

    private static final Map<String, Set<String>> RELATED_LEVELS = Map.of(
            "Undergraduate", Set.of("Honours", "Bachelor"),
            "Honours", Set.of("Undergraduate", "Bachelor"),
            "Masters", Set.of("Postgraduate", "PhD"),
            "PhD", Set.of("Postgraduate", "Masters")
    );

    private static final Pattern POINTS_PATTERN = Pattern.compile(
            "(\\d{1,2})\\s*points?",
            Pattern.CASE_INSENSITIVE);

    /** Optional fallback for international profiles that still mention GPA. */
    private static final Pattern GPA_PATTERN = Pattern.compile(
            "(?:gpa|grade point average)\\s*[:=]?\\s*(\\d+(?:\\.\\d+)?)",
            Pattern.CASE_INSENSITIVE);

    public ScoredOpportunityDTO evaluate(ApplicantProfile profile, Opportunity opportunity) {
        MatchBreakdownDTO breakdown = new MatchBreakdownDTO();
        List<MatchReasonDTO> reasons = new ArrayList<>();
        List<String> missing = new ArrayList<>();

        breakdown.setAcademicScore(scoreAcademic(profile, opportunity, reasons, missing));
        breakdown.setEducationLevelScore(scoreEducationLevel(profile, opportunity, reasons, missing));
        breakdown.setFieldScore(scoreField(profile, opportunity, reasons, missing));
        breakdown.setLocationScore(scoreLocation(profile, opportunity, reasons, missing));
        breakdown.setDeadlineScore(scoreDeadline(opportunity, reasons, missing));
        breakdown.setCertificateScore(scoreCertificate(profile, reasons, missing));

        int rawTotal = breakdown.totalScore();
        int matchScore = Math.min(100, rawTotal);

        breakdown.setReasons(reasons);
        breakdown.setMissingRequirements(missing);
        breakdown.setConfidenceLevel(resolveConfidence(matchScore));
        breakdown.setConfidenceLabel(resolveConfidenceLabel(matchScore));
        breakdown.setExplanation(buildExplanation(matchScore, reasons));

        return new ScoredOpportunityDTO(opportunity, matchScore, breakdown);
    }

    private int scoreAcademic(
            ApplicantProfile profile,
            Opportunity opportunity,
            List<MatchReasonDTO> reasons,
            List<String> missing) {

        boolean qualifies = hasQualifyingAcademicRecord(profile);
        reasons.add(new MatchReasonDTO(
                "academicResults",
                "Your results look competitive",
                qualifies));

        if (!qualifies) {
            missing.add("Add O/A-Level points, subject grades, or degree class to your profile");
            return 0;
        }
        return 20;
    }

    private int scoreEducationLevel(
            ApplicantProfile profile,
            Opportunity opportunity,
            List<MatchReasonDTO> reasons,
            List<String> missing) {

        String profileLevel = profile.getEducationLevel();
        String oppLevel = opportunity.getEducationLevel();
        boolean ageOk = false;
        int score = 0;

        if (isBlank(profileLevel)) {
            missing.add("Complete your education level on your profile");
            reasons.add(new MatchReasonDTO("degree", "Your degree matches", false));
            reasons.add(new MatchReasonDTO("age", "Age requirement satisfied", false));
            return 0;
        }

        if (isBlank(oppLevel)) {
            ageOk = true;
            score = 15;
            reasons.add(new MatchReasonDTO("degree", "Your degree matches", true));
        } else if (profileLevel.equalsIgnoreCase(oppLevel.trim())) {
            ageOk = true;
            score = 25;
            reasons.add(new MatchReasonDTO("degree", "Your degree matches", true));
        } else {
            Set<String> related = RELATED_LEVELS.getOrDefault(profileLevel.trim(), Set.of());
            if (related.stream().anyMatch(r -> r.equalsIgnoreCase(oppLevel.trim()))) {
                ageOk = true;
                score = 15;
                reasons.add(new MatchReasonDTO("degree", "Your degree matches", true));
            } else {
                reasons.add(new MatchReasonDTO("degree", "Your degree matches", false));
                missing.add("Requires " + oppLevel + " — your profile shows " + profileLevel);
            }
        }

        reasons.add(new MatchReasonDTO(
                "age",
                "Age requirement satisfied",
                ageOk && !isBlank(profileLevel)));
        if (!ageOk && !missing.stream().anyMatch(m -> m.contains("education level"))) {
            missing.add("Education level may not meet scholarship eligibility");
        }

        return score;
    }

    private int scoreField(
            ApplicantProfile profile,
            Opportunity opportunity,
            List<MatchReasonDTO> reasons,
            List<String> missing) {

        String profileField = profile.getFieldOfStudy();
        String oppField = opportunity.getTargetField();

        if (isBlank(oppField)) {
            reasons.add(new MatchReasonDTO("field", "Field of study aligns", !isBlank(profileField)));
            return isBlank(profileField) ? 0 : 10;
        }

        if (isBlank(profileField)) {
            reasons.add(new MatchReasonDTO("field", "Field of study aligns", false));
            missing.add("Add your field of study to your profile");
            return 0;
        }

        if (profileField.equalsIgnoreCase(oppField.trim())) {
            reasons.add(new MatchReasonDTO("field", "Field of study aligns", true));
            return 25;
        }

        Set<String> related = RELATED_FIELDS.getOrDefault(profileField.trim(), Set.of());
        if (related.stream().anyMatch(r -> r.equalsIgnoreCase(oppField.trim()))) {
            reasons.add(new MatchReasonDTO("field", "Field of study aligns", true));
            return 15;
        }

        reasons.add(new MatchReasonDTO("field", "Field of study aligns", false));
        missing.add("Targets " + oppField + " — your profile shows " + profileField);
        return 0;
    }

    private int scoreLocation(
            ApplicantProfile profile,
            Opportunity opportunity,
            List<MatchReasonDTO> reasons,
            List<String> missing) {

        int score = 0;
        boolean locationOk = false;

        if (!isBlank(profile.getCountry()) && !isBlank(opportunity.getTargetCountry())
                && profile.getCountry().equalsIgnoreCase(opportunity.getTargetCountry().trim())) {
            score = 15;
            locationOk = true;
        } else if (!isBlank(profile.getCountry()) && !isBlank(opportunity.getCountry())
                && profile.getCountry().equalsIgnoreCase(opportunity.getCountry().trim())) {
            score = 10;
            locationOk = true;
        } else if (isBlank(opportunity.getTargetCountry()) && isBlank(opportunity.getCountry())) {
            score = 8;
            locationOk = !isBlank(profile.getCountry());
        }

        if (!isBlank(profile.getProvince()) && "Rural".equalsIgnoreCase(profile.getProvince())) {
            score = Math.min(score + 3, 15);
        }

        reasons.add(new MatchReasonDTO("location", "Location eligibility met", locationOk || score > 0));

        if (!locationOk && score == 0) {
            if (isBlank(profile.getCountry())) {
                missing.add("Add your country on your profile");
            } else if (!isBlank(opportunity.getTargetCountry())) {
                missing.add("Scholarship targets applicants in " + opportunity.getTargetCountry());
            }
        }

        return score;
    }

    private int scoreDeadline(
            Opportunity opportunity,
            List<MatchReasonDTO> reasons,
            List<String> missing) {

        if (opportunity.getDeadline() == null) {
            reasons.add(new MatchReasonDTO("deadline", "Deadline still open", true));
            return 8;
        }

        long days = ChronoUnit.DAYS.between(LocalDate.now(), opportunity.getDeadline());
        if (days < 0) {
            reasons.add(new MatchReasonDTO("deadline", "Deadline still open", false));
            missing.add("Application deadline has passed");
            return 0;
        }

        reasons.add(new MatchReasonDTO("deadline", "Deadline still open", true));
        if (days <= 14) {
            return 10;
        }
        if (days <= 30) {
            return 8;
        }
        return 5;
    }

    private int scoreCertificate(
            ApplicantProfile profile,
            List<MatchReasonDTO> reasons,
            List<String> missing) {

        boolean uploaded = StringUtils.hasText(profile.getResultsCertificatePath());
        reasons.add(new MatchReasonDTO(
                "certificate",
                "Results certificate uploaded",
                uploaded));

        if (!uploaded) {
            missing.add("Upload your results certificate before applying");
            return 0;
        }
        return 5;
    }

    private boolean hasQualifyingAcademicRecord(ApplicantProfile profile) {
        if (!StringUtils.hasText(profile.getAcademicResults())) {
            return false;
        }
        String results = profile.getAcademicResults().trim();
        String lower = results.toLowerCase();

        if (lower.contains("distinction") || lower.contains("first class")
                || lower.contains("upper second") || lower.contains("cum laude")) {
            return true;
        }

        Matcher points = POINTS_PATTERN.matcher(results);
        if (points.find()) {
            try {
                return Integer.parseInt(points.group(1)) >= 6;
            } catch (NumberFormatException ignored) {
                // fall through
            }
        }

        Matcher gpa = GPA_PATTERN.matcher(results);
        if (gpa.find()) {
            try {
                return Double.parseDouble(gpa.group(1)) >= 2.0;
            } catch (NumberFormatException ignored) {
                // fall through
            }
        }

        if (lower.matches(".*\\b(a\\+?|b\\+?|pass|credit|merit|honours?)\\b.*")) {
            return true;
        }

        if (lower.contains("o-level") || lower.contains("a-level") || lower.contains("zimsec")) {
            return results.length() >= 8;
        }

        if (results.matches(".*\\b([6-9]\\d|100)\\s*%.*")) {
            return true;
        }

        return results.length() >= 12;
    }

    private String resolveConfidence(int matchScore) {
        if (matchScore >= 75) {
            return "HIGH";
        }
        if (matchScore >= 45) {
            return "MEDIUM";
        }
        return "LOW";
    }

    private String resolveConfidenceLabel(int matchScore) {
        if (matchScore >= 75) {
            return "High confidence";
        }
        if (matchScore >= 45) {
            return "Moderate confidence";
        }
        return "Low confidence";
    }

    private String buildExplanation(int matchScore, List<MatchReasonDTO> reasons) {
        long satisfied = reasons.stream().filter(MatchReasonDTO::isSatisfied).count();
        return matchScore + "% ScholarFit match · " + satisfied + " of " + reasons.size() + " requirements met";
    }

    private boolean isBlank(String value) {
        return value == null || value.isBlank();
    }
}
