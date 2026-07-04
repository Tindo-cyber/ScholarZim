package com.scholarzim.service.impl;

import com.scholarzim.entity.ProviderProfile;
import com.scholarzim.entity.Role;
import com.scholarzim.entity.User;
import com.scholarzim.exception.AdminOperationException;
import com.scholarzim.repository.*;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.EmailService;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.service.NotificationService;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import java.nio.file.Files;
import java.time.LocalDateTime;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

class AdminUserServiceImplVerificationTest {

    @TempDir
    java.nio.file.Path uploadDir;

    private UserRepository userRepository;
    private ProviderProfileRepository providerProfileRepository;
    private FileStorageService fileStorageService;
    private AdminUserServiceImpl service;

    @BeforeEach
    void setUp() {
        userRepository = mock(UserRepository.class);
        providerProfileRepository = mock(ProviderProfileRepository.class);
        fileStorageService = new FileStorageService(uploadDir.toString());
        service = new AdminUserServiceImpl(
                userRepository,
                mock(ApplicantProfileRepository.class),
                providerProfileRepository,
                mock(ApplicationRepository.class),
                mock(OpportunityRepository.class),
                mock(NotificationRepository.class),
                mock(AuditService.class),
                fileStorageService,
                mock(NotificationService.class),
                mock(EmailService.class));
    }

    @Test
    void approveProviderWithoutProfileFails() {
        User pending = pendingProvider(1L);

        when(userRepository.findById(1L)).thenReturn(Optional.of(pending));
        when(providerProfileRepository.findByUser(pending)).thenReturn(Optional.empty());

        assertThrows(AdminOperationException.class,
                () -> service.approveProvider(1L, "admin@test.com"));

        assertEquals("PENDING_APPROVAL", pending.getAccountStatus());
    }

    @Test
    void approveProviderWithoutCertificatePathFails() {
        User pending = pendingProvider(2L);
        ProviderProfile profile = new ProviderProfile();
        profile.setUser(pending);
        profile.setCertificatePath(null);

        when(userRepository.findById(2L)).thenReturn(Optional.of(pending));
        when(providerProfileRepository.findByUser(pending)).thenReturn(Optional.of(profile));

        assertThrows(AdminOperationException.class,
                () -> service.approveProvider(2L, "admin@test.com"));

        verify(userRepository, never()).save(any());
    }

    @Test
    void approveProviderWithCertificateOnDiskActivatesAccount() throws Exception {
        User pending = pendingProvider(3L);
        ProviderProfile profile = new ProviderProfile();
        profile.setUser(pending);
        profile.setOrganisationType("NGO");
        profile.setRegistrationNumber("NGO-1");
        profile.setSubmittedAt(LocalDateTime.now());
        String certPath = "provider-verification-test.pdf";
        Files.writeString(uploadDir.resolve(certPath), "%PDF-1.4 cert");
        profile.setCertificatePath(certPath);
        profile.setCertificateFilename("cert.pdf");

        when(userRepository.findById(3L)).thenReturn(Optional.of(pending));
        when(providerProfileRepository.findByUser(pending)).thenReturn(Optional.of(profile));

        service.approveProvider(3L, "admin@test.com");

        assertEquals("ACTIVE", pending.getAccountStatus());
        verify(userRepository).save(pending);
        verify(providerProfileRepository).save(profile);
    }

    @Test
    void loadProviderCertificateWhenMissingOnDiskThrows() {
        User provider = pendingProvider(4L);
        ProviderProfile profile = new ProviderProfile();
        profile.setUser(provider);
        profile.setCertificatePath("missing.pdf");
        profile.setCertificateFilename("missing.pdf");

        when(userRepository.findById(4L)).thenReturn(Optional.of(provider));
        when(providerProfileRepository.findByUser(provider)).thenReturn(Optional.of(profile));

        assertThrows(com.scholarzim.exception.ResourceNotFoundException.class,
                () -> service.loadProviderCertificate(4L, "admin@test.com"));
    }

    private static User pendingProvider(Long id) {
        User user = new User();
        user.setUserId(id);
        user.setFullName("Pending Org");
        user.setEmail("pending-" + id + "@org.co.zw");
        user.setAccountStatus("PENDING_APPROVAL");
        Role role = new Role();
        role.setRoleName("ROLE_PROVIDER");
        user.setRole(role);
        return user;
    }
}
