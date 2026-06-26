package com.scholarzim.dto;

import jakarta.validation.constraints.Future;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import lombok.Getter;
import lombok.Setter;

import java.time.LocalDate;

@Getter
@Setter
public class OpportunityRequest {

    @NotBlank(message = "Title is required")
    private String title;

    private String description;

    @NotBlank(message = "Education level is required")
    private String educationLevel;

    private String fundingType;

    private String country;

    @NotNull(message = "Deadline is required")
    @Future(message = "Deadline must be a future date")
    private LocalDate deadline;

    @NotBlank(message = "Target field is required")
    private String targetField;

    @NotBlank(message = "Target country is required")
    private String targetCountry;
}
