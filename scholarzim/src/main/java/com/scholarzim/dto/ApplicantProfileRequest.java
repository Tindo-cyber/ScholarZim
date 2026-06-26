package com.scholarzim.dto;

import jakarta.validation.constraints.NotBlank;
import lombok.Getter;
import lombok.Setter;

@Getter
@Setter
public class ApplicantProfileRequest {

    @NotBlank(message = "Education level is required")
    private String educationLevel;

    private String institutionName;

    @NotBlank(message = "Field of study is required")
    private String fieldOfStudy;

    @NotBlank(message = "Country is required")
    private String country;

    private String province;
    private String academicResults;
    private String biography;
}
