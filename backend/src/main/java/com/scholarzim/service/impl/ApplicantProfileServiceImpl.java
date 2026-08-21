package com.scholarzim.service.impl;

import com.scholarzim.dto.ApplicantProfileRequest;
import com.scholarzim.dto.StoredFileResource;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.User;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.util.ProfileCompletionSupport;
import org.springframework.core.io.Resource;
import org.springframework.core.io.UrlResource;
import org.springframework.lang.NonNull;
import org.springframework.security.access.AccessDeniedException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.web.multipart.MultipartFile;

import java.io.IOException;
import java.net.MalformedURLException;
import java.nio.file.Files;
import java.time.LocalDateTime;


@Service
public class ApplicantProfileServiceImpl implements ApplicantProfileService {

    private final ApplicantProfileRepository profileRepository;
    private final UserRepository userRepository;
    private final ApplicationRepository applicationRepository;
    private final FileStorageService fileStorageService;
    private final AuditService auditService;

    public ApplicantProfileServiceImpl(
            ApplicantProfileRepository profileRepository,
            UserRepository userRepository,
            ApplicationRepository applicationRepository,
            FileStorageService fileStorageService,
            AuditService auditService) {

        this.profileRepository = profileRepository;
        this.userRepository = userRepository;
        this.applicationRepository = applicationRepository;
        this.fileStorageService = fileStorageService;
        this.auditService = auditService;
    }

    @Override
    @Transactional
    public void saveProfile(
            ApplicantProfileRequest request,
            MultipartFile resultsCertificate,
            String email) {

        User user = userRepository.findByEmail(email)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        ApplicantProfile profile = profileRepository.findByUser(user)
                .orElseGet(ApplicantProfile::new);

        boolean needsCertificate = profile.getResultsCertificatePath() == null
                || profile.getResultsCertificatePath().isBlank();

        if (needsCertificate && (resultsCertificate == null || resultsCertificate.isEmpty())) {
            throw new IllegalArgumentException("Results certificate (PDF) is required.");
        }

        String previousPath = profile.getResultsCertificatePath();
        try {
            if (resultsCertificate != null && !resultsCertificate.isEmpty()) {
                String newPath = fileStorageService.storePdf(
                        resultsCertificate, "applicant-results-" + user.getUserId());
                fileStorageService.deleteIfExists(previousPath);
                profile.setResultsCertificatePath(newPath);
                profile.setResultsCertificateFilename(
                        resultsCertificate.getOriginalFilename() != null
                                ? resultsCertificate.getOriginalFilename()
                                : "results-certificate.pdf");
                profile.setResultsUploadedAt(LocalDateTime.now());
            }
        } catch (IllegalArgumentException ex) {
            throw ex;
        } catch (IOException ex) {
            throw new IllegalArgumentException("Could not save results certificate. Please try again.");
        }

        profile.setUser(user);
        profile.setEducationLevel(request.getEducationLevel());
        profile.setInstitutionName(request.getInstitutionName());
        profile.setFieldOfStudy(request.getFieldOfStudy());
        profile.setCountry(request.getCountry());
        profile.setProvince(request.getProvince());
        profile.setAcademicResults(request.getAcademicResults());
        profile.setBiography(request.getBiography());

        profileRepository.save(profile);
    }

    @Override
    public ApplicantProfile getProfileByEmail(String email) {
        return userRepository.findByEmail(email)
                .flatMap(profileRepository::findByUser)
                .orElse(null);
    }

    @Override
    public ApplicantProfile getProfileByUserId(@NonNull Long userId) {
        return userRepository.findById(userId)
                .flatMap(profileRepository::findByUser)
                .orElse(null);
    }

    @Override
    public ApplicantProfileRequest toRequest(ApplicantProfile profile) {
        ApplicantProfileRequest request = new ApplicantProfileRequest();
        if (profile == null) {
            return request;
        }
        request.setEducationLevel(profile.getEducationLevel());
        request.setInstitutionName(profile.getInstitutionName());
        request.setFieldOfStudy(profile.getFieldOfStudy());
        request.setCountry(profile.getCountry());
        request.setProvince(profile.getProvince());
        request.setAcademicResults(profile.getAcademicResults());
        request.setBiography(profile.getBiography());
        return request;
    }

    @Override
    public boolean hasProfile(String email) {
        ApplicantProfile profile = getProfileByEmail(email);
        return profile != null
                && profile.getEducationLevel() != null
                && !profile.getEducationLevel().isBlank();
    }

    @Override
    public boolean hasResultsCertificate(String email) {
        ApplicantProfile profile = getProfileByEmail(email);
        return profile != null
                && profile.getResultsCertificatePath() != null
                && !profile.getResultsCertificatePath().isBlank()
                && Files.exists(fileStorageService.resolve(profile.getResultsCertificatePath()));
    }

    @Override
    @Transactional(readOnly = true)
    public StoredFileResource loadResultsCertificateForApplication(
            @NonNull Long applicationId,
            String requesterEmail) {

        Application application = applicationRepository.findByIdWithDetails(applicationId)
                .orElseThrow(() -> new ResourceNotFoundException("Application not found."));

        if (application.getUser() == null) {
            throw new ResourceNotFoundException("Applicant not found for this application.");
        }

        ApplicantProfile profile = getProfileByUserId(application.getUser().getUserId());
        if (profile == null || profile.getResultsCertificatePath() == null) {
            throw new ResourceNotFoundException("No results certificate on file for this applicant.");
        }

        User requester = userRepository.findByEmailWithRole(requesterEmail)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        return loadResultsCertificateInternal(profile, requester, application.getUser().getUserId(), application);
    }

    private StoredFileResource loadResultsCertificateInternal(
            ApplicantProfile profile,
            User requester,
            Long subjectUserId,
            Application applicationContext) {

        if (!canAccessResultsCertificate(profile, requester, applicationContext)) {
            throw new AccessDeniedException("You are not allowed to download this results certificate.");
        }

        auditService.log(
                requester.getEmail(),
                com.scholarzim.util.AuditAction.VIEW_APPLICANT_RESULTS,
                "USER",
                subjectUserId,
                "Viewed results certificate for applicant userId=" + subjectUserId);

        try {
            var path = fileStorageService.resolve(profile.getResultsCertificatePath());
            if (!Files.exists(path)) {
                throw new ResourceNotFoundException("Results certificate file not found.");
            }
            Resource resource = new UrlResource(path.toUri());
            String displayName = profile.getResultsCertificateFilename() != null
                    ? profile.getResultsCertificateFilename()
                    : profile.getResultsCertificatePath();
            return new StoredFileResource(resource, "application/pdf", displayName);
        } catch (MalformedURLException ex) {
            throw new IllegalStateException("Invalid stored certificate path.", ex);
        }
    }

    @Override
    public ProfileCompletionSupport.Snapshot getProfileCompletion(String email) {
        ApplicantProfile profile = getProfileByEmail(email);
        return ProfileCompletionSupport.build(profile, this::storedFileExists);
    }

    @Override
    @Transactional
    public void uploadProfileDocument(String documentType, MultipartFile file, String email) {
        if (file == null || file.isEmpty()) {
            throw new IllegalArgumentException(
                    ProfileCompletionSupport.documentLabel(documentType) + " file is required.");
        }

        User user = userRepository.findByEmail(email)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        ApplicantProfile profile = profileRepository.findByUser(user)
                .orElseGet(() -> {
                    ApplicantProfile created = new ApplicantProfile();
                    created.setUser(user);
                    return created;
                });

        String normalizedType = ProfileCompletionSupport.normalizeDocumentType(documentType);
        String previousPath = switch (normalizedType) {
            case "CV" -> profile.getCvPath();
            case "TRANSCRIPT" -> profile.getResultsCertificatePath();
            case "PASSPORT" -> profile.getPassportPath();
            case "RECOMMENDATION_LETTER" -> profile.getRecommendationLetterPath();
            default -> throw new IllegalArgumentException("Unsupported document type.");
        };

        try {
            String storedPath = storeDocument(file, normalizedType, user.getUserId());
            fileStorageService.deleteIfExists(previousPath);
            applyUploadedDocument(profile, normalizedType, storedPath, file.getOriginalFilename());
            profileRepository.save(profile);
        } catch (IllegalArgumentException ex) {
            throw ex;
        } catch (IOException ex) {
            throw new IllegalArgumentException(
                    "Could not save " + ProfileCompletionSupport.documentLabel(documentType)
                            + ". Please try again.");
        }
    }

    private String storeDocument(MultipartFile file, String documentType, Long userId) throws IOException {
        String prefix = ProfileCompletionSupport.storagePrefix(documentType, userId);
        if ("TRANSCRIPT".equals(documentType)) {
            return fileStorageService.storePdf(file, prefix);
        }
        return fileStorageService.store(file, prefix);
    }

    private void applyUploadedDocument(
            ApplicantProfile profile,
            String documentType,
            String storedPath,
            String originalFilename) {

        String filename = originalFilename != null && !originalFilename.isBlank()
                ? originalFilename
                : ProfileCompletionSupport.documentLabel(documentType).toLowerCase() + ".pdf";
        LocalDateTime uploadedAt = LocalDateTime.now();

        switch (documentType) {
            case "CV" -> {
                profile.setCvPath(storedPath);
                profile.setCvFilename(filename);
                profile.setCvUploadedAt(uploadedAt);
            }
            case "TRANSCRIPT" -> {
                profile.setResultsCertificatePath(storedPath);
                profile.setResultsCertificateFilename(filename);
                profile.setResultsUploadedAt(uploadedAt);
            }
            case "PASSPORT" -> {
                profile.setPassportPath(storedPath);
                profile.setPassportFilename(filename);
                profile.setPassportUploadedAt(uploadedAt);
            }
            case "RECOMMENDATION_LETTER" -> {
                profile.setRecommendationLetterPath(storedPath);
                profile.setRecommendationLetterFilename(filename);
                profile.setRecommendationLetterUploadedAt(uploadedAt);
            }
            default -> throw new IllegalArgumentException("Unsupported document type.");
        }
    }

    private boolean storedFileExists(String path) {
        return path != null
                && !path.isBlank()
                && Files.exists(fileStorageService.resolve(path));
    }

    @Override
    @Transactional(readOnly = true)
    public StoredFileResource loadResultsCertificate(@NonNull Long userId, String requesterEmail) {

        ApplicantProfile profile = getProfileByUserId(userId);
        if (profile == null || profile.getResultsCertificatePath() == null) {
            throw new ResourceNotFoundException("No results certificate on file for this applicant.");
        }

        User requester = userRepository.findByEmail(requesterEmail)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        return loadResultsCertificateInternal(profile, requester, userId, null);
    }

    private boolean canAccessResultsCertificate(
            ApplicantProfile profile,
            User requester,
            Application applicationContext) {

        if (profile.getUser() != null
                && profile.getUser().getUserId().equals(requester.getUserId())) {
            return true;
        }

        if (requester.getRole() != null && "ROLE_ADMIN".equals(requester.getRole().getRoleName())) {
            return true;
        }

        if (requester.getRole() != null && "ROLE_PROVIDER".equals(requester.getRole().getRoleName())) {
            if (applicationContext != null
                    && applicationContext.getOpportunity() != null
                    && applicationContext.getOpportunity().getProvider() != null
                    && requester.getUserId().equals(
                            applicationContext.getOpportunity().getProvider().getUserId())) {
                return profile.getUser() != null
                        && applicationContext.getUser() != null
                        && profile.getUser().getUserId().equals(applicationContext.getUser().getUserId());
            }
            if (profile.getUser() != null) {
                return applicationRepository.findByUser(profile.getUser()).stream()
                        .anyMatch(app -> app.getOpportunity() != null
                                && app.getOpportunity().getProvider() != null
                                && requester.getUserId().equals(
                                        app.getOpportunity().getProvider().getUserId()));
            }
        }

        return false;
    }
}
