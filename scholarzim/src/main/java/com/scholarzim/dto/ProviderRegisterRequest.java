package com.scholarzim.dto;

import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.Pattern;
import jakarta.validation.constraints.Size;
import lombok.Getter;
import lombok.Setter;


@Getter
@Setter
public class ProviderRegisterRequest extends RegisterRequest {

    @NotBlank(message = "Organisation type is required")
    private String organisationType;

    @NotBlank(message = "Registration number is required")
    @Size(max = 100, message = "Registration number must be at most 100 characters")
    @Pattern(regexp = "^[A-Za-z0-9/\\-]{2,100}$",
            message = "Registration number may only contain letters, numbers, / and -")
    private String registrationNumber;
}
