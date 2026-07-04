package com.scholarzim.dto;

import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.Size;
import lombok.Getter;
import lombok.Setter;


@Getter
@Setter
public class ApplicationSubmitRequest {

    private Long opportunityId;

    @NotBlank(message = "Personal statement is required")
    @Size(min = 50, max = 5000, message = "Statement must be between 50 and 5000 characters")
    private String personalStatement;

    private String academicSummary;
}
