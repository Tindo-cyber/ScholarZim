package com.scholarzim.service.impl;

import com.scholarzim.dto.AdminUserViewDTO;
import com.scholarzim.dto.PageResult;
import com.scholarzim.dto.StoredFileResource;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.ProviderProfile;
import com.scholarzim.entity.User;
import com.scholarzim.exception.AdminOperationException;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.*;
import com.scholarzim.service.AdminUserService;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.service.NotificationService;
import com.scholarzim.util.AuditAction;
import com.scholarzim.util.NotificationType;
import com.scholarzim.util.ProviderOrgType;
import lombok.extern.slf4j.Slf4j;
import org.springframework.core.io.Resource;
import org.springframework.core.io.UrlResource;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Sort;
import org.springframework.lang.NonNull;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.io.IOException;
import java.net.MalformedURLException;
import java.nio.file.Files;
import java.time.LocalDateTime;
import java.util.Comparator;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Objects;
import java.util.stream.Collectors;


@Slf4j
@Service
@PreAuthorize("hasRole('ADMIN')")
public class AdminUserServiceImpl implements AdminUserService {

    private final UserRepository userRepository;
    private final ApplicantProfileRepository profileRepository;
    private final ProviderProfileRepository providerProfileRepository;
    private final ApplicationRepository applicationRepository;
    private final OpportunityRepository opportunityRepository;
    private final NotificationRepository notificationRepository;
    private final AuditService auditService;
    private final FileStorageService fileStorageService;
    private final NotificationService notificationService;
    private final EmailService emailService;

    public AdminUserServiceImpl(
            UserRepository userRepository,
            ApplicantProfileRepository profileRepository,
            ProviderProfileRepository providerProfileRepository,
            ApplicationRepository applicationRepository,
            OpportunityRepository opportunityRepository,
            NotificationRepository notificationRepository,
            AuditService auditService,
            FileStorageService fileStorageService,
            NotificationService notificationService,
            EmailService emailService) {

        this.userRepository = userRepository;
        this.profileRepository = profileRepository;
        this.providerProfileRepository = providerProfileRepository;
        this.applicationRepository = applicationRepository;
        this.opportunityRepository = opportunityRepository;
        this.notificationRepository = notificationRepository;
        this.auditService = auditService;
        this.fileStorageService = fileStorageService;
        this.notificationService = notificationService;
        this.emailService = emailService;
    }

    @Override
    public PageResult<AdminUserViewDTO> listApplicants(int page, int size) {

        Page<User> users = userRepository.findByRoleRoleName("ROLE_APPLICANT",
                PageRequest.of(Math.max(page, 0), Math.max(size, 1),
                        Sort.by("fullName").ascending()));

        List<Long> userIds = users.getContent().stream().map(User::getUserId).toList();
        Map<Long, Long> applicationCounts = toCountMap(applicationRepository.countGroupedByUserIds(userIds));
        Map<Long, ApplicantProfile> profiles = profileRepository.findByUserUserIdIn(userIds).stream()
                .collect(Collectors.toMap(p -> p.getUser().getUserId(), p -> p, (a, b) -> a));

        List<AdminUserViewDTO> content = users.getContent().stream()
                .map(user -> toApplicantView(user, applicationCounts, profiles))
                .toList();

        return new PageResult<>(content, users.getNumber(), users.getSize(), users.getTotalElements());
    }

    @Override
    public PageResult<AdminUserViewDTO> listProviders(int page, int size) {

        Page<User> users = userRepository.findByRoleRoleName("ROLE_PROVIDER",
                PageRequest.of(Math.max(page, 0), Math.max(size, 1),
                        Sort.by("fullName").ascending()));

        List<Long> userIds = users.getContent().stream().map(User::getUserId).toList();
        Map<Long, Long> opportunityCounts = toCountMap(opportunityRepository.countGroupedByProviderIds(userIds));
        Map<Long, Long> applicationCounts = toCountMap(
                applicationRepository.countApplicationsGroupedByProviderIds(userIds));
        Map<Long, ProviderProfile> profiles = providerProfileRepository.findByUserUserIdIn(userIds).stream()
                .collect(Collectors.toMap(p -> p.getUser().getUserId(), p -> p, (a, b) -> a));

        List<AdminUserViewDTO> content = users.getContent().stream()
                .map(user -> toProviderView(user, opportunityCounts, applicationCounts, profiles))
                .toList();

        return new PageResult<>(content, users.getNumber(), users.getSize(), users.getTotalElements());
    }

    @Override
    public List<AdminUserViewDTO> listPendingProviders() {

        List<User> pending = userRepository
                .findByRoleRoleNameAndAccountStatus("ROLE_PROVIDER", "PENDING_APPROVAL")
                .stream()
                .sorted(Comparator.comparing(User::getFullName, Comparator.nullsLast(String.CASE_INSENSITIVE_ORDER)))
                .toList();

        List<Long> userIds = pending.stream().map(User::getUserId).toList();
        Map<Long, ProviderProfile> profiles = userIds.isEmpty()
                ? Map.of()
                : providerProfileRepository.findByUserUserIdIn(userIds).stream()
                        .collect(Collectors.toMap(p -> p.getUser().getUserId(), p -> p, (a, b) -> a));

        return pending.stream()
                .map(user -> toProviderView(user, Map.of(), Map.of(), profiles))
                .toList();
    }

    @Override
    @Transactional
    public void deleteApplicant(@NonNull Long userId, String adminEmail) {

        User user = loadDeletableUser(userId, adminEmail, "ROLE_APPLICANT");

        applicationRepository.deleteByUser(user);
        profileRepository.findByUser(user).ifPresent(profileRepository::delete);
        notificationRepository.deleteByUser(user);
        userRepository.delete(Objects.requireNonNull(user));

        auditService.log(adminEmail, AuditAction.DELETE_USER, "USER", userId,
                "Deleted applicant: " + user.getFullName() + " (" + user.getEmail() + ")");
    }

    @Override
    @Transactional
    public void deleteProvider(@NonNull Long userId, String adminEmail) {

        User user = loadDeletableUser(userId, adminEmail, "ROLE_PROVIDER");

        providerProfileRepository.findByUser(user).ifPresent(profile -> {
            fileStorageService.deleteIfExists(profile.getCertificatePath());
            providerProfileRepository.delete(profile);
        });

        List<Opportunity> opportunities = opportunityRepository.findByProvider(user);
        if (!opportunities.isEmpty()) {
            List<Application> apps = applicationRepository.findByOpportunityIn(opportunities);
            applicationRepository.deleteAll(Objects.requireNonNull(apps));
            opportunityRepository.deleteAll(opportunities);
        }

        notificationRepository.deleteByUser(user);
        userRepository.delete(Objects.requireNonNull(user));

        auditService.log(adminEmail, AuditAction.DELETE_USER, "USER", userId,
                "Deleted provider: " + user.getFullName() + " (" + user.getEmail() + ")");
    }

    @Override
    @Transactional
    public void suspendUser(@NonNull Long userId, String adminEmail) {

        User user = userRepository.findById(userId)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        if (user.getEmail().equalsIgnoreCase(adminEmail)) {
            throw new AdminOperationException("You cannot suspend your own account.");
        }

        user.setAccountStatus("SUSPENDED");
        userRepository.save(user);

        auditService.log(adminEmail, AuditAction.UPDATE_USER, "USER", userId,
                "Suspended user: " + user.getEmail());
    }

    @Override
    @Transactional
    public void reactivateUser(@NonNull Long userId, String adminEmail) {

        User user = userRepository.findById(userId)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        user.setAccountStatus("ACTIVE");
        userRepository.save(user);

        auditService.log(adminEmail, AuditAction.UPDATE_USER, "USER", userId,
                "Reactivated user: " + user.getEmail());
    }

    @Override
    @Transactional
    public void approveProvider(@NonNull Long userId, String adminEmail) {

        User user = userRepository.findById(userId)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        if (user.getRole() == null || !"ROLE_PROVIDER".equals(user.getRole().getRoleName())) {
            throw new AdminOperationException("Only provider accounts can be approved here.");
        }

        ProviderProfile profile = providerProfileRepository.findByUser(user)
                .orElseThrow(() -> new AdminOperationException(
                        "Cannot approve provider without a verification profile."));

        if (profile.getCertificatePath() == null || profile.getCertificatePath().isBlank()) {
            throw new AdminOperationException("Cannot approve provider without a registration certificate.");
        }

        if (!Files.exists(fileStorageService.resolve(profile.getCertificatePath()))) {
            throw new AdminOperationException("Registration certificate file is missing on disk.");
        }

        user.setAccountStatus("ACTIVE");
        userRepository.save(user);

        profile.setReviewedAt(LocalDateTime.now());
        profile.setReviewedBy(adminEmail);
        profile.setRejectionReason(null);
        providerProfileRepository.save(profile);

        auditService.log(adminEmail, AuditAction.APPROVE_PROVIDER, "USER", userId,
                "Approved provider: " + user.getFullName());

        log.info("Provider approved: id={} by={}", userId, adminEmail);

        notificationService.notifyUser(
                user,
                NotificationType.PROVIDER_APPROVED,
                "Your provider account has been approved. You can now sign in and publish scholarships.",
                "/login?role=provider",
                userId);

        emailService.sendStatusUpdateEmail(
                user.getEmail(),
                "ScholarZim provider account approved",
                """
                        Hi %s,

                        Your provider application for %s has been approved.
                        Sign in at ScholarZim to publish scholarships and review applications.

                        — The ScholarZim Team
                        """.formatted(user.getFullName(), user.getFullName()));
    }

    @Override
    @Transactional
    public void rejectProvider(@NonNull Long userId, String adminEmail, String reason) {

        if (reason == null || reason.isBlank()) {
            throw new AdminOperationException("A rejection reason is required.");
        }

        User user = userRepository.findById(userId)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        if (user.getRole() == null || !"ROLE_PROVIDER".equals(user.getRole().getRoleName())) {
            throw new AdminOperationException("Only provider accounts can be rejected here.");
        }

        if (!"PENDING_APPROVAL".equals(user.getAccountStatus())) {
            throw new AdminOperationException("Only pending provider applications can be rejected.");
        }

        user.setAccountStatus("REJECTED");
        userRepository.save(user);

        providerProfileRepository.findByUser(user).ifPresent(profile -> {
            profile.setReviewedAt(LocalDateTime.now());
            profile.setReviewedBy(adminEmail);
            profile.setRejectionReason(reason.trim());
            providerProfileRepository.save(profile);
        });

        auditService.log(adminEmail, AuditAction.REJECT_PROVIDER, "USER", userId,
                "Rejected provider: " + user.getFullName() + " — " + reason.trim());

        emailService.sendStatusUpdateEmail(
                user.getEmail(),
                "ScholarZim provider application update",
                """
                        Hi %s,

                        Your provider application for %s was not approved at this time.

                        Reason: %s

                        If you believe this is an error, contact support with updated documentation.

                        — The ScholarZim Team
                        """.formatted(user.getFullName(), user.getFullName(), reason.trim()));
    }

    @Override
    @Transactional(readOnly = true)
    public StoredFileResource loadProviderCertificate(@NonNull Long userId, String adminEmail) {

        User user = userRepository.findById(userId)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        if (user.getRole() == null || !"ROLE_PROVIDER".equals(user.getRole().getRoleName())) {
            throw new AdminOperationException("Certificate download is only available for provider accounts.");
        }

        ProviderProfile profile = providerProfileRepository.findByUser(user)
                .orElseThrow(() -> new ResourceNotFoundException("Provider verification profile not found."));

        if (profile.getCertificatePath() == null) {
            throw new ResourceNotFoundException("No certificate uploaded for this provider.");
        }

        auditService.log(adminEmail, AuditAction.VIEW_PROVIDER_CERTIFICATE, "USER", userId,
                "Viewed registration certificate for: " + user.getFullName());

        try {
            var path = fileStorageService.resolve(profile.getCertificatePath());
            if (!Files.exists(path)) {
                throw new ResourceNotFoundException("Certificate file not found.");
            }
            Resource resource = new UrlResource(path.toUri());
            String displayName = profile.getCertificateFilename() != null
                    ? profile.getCertificateFilename()
                    : profile.getCertificatePath();
            return new StoredFileResource(resource, "application/pdf", displayName);
        } catch (MalformedURLException ex) {
            throw new IllegalStateException("Invalid stored certificate path.", ex);
        }
    }

    private User loadDeletableUser(@NonNull Long userId, String adminEmail, String requiredRole) {

        User user = userRepository.findById(userId)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));

        if (user.getEmail().equalsIgnoreCase(adminEmail)) {
            throw new AdminOperationException("You cannot delete your own account.");
        }

        if (user.getRole() == null || !requiredRole.equals(user.getRole().getRoleName())) {
            throw new AdminOperationException("This account cannot be deleted from here.");
        }

        return user;
    }

    private AdminUserViewDTO toApplicantView(
            User user,
            Map<Long, Long> applicationCounts,
            Map<Long, ApplicantProfile> profiles) {

        AdminUserViewDTO dto = baseView(user, "Applicant");
        dto.setApplicationCount(applicationCounts.getOrDefault(user.getUserId(), 0L));

        ApplicantProfile profile = profiles.get(user.getUserId());
        if (profile != null) {
            String institution = profile.getInstitutionName();
            String field = profile.getFieldOfStudy();
            if (institution != null && field != null) {
                dto.setDetail(institution + " · " + field);
            } else if (institution != null) {
                dto.setDetail(institution);
            } else if (profile.getProvince() != null) {
                dto.setDetail(profile.getProvince() + ", " + profile.getCountry());
            }
        }

        if (dto.getDetail() == null) {
            dto.setDetail("Profile incomplete");
        }

        return dto;
    }

    private AdminUserViewDTO toProviderView(
            User user,
            Map<Long, Long> opportunityCounts,
            Map<Long, Long> applicationCounts,
            Map<Long, ProviderProfile> profiles) {

        AdminUserViewDTO dto = baseView(user, "Provider");
        long oppCount = opportunityCounts.getOrDefault(user.getUserId(), 0L);
        long appsReceived = applicationCounts.getOrDefault(user.getUserId(), 0L);
        dto.setOpportunityCount(oppCount);
        dto.setApplicationCount(appsReceived);

        ProviderProfile profile = profiles.get(user.getUserId());
        if (profile != null) {
            dto.setOrganisationType(ProviderOrgType.label(profile.getOrganisationType()));
            dto.setRegistrationNumber(profile.getRegistrationNumber());
            dto.setSubmittedAt(profile.getSubmittedAt());
            dto.setHasCertificate(profile.getCertificatePath() != null && !profile.getCertificatePath().isBlank());
            if ("PENDING_APPROVAL".equals(user.getAccountStatus())) {
                dto.setDetail(ProviderOrgType.label(profile.getOrganisationType())
                        + " · Reg# " + profile.getRegistrationNumber());
            }
        }

        if (dto.getDetail() == null) {
            dto.setDetail(oppCount + " opportunit"
                    + (oppCount == 1 ? "y" : "ies")
                    + " · " + appsReceived + " application"
                    + (appsReceived == 1 ? "" : "s"));
        }

        return dto;
    }

    private Map<Long, Long> toCountMap(List<Object[]> rows) {
        Map<Long, Long> counts = new HashMap<>();
        for (Object[] row : rows) {
            counts.put(((Number) row[0]).longValue(), ((Number) row[1]).longValue());
        }
        return counts;
    }

    private AdminUserViewDTO toApplicantView(User user) {
        return toApplicantView(user, Map.of(), Map.of());
    }

    private AdminUserViewDTO toProviderView(User user) {
        return toProviderView(user, Map.of(), Map.of(), Map.of());
    }

    private AdminUserViewDTO baseView(User user, String roleLabel) {

        AdminUserViewDTO dto = new AdminUserViewDTO();
        dto.setUserId(user.getUserId());
        dto.setFullName(user.getFullName());
        dto.setEmail(user.getEmail());
        dto.setPhone(user.getPhone());
        dto.setAccountStatus(user.getAccountStatus() != null ? user.getAccountStatus() : "ACTIVE");
        dto.setRoleName(roleLabel);
        return dto;
    }
}
