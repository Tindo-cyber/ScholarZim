package com.scholarzim.service;

import com.scholarzim.dto.ApplicantProfileRequest;
import com.scholarzim.entity.ApplicantProfile;

public interface ApplicantProfileService {

    void saveProfile(
            ApplicantProfileRequest request,
            String email
    );

    ApplicantProfile getProfileByEmail(String email);

    ApplicantProfileRequest toRequest(ApplicantProfile profile);

    boolean hasProfile(String email);
}
