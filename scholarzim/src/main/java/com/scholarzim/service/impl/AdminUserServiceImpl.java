package com.scholarzim.service.impl;

import com.scholarzim.dto.AdminUserViewDTO;
import com.scholarzim.dto.PageResult;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.exception.AdminOperationException;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.*;
import com.scholarzim.service.AdminUserService;
import com.scholarzim.service.AuditService;
import com.scholarzim.util.AuditAction;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Sort;
import org.springframework.lang.NonNull;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.util.Comparator;
import java.util.List;
import java.util.Objects;

@Service
public class AdminUserServiceImpl implements AdminUserService {

    private final UserRepository userRepository;
    private final ApplicantProfileRepository profileRepository;
    private final ApplicationRepository applicationRepository;
    private final OpportunityRepository opportunityRepository;
    private final NotificationRepository notificationRepository;
    private final AuditService auditService;

    public AdminUserServiceImpl(
            UserRepository userRepository,
            ApplicantProfileRepository profileRepository,
            ApplicationRepository applicationRepository,
            OpportunityRepository opportunityRepository,
            NotificationRepository notificationRepository,
            AuditService auditService) {

        this.userRepository = userRepository;
        this.profileRepository = profileRepository;
        this.applicationRepository = applicationRepository;
        this.opportunityRepository = opportunityRepository;
        this.notificationRepository = notificationRepository;
        this.auditService = auditService;
    }

    @Override
    public PageResult<AdminUserViewDTO> listApplicants(int page, int size) {

        Page<User> users = userRepository.findByRoleRoleName("ROLE_APPLICANT",
                PageRequest.of(Math.max(page, 0), Math.max(size, 1),
                        Sort.by("fullName").ascending()));

        List<AdminUserViewDTO> content = users.getContent().stream()
                .map(this::toApplicantView)
                .toList();

        return new PageResult<>(content, users.getNumber(), users.getSize(), users.getTotalElements());
    }

    @Override
    public PageResult<AdminUserViewDTO> listProviders(int page, int size) {

        Page<User> users = userRepository.findByRoleRoleName("ROLE_PROVIDER",
                PageRequest.of(Math.max(page, 0), Math.max(size, 1),
                        Sort.by("fullName").ascending()));

        List<AdminUserViewDTO> content = users.getContent().stream()
                .map(this::toProviderView)
                .toList();

        return new PageResult<>(content, users.getNumber(), users.getSize(), users.getTotalElements());
    }

    @Override
    public List<AdminUserViewDTO> listPendingProviders() {

        return userRepository.findByRoleRoleNameAndAccountStatus("ROLE_PROVIDER", "PENDING_APPROVAL")
                .stream()
                .sorted(Comparator.comparing(User::getFullName, Comparator.nullsLast(String.CASE_INSENSITIVE_ORDER)))
                .map(this::toProviderView)
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

        user.setAccountStatus("ACTIVE");
        userRepository.save(user);

        auditService.log(adminEmail, AuditAction.UPDATE_USER, "USER", userId,
                "Approved provider: " + user.getFullName());
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

    private AdminUserViewDTO toApplicantView(User user) {

        AdminUserViewDTO dto = baseView(user, "Applicant");
        dto.setApplicationCount(applicationRepository.findByUser(user).size());

        profileRepository.findByUser(user).ifPresent(profile -> {
            String institution = profile.getInstitutionName();
            String field = profile.getFieldOfStudy();
            if (institution != null && field != null) {
                dto.setDetail(institution + " · " + field);
            } else if (institution != null) {
                dto.setDetail(institution);
            } else if (profile.getProvince() != null) {
                dto.setDetail(profile.getProvince() + ", " + profile.getCountry());
            }
        });

        if (dto.getDetail() == null) {
            dto.setDetail("Profile incomplete");
        }

        return dto;
    }

    private AdminUserViewDTO toProviderView(User user) {

        AdminUserViewDTO dto = baseView(user, "Provider");
        List<Opportunity> opps = opportunityRepository.findByProvider(user);
        dto.setOpportunityCount(opps.size());

        long appsReceived = opps.isEmpty() ? 0
                : applicationRepository.findByOpportunityIn(opps).size();
        dto.setApplicationCount(appsReceived);
        dto.setDetail(opps.size() + " opportunit"
                + (opps.size() == 1 ? "y" : "ies")
                + " · " + appsReceived + " application"
                + (appsReceived == 1 ? "" : "s"));

        return dto;
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
