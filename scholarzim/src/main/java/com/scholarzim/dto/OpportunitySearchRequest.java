package com.scholarzim.dto;

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
