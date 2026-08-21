package com.scholarzim.entity;

import jakarta.persistence.*;
import lombok.Getter;
import lombok.Setter;

import java.time.LocalDateTime;


@Entity
@Table(name = "saved_scholarships")
@Getter
@Setter
public class SavedScholarship {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    @Column(name = "saved_id")
    private Long savedId;

    @ManyToOne(optional = false)
    @JoinColumn(name = "user_id")
    private User user;

    @ManyToOne(optional = false)
    @JoinColumn(name = "opportunity_id")
    private Opportunity opportunity;

    @Column(name = "saved_at")
    private LocalDateTime savedAt;
}
