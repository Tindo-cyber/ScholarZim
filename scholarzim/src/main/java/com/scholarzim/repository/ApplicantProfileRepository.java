package com.scholarzim.repository;

import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.User;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Optional;


public interface ApplicantProfileRepository
        extends JpaRepository<ApplicantProfile, Long> {

    Optional<ApplicantProfile> findByUser(User user);

    void deleteByUser(User user);
}
