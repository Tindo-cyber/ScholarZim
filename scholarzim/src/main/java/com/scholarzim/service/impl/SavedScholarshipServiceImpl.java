package com.scholarzim.service.impl;

import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.SavedScholarship;
import com.scholarzim.entity.User;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.SavedScholarshipRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.SavedScholarshipService;
import org.springframework.lang.NonNull;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.LocalDateTime;
import java.util.HashSet;
import java.util.List;
import java.util.Set;


@Service
public class SavedScholarshipServiceImpl implements SavedScholarshipService {

    private final SavedScholarshipRepository savedRepository;
    private final UserRepository userRepository;
    private final OpportunityRepository opportunityRepository;

    public SavedScholarshipServiceImpl(
            SavedScholarshipRepository savedRepository,
            UserRepository userRepository,
            OpportunityRepository opportunityRepository) {

        this.savedRepository = savedRepository;
        this.userRepository = userRepository;
        this.opportunityRepository = opportunityRepository;
    }

    @Override
    @Transactional
    public void save(String email, @NonNull Long opportunityId) {

        User user = userRepository.findByEmail(email)
                .orElseThrow(() -> new ResourceNotFoundException("User not found"));
        Opportunity opportunity = opportunityRepository.findById(opportunityId)
                .orElseThrow(() -> new ResourceNotFoundException("Scholarship not found"));

        if (savedRepository.existsByUserAndOpportunityOpportunityId(user, opportunityId)) {
            return;
        }

        SavedScholarship saved = new SavedScholarship();
        saved.setUser(user);
        saved.setOpportunity(opportunity);
        saved.setSavedAt(LocalDateTime.now());
        savedRepository.save(saved);
    }

    @Override
    @Transactional
    public void remove(String email, @NonNull Long opportunityId) {

        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return;
        }
        savedRepository.deleteByUserAndOpportunityOpportunityId(user, opportunityId);
    }

    @Override
    public boolean isSaved(String email, @NonNull Long opportunityId) {

        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return false;
        }
        return savedRepository.existsByUserAndOpportunityOpportunityId(user, opportunityId);
    }

    @Override
    public List<Opportunity> listSaved(String email) {

        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return List.of();
        }
        return savedRepository.findByUserWithOpportunityOrderBySavedAtDesc(user).stream()
                .map(SavedScholarship::getOpportunity)
                .toList();
    }

    @Override
    public Set<Long> listSavedOpportunityIds(String email) {

        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return Set.of();
        }
        return new HashSet<>(savedRepository.findOpportunityIdsByUser(user));
    }

    @Override
    public long countSaved(String email) {

        User user = userRepository.findByEmail(email).orElse(null);
        if (user == null) {
            return 0L;
        }
        return savedRepository.countByUser(user);
    }
}
