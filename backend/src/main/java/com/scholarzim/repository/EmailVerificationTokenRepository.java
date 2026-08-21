package com.scholarzim.repository;

import com.scholarzim.entity.EmailVerificationToken;
import com.scholarzim.entity.User;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Modifying;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.Optional;


public interface EmailVerificationTokenRepository extends JpaRepository<EmailVerificationToken, Long> {

    Optional<EmailVerificationToken> findByTokenAndUsedFalse(String token);

    @Modifying
    @Query("UPDATE EmailVerificationToken t SET t.used = true WHERE t.user = :user AND t.used = false")
    void invalidateActiveTokensForUser(@Param("user") User user);
}
