package com.scholarzim.repository;

import com.scholarzim.entity.User;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;
import java.util.Optional;


public interface UserRepository extends JpaRepository<User, Long> {

    Optional<User> findByEmail(String email);

    boolean existsByEmail(String email);

    long countByRoleRoleName(String roleName);

    long countByAccountStatus(String accountStatus);

    List<User> findByRoleRoleName(String roleName);

    List<User> findByRoleRoleNameAndAccountStatus(String roleName, String accountStatus);

    Page<User> findByRoleRoleName(String roleName, Pageable pageable);

    @Query("""
            SELECT u FROM User u
            WHERE LOWER(u.email) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(u.fullName) LIKE LOWER(CONCAT('%', :q, '%'))
               OR LOWER(u.phone) LIKE LOWER(CONCAT('%', :q, '%'))
            ORDER BY u.fullName
            """)
    List<User> adminSearch(@Param("q") String query, Pageable pageable);
}
