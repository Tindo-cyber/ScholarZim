package com.scholarzim.entity;

import jakarta.persistence.*;
import lombok.Getter;
import lombok.Setter;


@Entity
@Table(name = "applicant_profiles")
@Getter
@Setter
public class ApplicantProfile {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    @Column(name = "profile_id")
    private Long profileId;

    @ManyToOne
    @JoinColumn(name = "user_id")
    private User user;

    @Column(name = "education_level")
    private String educationLevel;

    @Column(name = "institution_name")
    private String institutionName;

    @Column(name = "field_of_study")
    private String fieldOfStudy;

    private String country;

    private String province;

    @Column(name = "academic_results")
    private String academicResults;

    @Column(columnDefinition = "TEXT")
    private String biography;

    @Column(name = "results_certificate_path")
    private String resultsCertificatePath;

    @Column(name = "results_certificate_filename")
    private String resultsCertificateFilename;

    @Column(name = "results_uploaded_at")
    private java.time.LocalDateTime resultsUploadedAt;

    @Column(name = "cv_path")
    private String cvPath;

    @Column(name = "cv_filename")
    private String cvFilename;

    @Column(name = "cv_uploaded_at")
    private java.time.LocalDateTime cvUploadedAt;

    @Column(name = "passport_path")
    private String passportPath;

    @Column(name = "passport_filename")
    private String passportFilename;

    @Column(name = "passport_uploaded_at")
    private java.time.LocalDateTime passportUploadedAt;

    @Column(name = "recommendation_letter_path")
    private String recommendationLetterPath;

    @Column(name = "recommendation_letter_filename")
    private String recommendationLetterFilename;

    @Column(name = "recommendation_letter_uploaded_at")
    private java.time.LocalDateTime recommendationLetterUploadedAt;
}
