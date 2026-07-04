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
}
