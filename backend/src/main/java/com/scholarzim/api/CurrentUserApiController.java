package com.scholarzim.api;

import com.scholarzim.dto.CurrentUserDTO;
import com.scholarzim.entity.User;
import com.scholarzim.repository.UserRepository;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.Optional;


/**
 * Identity endpoint for the React app. Unauthenticated callers get
 * {@code authenticated: false} rather than a 401, so the SPA can render a
 * signed-out shell without treating the response as an error.
 */
@RestController
@RequestMapping("/api")
@Tag(name = "Session")
public class CurrentUserApiController {

    private final UserRepository userRepository;

    public CurrentUserApiController(UserRepository userRepository) {
        this.userRepository = userRepository;
    }

    @GetMapping("/me")
    @Operation(summary = "Current session user",
            description = "Returns the signed-in user, or authenticated=false when anonymous.")
    public CurrentUserDTO me() {
        Authentication auth = SecurityContextHolder.getContext().getAuthentication();
        if (auth == null || !auth.isAuthenticated() || "anonymousUser".equals(auth.getPrincipal())) {
            return CurrentUserDTO.anonymous();
        }

        Optional<User> found = userRepository.findByEmailWithRole(auth.getName());
        if (found.isEmpty()) {
            return CurrentUserDTO.anonymous();
        }

        User user = found.get();
        CurrentUserDTO dto = new CurrentUserDTO();
        dto.setAuthenticated(true);
        dto.setUserId(user.getUserId());
        dto.setFullName(user.getFullName());
        dto.setEmail(user.getEmail());
        dto.setRole(user.getRole() != null ? user.getRole().getRoleName() : null);
        dto.setSuperAdmin(user.isSuperAdmin());
        return dto;
    }
}
