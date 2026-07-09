package com.scholarzim.repository;

import com.scholarzim.entity.ApplicantProfile;
import com.scholarzim.entity.User;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;

import java.util.Optional;


public interface ApplicantProfileRepository
        extends JpaRepository<ApplicantProfile, Long> {

    Optional<ApplicantProfile> findByUser(User user);

    void deleteByUser(User user);

    @Query("""
            SELECT COUNT(DISTINCT p.institutionName) FROM ApplicantProfile p
            WHERE p.institutionName IS NOT NULL AND TRIM(p.institutionName) <> ''
            """)
    long countDistinctInstitutions();

    @Query("SELECT p FROM ApplicantProfile p JOIN FETCH p.user")
    java.util.List<ApplicantProfile> findAllWithUser();
}
