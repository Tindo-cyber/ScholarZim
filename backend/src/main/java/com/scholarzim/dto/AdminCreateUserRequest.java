package com.scholarzim.dto;

import jakarta.validation.constraints.Email;
import jakarta.validation.constraints.NotBlank;
import lombok.Getter;
import lombok.Setter;


/**
 * Admin-initiated account creation. Unlike {@link RegisterRequest} / {@link ProviderRegisterRequest},
 * the password fields are conditionally required: ROLE_ADMIN accounts are created without a password
 * (the new admin sets their own via the confirmation link) while ROLE_PROVIDER / ROLE_APPLICANT
 * accounts get one assigned here — see AdminUserServiceImpl.createUser for the validation.
 */
@Getter
@Setter
public class AdminCreateUserRequest {

    @NotBlank(message = "Full name is required")
    private String fullName;

    @NotBlank(message = "Email is required")
    @Email(message = "Enter a valid email address")
    private String email;

    private String phone;

    @NotBlank(message = "Role is required")
    private String role;

    private String password;

    private String confirmPassword;

    private String organisationType;

    private String registrationNumber;
}
