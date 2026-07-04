package com.scholarzim.entity;

import jakarta.persistence.*;
import lombok.Getter;
import lombok.Setter;

import java.time.LocalDateTime;


@Entity
@Table(name = "provider_profiles")
@Getter
@Setter
public class ProviderProfile {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    @Column(name = "profile_id")
    private Long profileId;

    @OneToOne
    @JoinColumn(name = "user_id", nullable = false, unique = true)
    private User user;

    @Column(name = "organisation_type", nullable = false)
    private String organisationType;

    @Column(name = "registration_number", nullable = false)
    private String registrationNumber;

    @Column(name = "certificate_path", nullable = false)
    private String certificatePath;

    @Column(name = "certificate_filename", nullable = false)
    private String certificateFilename;

    @Column(name = "submitted_at", nullable = false)
    private LocalDateTime submittedAt;

    @Column(name = "reviewed_at")
    private LocalDateTime reviewedAt;

    @Column(name = "reviewed_by")
    private String reviewedBy;

    @Column(name = "rejection_reason", length = 500)
    private String rejectionReason;
}
