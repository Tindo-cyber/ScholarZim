package com.scholarzim.service.impl;

import com.scholarzim.entity.User;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.UserLookupService;
import org.springframework.lang.NonNull;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;


@Service
@Transactional(readOnly = true)
public class UserLookupServiceImpl implements UserLookupService {

    private final UserRepository userRepository;

    public UserLookupServiceImpl(UserRepository userRepository) {
        this.userRepository = userRepository;
    }

    @Override
    public User requireByEmail(@NonNull String email) {
        return userRepository.findByEmail(email)
                .orElseThrow(() -> new ResourceNotFoundException("User not found: " + email));
    }

    @Override
    public User requireById(@NonNull Long userId) {
        return userRepository.findById(userId)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));
    }
}
