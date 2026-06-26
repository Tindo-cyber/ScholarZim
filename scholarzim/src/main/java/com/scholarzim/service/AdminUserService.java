package com.scholarzim.service;

import org.springframework.lang.NonNull;

public interface AdminUserService {

    com.scholarzim.dto.PageResult<com.scholarzim.dto.AdminUserViewDTO> listApplicants(int page, int size);

    com.scholarzim.dto.PageResult<com.scholarzim.dto.AdminUserViewDTO> listProviders(int page, int size);

    java.util.List<com.scholarzim.dto.AdminUserViewDTO> listPendingProviders();

    void deleteApplicant(@NonNull Long userId, String adminEmail);

    void deleteProvider(@NonNull Long userId, String adminEmail);

    void suspendUser(@NonNull Long userId, String adminEmail);

    void reactivateUser(@NonNull Long userId, String adminEmail);

    void approveProvider(@NonNull Long userId, String adminEmail);
}
