package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;

import java.time.LocalDate;

@Getter
@Setter
public class OpportunityApiDTO {

    private Long id;
    private String title;
    private String description;
    private String providerName;
    private String educationLevel;
    private String fundingType;
    private String country;
    private String targetField;
    private LocalDate deadline;
    private String status;
}
