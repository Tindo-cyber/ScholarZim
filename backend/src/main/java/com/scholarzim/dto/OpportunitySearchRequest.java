package com.scholarzim.dto;

import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import lombok.Getter;
import lombok.Setter;
import org.springframework.format.annotation.DateTimeFormat;

import java.time.LocalDate;


@Getter
@Setter
public class OpportunitySearchRequest {

    private String educationLevel;
    private String country;
    private String fieldOfStudy;
    private String provider;
    private String keyword;
    private String fundingType;

    @DateTimeFormat(iso = DateTimeFormat.ISO.DATE)
    private LocalDate deadlineBefore;

    @Min(0)
    private int page = 0;

    @Min(1)
    @Max(50)
    private int size = 20;

    public boolean isEmpty() {
        return isBlank(educationLevel)
                && isBlank(country)
                && isBlank(fieldOfStudy)
                && isBlank(provider)
                && isBlank(keyword)
                && isBlank(fundingType)
                && deadlineBefore == null;
    }

    private boolean isBlank(String value) {
        return value == null || value.isBlank();
    }
}
