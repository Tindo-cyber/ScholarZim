package com.scholarzim.service.impl;

import com.scholarzim.dto.ApplicationSubmitRequest;
import com.scholarzim.dto.StoredFileResource;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.exception.DuplicateApplicationException;
import com.scholarzim.exception.InvalidStatusException;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicationService;
import com.scholarzim.service.ApplicantProfileService;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.service.NotificationService;
import com.scholarzim.util.ApplicationStatus;
import com.scholarzim.util.AuditAction;
import com.scholarzim.util.NotificationType;
import lombok.extern.slf4j.Slf4j;
import org.springframework.lang.NonNull;
import org.springframework.core.io.Resource;
import org.springframework.core.io.UrlResource;
import org.springframework.security.access.AccessDeniedException;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.web.multipart.MultipartFile;

import java.io.IOException;
import java.net.MalformedURLException;
import java.nio.file.Files;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.util.List;
import java.util.Objects;
import java.util.Set;


@Slf4j
@Service
public class ApplicationServiceImpl implements ApplicationService {

    private static final Set<String> PROVIDER_STATUSES = Set.of(
            ApplicationStatus.APPROVED,
            ApplicationStatus.REJECTED,
            ApplicationStatus.UNDER_REVIEW,
            ApplicationStatus.DOCUMENTS_REQUESTED,
            ApplicationStatus.WAITLISTED);

    private final ApplicationRepository applicationRepository;
    private final UserRepository userRepository;
    private final OpportunityRepository opportunityRepository;
    private final NotificationService notificationService;
    private final AuditService auditService;
    private final FileStorageService fileStorageService;
    private final ApplicantProfileService applicantProfileService;

    public ApplicationServiceImpl(
            ApplicationRepository applicationRepository,
            UserRepository userRepository,
            OpportunityRepository opportunityRepository,
            NotificationService notificationService,
            AuditService auditService,
            FileStorageService fileStorageService,
            ApplicantProfileService applicantProfileService) {

        this.applicationRepository = applicationRepository;
        this.userRepository = userRepository;
        this.opportunityRepository = opportunityRepository;
        this.notificationService = notificationService;
        this.auditService = auditService;
        this.fileStorageService = fileStorageService;
        this.applicantProfileService = applicantProfileService;
    }

    @Override
    public List<Application> getApplicationsByUser(String email) {
        return userRepository.findByEmail(email)
                .map(applicationRepository::findByUser)
                .orElse(List.of());
    }

    @Override
    @Transactional
    public void apply(@NonNull Long opportunityId, String email) {
        ensureResultsCertificate(email);
        Opportunity opportunity = validateOpportunityForApply(opportunityId);
        User user = findUserByEmail(email);
        ensureNotDuplicate(user, opportunity);
        saveNewApplication(user, opportunity, null, null, null);
    }

    @Override
    @Transactional
    public Long submitApplication(ApplicationSubmitRequest request, MultipartFile document, String email) {

        ensureResultsCertificate(email);
        Long opportunityId = Objects.requireNonNull(
                request.getOpportunityId(), "Opportunity is required.");
        Opportunity opportunity = validateOpportunityForApply(opportunityId);
        User user = findUserByEmail(email);
        ensureNotDuplicate(user, opportunity);

        String storedName = null;
        String originalName = null;
        try {
            if (document != null && !document.isEmpty()) {
                storedName = fileStorageService.store(document, "app-" + user.getUserId());
                originalName = document.getOriginalFilename();
            }
        } catch (Exception ex) {
            throw new IllegalArgumentException(ex.getMessage());
        }

        Application saved = saveNewApplication(user, opportunity, request.getPersonalStatement(), storedName, originalName);
        return saved.getApplicationId();
    }

    @Override
    public Application getApplicationForUser(@NonNull Long applicationId, String email) {

        Application app = applicationRepository.findById(applicationId)
                .orElseThrow(() -> new ResourceNotFoundException("Application not found."));

        User owner = app.getUser();
        if (owner == null || !owner.getEmail().equalsIgnoreCase(email)) {
            throw new AccessDeniedException("Not your application.");
        }
        return app;
    }

    @Override
    public List<Application> getApplicationsForProvider(String providerEmail) {

        User provider = findUserByEmail(providerEmail);
        List<Opportunity> opportunities = opportunityRepository.findByProvider(provider);
        if (opportunities.isEmpty()) {
            return List.of();
        }
        return applicationRepository.findByOpportunityIn(opportunities);
    }

    @Override
    @Transactional(readOnly = true)
    public StoredFileResource loadApplicationDocument(@NonNull Long applicationId, String requesterEmail) {

        Application application = applicationRepository.findById(applicationId)
                .orElseThrow(() -> new ResourceNotFoundException("Application not found."));

        if (application.getDocumentPath() == null) {
            throw new ResourceNotFoundException("No document attached to this application.");
        }

        User requester = findUserByEmail(requesterEmail);
        if (!canAccessApplicationDocument(application, requester)) {
            throw new AccessDeniedException("You are not allowed to download this document.");
        }

        try {
            var path = fileStorageService.resolve(application.getDocumentPath());
            if (!Files.exists(path)) {
                throw new ResourceNotFoundException("Document file not found.");
            }
            String contentType;
            try {
                contentType = Files.probeContentType(path);
            } catch (IOException ex) {
                contentType = null;
            }
            if (contentType == null) {
                contentType = "application/octet-stream";
            }
            Resource resource = new UrlResource(path.toUri());
            String displayName = application.getDocumentFilename() != null
                    ? application.getDocumentFilename()
                    : application.getDocumentPath();
            return new StoredFileResource(resource, contentType, displayName);
        } catch (MalformedURLException ex) {
            throw new IllegalStateException("Invalid stored document path.", ex);
        }
    }

    private boolean canAccessApplicationDocument(Application application, User requester) {

        if (application.getUser() != null
                && application.getUser().getUserId().equals(requester.getUserId())) {
            return true;
        }

        if (requester.getRole() != null && "ADMIN".equalsIgnoreCase(requester.getRole().getRoleName())) {
            return true;
        }

        Opportunity opportunity = application.getOpportunity();
        return opportunity != null
                && opportunity.getProvider() != null
                && opportunity.getProvider().getUserId().equals(requester.getUserId());
    }

    @Override
    @Transactional
    @PreAuthorize("hasRole('PROVIDER')")
    public void updateStatus(@NonNull Long applicationId, String status, String providerEmail) {
        updateStatus(applicationId, status, null, providerEmail);
    }

    @Override
    @Transactional
    @PreAuthorize("hasRole('PROVIDER')")
    public void updateStatus(@NonNull Long applicationId, String status, String rejectionReason, String providerEmail) {

        if (!PROVIDER_STATUSES.contains(status)) {
            throw new InvalidStatusException("Invalid application status: " + status);
        }

        Application application = applicationRepository.findById(applicationId)
                .orElseThrow(() -> new ResourceNotFoundException("Application not found."));

        User provider = findUserByEmail(providerEmail);
        Opportunity opportunity = application.getOpportunity();

        if (opportunity == null || opportunity.getProvider() == null
                || !opportunity.getProvider().getUserId().equals(provider.getUserId())) {
            throw new AccessDeniedException("You are not allowed to modify this application.");
        }

        application.setApplicationStatus(status);
        if (ApplicationStatus.REJECTED.equals(status)) {
            application.setRejectionReason(rejectionReason);
        }
        applicationRepository.save(application);

        auditService.log(providerEmail, AuditAction.STATUS_UPDATE, "APPLICATION", applicationId,
                "Status changed to " + status + " for \"" + opportunity.getTitle() + "\"");

        notifyApplicantOfDecision(application, status);
        log.info("Application {} -> {} by {}", applicationId, status, providerEmail);
    }

    private Opportunity validateOpportunityForApply(@NonNull Long opportunityId) {

        Opportunity opportunity = opportunityRepository.findById(opportunityId)
                .orElseThrow(() -> new ResourceNotFoundException("Opportunity not found."));

        if (!"ACTIVE".equalsIgnoreCase(opportunity.getStatus())) {
            throw new IllegalArgumentException("This scholarship is no longer accepting applications.");
        }

        if (opportunity.getDeadline() != null && opportunity.getDeadline().isBefore(LocalDate.now())) {
            throw new IllegalArgumentException("The deadline for this scholarship has passed.");
        }
        return opportunity;
    }

    private void ensureNotDuplicate(User user, Opportunity opportunity) {
        if (applicationRepository.existsByUserAndOpportunity(user, opportunity)) {
            throw new DuplicateApplicationException("You have already applied to this opportunity.");
        }
    }

    private Application saveNewApplication(User user, Opportunity opportunity, String statement,
                                    String storedDoc, String originalDoc) {

        Application application = new Application();
        application.setUser(user);
        application.setOpportunity(opportunity);
        application.setApplicationStatus(ApplicationStatus.SUBMITTED);
        application.setSubmittedAt(LocalDateTime.now());
        application.setPersonalStatement(statement);
        if (storedDoc != null) {
            application.setDocumentPath(storedDoc);
            application.setDocumentFilename(originalDoc);
        }

        Application saved = applicationRepository.save(application);

        auditService.log(user.getEmail(), AuditAction.APPLY, "APPLICATION", saved.getApplicationId(),
                "Applied to \"" + opportunity.getTitle() + "\"");
        log.info("Application created: user={} opportunity={}", user.getEmail(), opportunity.getOpportunityId());
        return saved;
    }

    private User findUserByEmail(String email) {
        return userRepository.findByEmail(email)
                .orElseThrow(() -> new ResourceNotFoundException("User account not found."));
    }

    private void ensureResultsCertificate(String email) {
        if (!applicantProfileService.hasResultsCertificate(email)) {
            throw new IllegalStateException(
                    "Upload your results certificate on your academic profile before applying.");
        }
    }

    private void notifyApplicantOfDecision(Application application, String status) {

        User applicant = application.getUser();
        Opportunity opportunity = application.getOpportunity();
        if (applicant == null || opportunity == null) {
            return;
        }

        String title = opportunity.getTitle();
        Long opportunityId = opportunity.getOpportunityId();

        if (ApplicationStatus.APPROVED.equals(status)) {
            notificationService.notifyUser(applicant, NotificationType.APPLICATION_APPROVED,
                    "Your application for \"" + title + "\" was approved.",
                    "/my-applications", opportunityId);
        } else if (ApplicationStatus.REJECTED.equals(status)) {
            notificationService.notifyUser(applicant, NotificationType.APPLICATION_REJECTED,
                    "Your application for \"" + title + "\" was not successful this round.",
                    "/my-applications", opportunityId);
        } else if (ApplicationStatus.DOCUMENTS_REQUESTED.equals(status)) {
            notificationService.notifyUser(applicant, NotificationType.NEW_OPPORTUNITY,
                    "Additional documents requested for \"" + title + "\".",
                    "/my-applications", opportunityId);
        }
    }
}
