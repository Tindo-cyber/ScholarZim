package com.scholarzim.repository;

import com.scholarzim.entity.Notification;
import com.scholarzim.entity.User;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Modifying;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;


public interface NotificationRepository
        extends JpaRepository<Notification, Long> {

    List<Notification> findByUserOrderByCreatedAtDesc(User user);

    List<Notification> findTop10ByUserOrderByCreatedAtDesc(User user);

    long countByUserAndReadFalse(User user);

    boolean existsByUserAndTypeAndRelatedId(User user, String type, Long relatedId);

    @Modifying
    @Query("UPDATE Notification n SET n.read = true WHERE n.user = :user AND n.read = false")
    void markAllReadForUser(@Param("user") User user);

    void deleteByUser(User user);
}
