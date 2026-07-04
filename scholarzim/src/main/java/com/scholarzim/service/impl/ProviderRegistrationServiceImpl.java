package com.scholarzim.service.impl;

import com.scholarzim.dto.ProviderRegisterRequest;
import com.scholarzim.entity.ProviderProfile;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ProviderProfileRepository;
import com.scholarzim.repository.RoleRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.service.NotificationService;
import com.scholarzim.service.ProviderRegistrationService;
import com.scholarzim.service.RegistrationException;
import com.scholarzim.util.AuditAction;
import com.scholarzim.util.NotificationType;
import com.scholarzim.util.ProviderOrgType;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.web.multipart.MultipartFile;

import java.time.LocalDateTime;
import java.util.List;


@Service
public class ProviderRegistrationServiceImpl implements ProviderRegistrationService {

    private final UserRepository userRepository;
    private final RoleRepository roleRepository;
    private final ProviderProfileRepository providerProfileRepository;
    private final PasswordEncoder passwordEncoder;
    private final FileStorageService fileStorageService;
    private final AuditService auditService;
    private final NotificationService notificationService;
    private final EmailService emailService;

    public ProviderRegistrationServiceImpl(
            UserRepository userRepository,
            RoleRepository roleRepository,
            ProviderProfileRepository providerProfileRepository,
            PasswordEncoder passwordEncoder,
            FileStorageService fileStorageService,
            AuditService auditService,
            NotificationService notificationService,
            EmailService emailService) {

        this.userRepository = userRepository;
        this.roleRepository = roleRepository;
        this.providerProfileRepository = providerProfileRepository;
        this.passwordEncoder = passwordEncoder;
        this.fileStorageService = fileStorageService;
        this.auditService = auditService;
        this.notificationService = notificationService;
        this.emailService = emailService;
    }

    @Override
    @Transactional
    public void registerProvider(ProviderRegisterRequest request, MultipartFile certificate) {

        if (userRepository.existsByEmail(request.getEmail())) {
            throw new RegistrationException("Email already exists");
        }

        if (!request.getPassword().equals(request.getConfirmPassword())) {
            throw new RegistrationException("Passwords do not match");
        }

        if (!ProviderOrgType.isValid(request.getOrganisationType())) {
            throw new RegistrationException("Select a valid organisation type");
        }

        String storedPath = null;
        try {
            storedPath = fileStorageService.storePdf(certificate, "provider-verification");

            Role providerRole = roleRepository.findByRoleName("ROLE_PROVIDER")
                    .orElseThrow(() -> new RegistrationException("Provider role not found"));

            User user = new User();
            user.setFullName(request.getFullName());
            user.setEmail(request.getEmail());
            user.setPhone(request.getPhone());
            user.setPasswordHash(passwordEncoder.encode(request.getPassword()));
            user.setRole(providerRole);
            user.setAccountStatus("PENDING_APPROVAL");

            User saved = userRepository.save(user);

            ProviderProfile profile = new ProviderProfile();
            profile.setUser(saved);
            profile.setOrganisationType(request.getOrganisationType());
            profile.setRegistrationNumber(request.getRegistrationNumber().trim());
            profile.setCertificatePath(storedPath);
            profile.setCertificateFilename(
                    certificate.getOriginalFilename() != null
                            ? certificate.getOriginalFilename()
                            : "registration-certificate.pdf");
            profile.setSubmittedAt(LocalDateTime.now());
            providerProfileRepository.save(profile);

            auditService.log(request.getEmail(), AuditAction.REGISTER, "USER", saved.getUserId(),
                    "Provider registration pending approval: " + request.getFullName()
                            + " (" + ProviderOrgType.label(request.getOrganisationType())
                            + ", reg# " + request.getRegistrationNumber() + ")");

            notifyAdmins(saved);
            emailService.sendStatusUpdateEmail(
                    saved.getEmail(),
                    "ScholarZim provider application received",
                    """
                            Hi %s,

                            We received your provider application for %s.
                            Our team will review your Zimbabwe registration certificate and notify you by email once approved.

                            — The ScholarZim Team
                            """.formatted(saved.getFullName(), saved.getFullName()));
        } catch (IllegalArgumentException ex) {
            fileStorageService.deleteIfExists(storedPath);
            throw new RegistrationException(ex.getMessage());
        } catch (java.io.IOException ex) {
            fileStorageService.deleteIfExists(storedPath);
            throw new RegistrationException("Could not save registration certificate. Please try again.");
        } catch (RuntimeException ex) {
            fileStorageService.deleteIfExists(storedPath);
            throw ex;
        }
    }

    private void notifyAdmins(User applicant) {

        List<User> admins = userRepository.findByRoleRoleName("ROLE_ADMIN");
        String message = "New provider application: " + applicant.getFullName();
        for (User admin : admins) {
            notificationService.notifyUser(
                    admin,
                    NotificationType.PROVIDER_APPLICATION,
                    message,
                    "/admin/dashboard#user-management",
                    applicant.getUserId());
        }
    }
}
