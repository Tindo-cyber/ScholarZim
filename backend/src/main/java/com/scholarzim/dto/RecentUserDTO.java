package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;

import java.time.LocalDateTime;


@Getter
@Setter
public class RecentUserDTO {

    private String email;
    private String displayName;
    private String roleLabel;
    private LocalDateTime joinedAt;
}
