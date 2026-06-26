package com.scholarzim.service;

import com.scholarzim.dto.ApplicationSubmitRequest;
import org.springframework.lang.NonNull;
import org.springframework.web.multipart.MultipartFile;

import java.util.List;

public interface ApplicationService {

    List<com.scholarzim.entity.Application> getApplicationsByUser(String email);

    void apply(@NonNull Long opportunityId, String email);

    void submitApplication(ApplicationSubmitRequest request, MultipartFile document, String email);

    com.scholarzim.entity.Application getApplicationForUser(@NonNull Long applicationId, String email);

    List<com.scholarzim.entity.Application> getApplicationsForProvider(String providerEmail);

    void updateStatus(@NonNull Long applicationId, String status, String providerEmail);

    void updateStatus(@NonNull Long applicationId, String status, String rejectionReason, String providerEmail);
}
