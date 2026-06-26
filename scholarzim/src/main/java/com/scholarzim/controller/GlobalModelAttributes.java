package com.scholarzim.controller;

import com.scholarzim.util.FormOptions;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ModelAttribute;

import java.util.List;

@ControllerAdvice
public class GlobalModelAttributes {

    @ModelAttribute("educationLevels")
    public List<String> educationLevels() {
        return FormOptions.EDUCATION_LEVELS;
    }

    @ModelAttribute("fieldsOfStudy")
    public List<String> fieldsOfStudy() {
        return FormOptions.FIELDS_OF_STUDY;
    }

    @ModelAttribute("countries")
    public List<String> countries() {
        return FormOptions.COUNTRIES;
    }

    @ModelAttribute("fundingTypes")
    public List<String> fundingTypes() {
        return FormOptions.FUNDING_TYPES;
    }

    @ModelAttribute("provinces")
    public List<String> provinces() {
        return FormOptions.ZIMBABWE_PROVINCES;
    }

    @ModelAttribute("institutions")
    public List<String> institutions() {
        return FormOptions.INSTITUTIONS;
    }
}
