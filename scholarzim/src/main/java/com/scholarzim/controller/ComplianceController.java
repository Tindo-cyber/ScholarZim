package com.scholarzim.controller;

import com.scholarzim.entity.Application;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.UserRepository;
import org.springframework.lang.NonNull;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.web.bind.annotation.GetMapping;

import java.nio.charset.StandardCharsets;
import java.util.List;


@Controller
public class ComplianceController {

    private final UserRepository userRepository;
    private final ApplicationRepository applicationRepository;

    public ComplianceController(
            UserRepository userRepository,
            ApplicationRepository applicationRepository) {

        this.userRepository = userRepository;
        this.applicationRepository = applicationRepository;
    }

    @GetMapping("/account/export-data")
    public ResponseEntity<byte[]> exportMyData(@NonNull Authentication auth) {

        User user = userRepository.findByEmail(auth.getName())
                .orElseThrow(() -> new com.scholarzim.exception.ResourceNotFoundException("User not found"));
        List<Application> apps = applicationRepository.findByUser(user);

        StringBuilder sb = new StringBuilder();
        sb.append("ScholarZim Data Export\n");
        sb.append("Name: ").append(user.getFullName()).append("\n");
        sb.append("Email: ").append(user.getEmail()).append("\n");
        sb.append("Phone: ").append(user.getPhone()).append("\n\nApplications:\n");
        for (Application app : apps) {
            sb.append("- ").append(app.getOpportunity() != null ? app.getOpportunity().getTitle() : "Unknown")
                    .append(" [").append(app.getApplicationStatus()).append("]\n");
        }

        byte[] body = sb.toString().getBytes(StandardCharsets.UTF_8);
        return ResponseEntity.ok()
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=\"scholarzim-data.txt\"")
                .contentType(MediaType.parseMediaType("text/plain"))
                .body(body);
    }
}
