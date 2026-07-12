package com.scholarzim.util;

import com.scholarzim.entity.ApplicantProfile;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;


class ProfileCompletionSupportTest {

    @Test
    void calculatesPercentAcrossChecklistAndDocuments() {
        ApplicantProfile profile = new ApplicantProfile();
        profile.setEducationLevel("Undergraduate");
        profile.setInstitutionName("UZ");
        profile.setFieldOfStudy("Engineering");
        profile.setAcademicResults("15 points");
        profile.setCountry("Zimbabwe");
        profile.setBiography("Aspiring engineer");
        profile.setResultsCertificatePath("transcript.pdf");
        profile.setCvPath("cv.pdf");

        var snapshot = ProfileCompletionSupport.build(profile, path -> true);

        assertThat(snapshot.percent()).isEqualTo(80);
        assertThat(snapshot.checklist()).hasSize(6);
        assertThat(snapshot.documents()).hasSize(4);
        assertThat(snapshot.missingDocuments()).extracting(ProfileCompletionSupport.DocumentItem::label)
                .containsExactly("Passport", "Recommendation Letter");
    }

    @Test
    void awardsBadgesAtMilestones() {
        var snapshot = ProfileCompletionSupport.build(null, path -> false);

        assertThat(snapshot.badges()).hasSize(4);
        assertThat(snapshot.badges().get(0).earned()).isFalse();
        assertThat(snapshot.badges().get(0).threshold()).isEqualTo(25);

        var complete = ProfileCompletionSupport.build(completeProfile(), path -> true);
        assertThat(complete.percent()).isEqualTo(100);
        assertThat(complete.badges()).allMatch(ProfileCompletionSupport.BadgeItem::earned);
    }

    @Test
    void normalizesDocumentTypes() {
        assertThat(ProfileCompletionSupport.normalizeDocumentType("recommendation-letter"))
                .isEqualTo("RECOMMENDATION_LETTER");
        assertThat(ProfileCompletionSupport.storagePrefix("CV", 9L))
                .isEqualTo("applicant-cv-9");
    }

    private static ApplicantProfile completeProfile() {
        ApplicantProfile profile = new ApplicantProfile();
        profile.setEducationLevel("Undergraduate");
        profile.setInstitutionName("UZ");
        profile.setFieldOfStudy("Engineering");
        profile.setAcademicResults("15 points");
        profile.setCountry("Zimbabwe");
        profile.setBiography("Ready for scholarships");
        profile.setResultsCertificatePath("transcript.pdf");
        profile.setCvPath("cv.pdf");
        profile.setPassportPath("passport.jpg");
        profile.setRecommendationLetterPath("reference.pdf");
        return profile;
    }
}
