package com.scholarzim.entity;

import jakarta.persistence.*;
import lombok.Getter;
import lombok.Setter;

import java.time.LocalDateTime;


@Entity
@Table(
        name = "applications",
        uniqueConstraints = @UniqueConstraint(
                name = "uk_applications_user_opportunity",
                columnNames = {"user_id", "opportunity_id"}
        )
)
@Getter
@Setter
public class Application {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    @Column(name = "application_id")
    private Long applicationId;

    @ManyToOne
    @JoinColumn(name = "user_id")
    private User user;

    @ManyToOne
    @JoinColumn(name = "opportunity_id")
    private Opportunity opportunity;

    @Column(name = "application_status")
    private String applicationStatus;

    @Column(name = "submitted_at")
    private LocalDateTime submittedAt;

    @Column(name = "personal_statement", columnDefinition = "TEXT")
    private String personalStatement;

    @Column(name = "document_filename")
    private String documentFilename;

    @Column(name = "document_path")
    private String documentPath;

    @Column(name = "rejection_reason", length = 500)
    private String rejectionReason;
}
