package com.scholarzim.service;

import com.scholarzim.dto.ProviderRegisterRequest;
import org.springframework.web.multipart.MultipartFile;


public interface ProviderRegistrationService {

    void registerProvider(ProviderRegisterRequest request, MultipartFile certificate);
}
