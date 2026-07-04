package com.scholarzim.repository;

import com.scholarzim.entity.ProviderProfile;
import com.scholarzim.entity.User;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Optional;


public interface ProviderProfileRepository extends JpaRepository<ProviderProfile, Long> {

    Optional<ProviderProfile> findByUser(User user);

    Optional<ProviderProfile> findByUserUserId(Long userId);
}
