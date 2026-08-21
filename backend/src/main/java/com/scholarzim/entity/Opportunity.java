package com.scholarzim.entity;

import jakarta.persistence.*;
import lombok.Getter;
import lombok.Setter;

import java.time.LocalDate;
import java.time.LocalDateTime;


@Entity
@Table(name = "opportunities")
@Getter
@Setter
public class Opportunity {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    @Column(name = "opportunity_id")
    private Long opportunityId;

    @ManyToOne
    @JoinColumn(name = "provider_user_id")
    private User provider;

    private String title;

    @Column(columnDefinition = "TEXT")
    private String description;

    private String providerName;

    private String educationLevel;

    private String fundingType;

    private String country;

    private LocalDate deadline;

    private String status;

    private LocalDateTime createdAt;

    private String targetField;

    private String targetCountry;
}
