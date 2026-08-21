package com.scholarzim.controller;

import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.ApplicantProfileService;
import org.springframework.core.io.Resource;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.lang.NonNull;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;


@Controller
public class FileDownloadController {

    private final ApplicationService applicationService;
    private final ApplicantProfileService applicantProfileService;

    public FileDownloadController(
            ApplicationService applicationService,
            ApplicantProfileService applicantProfileService) {

        this.applicationService = applicationService;
        this.applicantProfileService = applicantProfileService;
    }

    @GetMapping("/applications/{applicationId}/document")
    public ResponseEntity<Resource> downloadApplicationDocument(
            @PathVariable Long applicationId,
            @NonNull Authentication authentication) {

        var file = applicationService.loadApplicationDocument(applicationId, authentication.getName());

        String filename = file.displayName() != null ? file.displayName() : "document";
        return ResponseEntity.ok()
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=\"" + filename + "\"")
                .contentType(MediaType.parseMediaType(file.contentType()))
                .body(file.resource());
    }

    @GetMapping("/applications/{applicationId}/results-certificate")
    public ResponseEntity<Resource> downloadResultsCertificate(
            @PathVariable Long applicationId,
            @NonNull Authentication authentication) {

        var file = applicantProfileService.loadResultsCertificateForApplication(
                applicationId, authentication.getName());

        String filename = file.displayName() != null ? file.displayName() : "results-certificate.pdf";
        return ResponseEntity.ok()
                .header(HttpHeaders.CONTENT_DISPOSITION, "inline; filename=\"" + filename + "\"")
                .contentType(MediaType.parseMediaType(file.contentType()))
                .body(file.resource());
    }
}
