package com.scholarzim.entity;

import jakarta.persistence.*;
import lombok.Getter;
import lombok.Setter;

import java.time.LocalDateTime;


@Entity
@Table(name = "notifications")
@Getter
@Setter
public class Notification {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    @Column(name = "notification_id")
    private Long notificationId;

    @ManyToOne
    @JoinColumn(name = "user_id")
    private User user;

    @Column(name = "type")
    private String type;

    @Column(name = "message", length = 500)
    private String message;

    @Column(name = "link")
    private String link;

    @Column(name = "related_id")
    private Long relatedId;

    @Column(name = "is_read")
    private boolean read;

    public boolean isUnread() {
        return !read;
    }

    @Column(name = "created_at")
    private LocalDateTime createdAt;
}
