package com.scholarzim.service;

import com.scholarzim.entity.User;
import org.springframework.lang.NonNull;


public interface UserLookupService {

    User requireByEmail(@NonNull String email);

    User requireById(@NonNull Long userId);
}
