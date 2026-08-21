package com.scholarzim.service;

import com.scholarzim.dto.AdminCreateUserRequest;
import com.scholarzim.dto.StoredFileResource;
import org.springframework.lang.NonNull;
import org.springframework.web.multipart.MultipartFile;


public interface AdminUserService {

    /**
     * Creates a user account directly, bypassing self-registration. Only a super admin may
     * create ROLE_ADMIN accounts (enforced via @PreAuthorize on the implementation); any admin
     * may create ROLE_PROVIDER or ROLE_APPLICANT accounts. {@code certificate} is required (and
     * only used) when the requested role is ROLE_PROVIDER.
     */
    void createUser(AdminCreateUserRequest request, MultipartFile certificate, String adminEmail);

    com.scholarzim.dto.PageResult<com.scholarzim.dto.AdminUserViewDTO> listApplicants(int page, int size);

    com.scholarzim.dto.PageResult<com.scholarzim.dto.AdminUserViewDTO> listProviders(int page, int size);

    java.util.List<com.scholarzim.dto.AdminUserViewDTO> listPendingProviders();

    void deleteApplicant(@NonNull Long userId, String adminEmail);

    void deleteProvider(@NonNull Long userId, String adminEmail);

    void suspendUser(@NonNull Long userId, String adminEmail);

    void reactivateUser(@NonNull Long userId, String adminEmail);

    void approveProvider(@NonNull Long userId, String adminEmail);

    void rejectProvider(@NonNull Long userId, String adminEmail, String reason);

    StoredFileResource loadProviderCertificate(@NonNull Long userId, String adminEmail);
}
