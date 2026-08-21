package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;


/**
 * Identity payload for the React app: who is signed in and what they may see.
 * Deliberately excludes anything sensitive — the SPA only needs enough to pick
 * a navigation shell and guard its routes. Authorisation itself stays on the
 * server, where every endpoint re-checks the session.
 */
@Getter
@Setter
public class CurrentUserDTO {

    private boolean authenticated;
    private Long userId;
    private String fullName;
    private String email;
    /** One of ROLE_APPLICANT, ROLE_PROVIDER, ROLE_ADMIN; null when anonymous. */
    private String role;
    private boolean superAdmin;

    public static CurrentUserDTO anonymous() {
        CurrentUserDTO dto = new CurrentUserDTO();
        dto.setAuthenticated(false);
        return dto;
    }
}
