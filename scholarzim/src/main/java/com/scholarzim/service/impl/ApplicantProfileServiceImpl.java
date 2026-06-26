package com.scholarzim.service.impl;

import com.scholarzim.dto.ApplicantProfileRequest;
import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.ApplicantProfileService;
import org.springframework.stereotype.Service;

@Service
public class ApplicantProfileServiceImpl
        implements ApplicantProfileService {

    private final ApplicantProfileRepository profileRepository;
    private final UserRepository userRepository;

    public ApplicantProfileServiceImpl(
            ApplicantProfileRepository profileRepository,
            UserRepository userRepository) {

        this.profileRepository = profileRepository;
        this.userRepository = userRepository;
    }

    @Override
    public void saveProfile(
            ApplicantProfileRequest request,
            String email) {

        User user = userRepository
                .findByEmail(email)
                .orElseThrow();

        ApplicantProfile profile =
                profileRepository.findByUser(user)
                        .orElseGet(ApplicantProfile::new);

        profile.setUser(user);
        profile.setEducationLevel(
                request.getEducationLevel());

        profile.setInstitutionName(
                request.getInstitutionName());

        profile.setFieldOfStudy(
                request.getFieldOfStudy());

        profile.setCountry(
                request.getCountry());

        profile.setProvince(
                request.getProvince());

        profile.setAcademicResults(
                request.getAcademicResults());

        profile.setBiography(
                request.getBiography());

        profileRepository.save(profile);
    }

    @Override
    public ApplicantProfile getProfileByEmail(String email) {

        User user = userRepository.findByEmail(email)
                .orElseThrow();

        return profileRepository.findByUser(user).orElse(null);
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
}
