package com.scholarzim.service.impl;

import com.scholarzim.dto.OpportunityRequest;
import com.scholarzim.dto.OpportunitySearchRequest;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.exception.ResourceNotFoundException;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AuditService;
import com.scholarzim.service.NotificationService;
import com.scholarzim.service.OpportunityService;
import com.scholarzim.service.RecommendationService;
import com.scholarzim.util.AuditAction;
import com.scholarzim.util.FormOptions;
import com.scholarzim.util.NotificationType;
import lombok.extern.slf4j.Slf4j;
import org.springframework.cache.annotation.Cacheable;
import org.springframework.data.domain.Pageable;
import org.springframework.lang.NonNull;
import org.springframework.stereotype.Service;

import java.time.LocalDate;
import java.time.LocalDateTime;
import java.util.List;
import java.util.Optional;


@Slf4j
@Service
public class OpportunityServiceImpl implements OpportunityService {

    private final OpportunityRepository opportunityRepository;
    private final UserRepository userRepository;
    private final RecommendationService recommendationService;
    private final NotificationService notificationService;
    private final AuditService auditService;

    public OpportunityServiceImpl(
            OpportunityRepository opportunityRepository,
            UserRepository userRepository,
            RecommendationService recommendationService,
            NotificationService notificationService,
            AuditService auditService) {

        this.opportunityRepository = opportunityRepository;
        this.userRepository = userRepository;
        this.recommendationService = recommendationService;
        this.notificationService = notificationService;
        this.auditService = auditService;
    }

    @Override
    public void createOpportunity(OpportunityRequest request, String providerEmail) {

        User provider = userRepository.findByEmail(providerEmail)
                .orElseThrow(() -> new ResourceNotFoundException("Provider account not found."));

        if (provider.getAccountStatus() != null && !"ACTIVE".equalsIgnoreCase(provider.getAccountStatus())) {
            throw new org.springframework.security.access.AccessDeniedException(
                    "Your provider account must be approved before publishing scholarships.");
        }

        Opportunity opportunity = new Opportunity();
        opportunity.setTitle(request.getTitle());
        opportunity.setDescription(request.getDescription());
        opportunity.setEducationLevel(request.getEducationLevel());
        opportunity.setFundingType(request.getFundingType());
        String country = normalizeCountry(request.getCountry());
        opportunity.setCountry(country);
        opportunity.setDeadline(request.getDeadline());
        opportunity.setTargetField(request.getTargetField() != null ? request.getTargetField().trim() : null);
        opportunity.setTargetCountry(country);
        opportunity.setStatus("ACTIVE");
        opportunity.setProvider(provider);
        String displayName = request.getProviderDisplayName();
        opportunity.setProviderName(
                displayName != null && !displayName.isBlank() ? displayName.trim() : provider.getFullName());
        opportunity.setCreatedAt(LocalDateTime.now());

        Opportunity saved = opportunityRepository.save(opportunity);

        auditService.log(
                providerEmail,
                AuditAction.CREATE_OPPORTUNITY,
                "OPPORTUNITY",
                saved.getOpportunityId(),
                "Created opportunity \"" + saved.getTitle() + "\"");

        log.info("Opportunity created: id={} by={}", saved.getOpportunityId(), providerEmail);
        notifyMatchingApplicants(saved);
    }

    private void notifyMatchingApplicants(Opportunity opportunity) {

        recommendationService.findMatchingApplicants(opportunity)
                .forEach(applicant -> notificationService.notifyUser(
                        applicant,
                        NotificationType.NEW_OPPORTUNITY,
                        "New opportunity matches your profile: \"" + opportunity.getTitle() + "\".",
                        "/applicant/recommendations",
                        opportunity.getOpportunityId()));
    }

    @Override
    public List<Opportunity> getAllOpportunities() {
        return opportunityRepository.findAll();
    }

    @Override
    public List<Opportunity> getActiveOpportunities() {
        return opportunityRepository.search(
                LocalDate.now(), null, null, null, null, null, null);
    }

    @Override
    public List<Opportunity> getFeaturedOpportunities(int limit) {
        int safeLimit = Math.max(1, Math.min(limit, 24));
        return opportunityRepository.findActiveFeatured(LocalDate.now(), Pageable.ofSize(safeLimit));
    }

    @Override
    public long countActiveOpportunities() {
        return opportunityRepository.countActive(LocalDate.now());
    }

    @Override
    public long countUpcomingDeadlines() {
        return opportunityRepository.countUpcomingDeadlines(LocalDate.now());
    }

    @Override
    public List<Opportunity> searchOpportunities(OpportunitySearchRequest searchRequest) {

        OpportunitySearchRequest criteria =
                searchRequest != null ? searchRequest : new OpportunitySearchRequest();

        return opportunityRepository.searchWithKeyword(
                LocalDate.now(),
                normalize(criteria.getEducationLevel()),
                normalize(criteria.getCountry()),
                normalize(criteria.getFieldOfStudy()),
                normalize(criteria.getProvider()),
                normalize(criteria.getFundingType()),
                criteria.getDeadlineBefore(),
                normalize(criteria.getKeyword()));
    }

    @Override
    @Cacheable("providerNames")
    public List<String> getProviderNames() {
        return opportunityRepository.findDistinctProviderNames();
    }

    @Override
    public Optional<Opportunity> findById(@NonNull Long id) {
        return opportunityRepository.findById(id);
    }

    private String normalize(String value) {

        if (value == null) {
            return null;
        }

        String trimmed = value.trim();
        return trimmed.isEmpty() ? null : trimmed;
    }

    private String normalizeCountry(String value) {
        String normalized = normalize(value);
        return normalized != null ? normalized : FormOptions.DEFAULT_COUNTRY;
    }
}
