package com.scholarzim;

import com.scholarzim.dto.RegisterRequest;
import jakarta.validation.ConstraintViolation;
import jakarta.validation.Validation;
import jakarta.validation.Validator;
import org.junit.jupiter.api.Test;

import java.util.Set;

import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

class RegisterRequestValidationTest {

    private final Validator validator = Validation.buildDefaultValidatorFactory().getValidator();

    @Test
    void rejectsMismatchedPasswords() {
        RegisterRequest request = validRequest();
        request.setConfirmPassword("Different1");

        Set<ConstraintViolation<RegisterRequest>> violations = validator.validate(request);

        assertFalse(violations.isEmpty());
        assertTrue(violations.stream().anyMatch(v -> v.getMessage().contains("Passwords do not match")));
    }

    @Test
    void acceptsMatchingPasswords() {
        RegisterRequest request = validRequest();

        Set<ConstraintViolation<RegisterRequest>> violations = validator.validate(request);

        assertTrue(violations.isEmpty());
    }

    private static RegisterRequest validRequest() {
        RegisterRequest request = new RegisterRequest();
        request.setFullName("Test Student");
        request.setEmail("student@test.com");
        request.setPassword("Secret12");
        request.setConfirmPassword("Secret12");
        return request;
    }
}
