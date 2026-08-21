package com.scholarzim.service;

import com.scholarzim.dto.ApplicantProfileRequest;
import com.scholarzim.dto.StoredFileResource;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.util.ProfileCompletionSupport;
import org.springframework.lang.NonNull;
import org.springframework.web.multipart.MultipartFile;


public interface ApplicantProfileService {

    void saveProfile(
            ApplicantProfileRequest request,
            MultipartFile resultsCertificate,
            String email);

    ApplicantProfile getProfileByEmail(String email);

    ApplicantProfile getProfileByUserId(@NonNull Long userId);

    ApplicantProfileRequest toRequest(ApplicantProfile profile);

    boolean hasProfile(String email);

    boolean hasResultsCertificate(String email);

    StoredFileResource loadResultsCertificate(@NonNull Long userId, String requesterEmail);

    StoredFileResource loadResultsCertificateForApplication(@NonNull Long applicationId, String requesterEmail);

    ProfileCompletionSupport.Snapshot getProfileCompletion(String email);

    void uploadProfileDocument(String documentType, MultipartFile file, String email);
}
