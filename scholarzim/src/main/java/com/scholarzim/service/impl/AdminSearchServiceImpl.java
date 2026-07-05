package com.scholarzim.service.impl;

import com.scholarzim.dto.AdminSearchHitDTO;
import com.scholarzim.dto.AdminSearchResultsDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;
import com.scholarzim.entity.User;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.AdminSearchService;
import com.scholarzim.util.ApplicationStatus;
import com.scholarzim.util.OpportunityStatus;
import com.scholarzim.util.RoleNames;
import org.springframework.data.domain.PageRequest;
import org.springframework.stereotype.Service;
import org.springframework.util.StringUtils;


@Service
public class AdminSearchServiceImpl implements AdminSearchService {

    private static final int MIN_QUERY_LENGTH = 2;
    private static final int MAX_HITS = 10;

    private final UserRepository userRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicationRepository applicationRepository;

    public AdminSearchServiceImpl(
            UserRepository userRepository,
            OpportunityRepository opportunityRepository,
            ApplicationRepository applicationRepository) {

        this.userRepository = userRepository;
        this.opportunityRepository = opportunityRepository;
        this.applicationRepository = applicationRepository;
    }

    @Override
    public AdminSearchResultsDTO search(String query) {

        AdminSearchResultsDTO results = new AdminSearchResultsDTO();
        String q = query != null ? query.trim() : "";
        results.setQuery(q);

        if (q.length() < MIN_QUERY_LENGTH) {
            return results;
        }

        PageRequest limit = PageRequest.of(0, MAX_HITS);

        userRepository.adminSearch(q, limit).forEach(user -> results.getUsers().add(toUserHit(user)));
        opportunityRepository.adminSearchByKeyword(q, limit)
                .forEach(opp -> results.getScholarships().add(toScholarshipHit(opp)));
        applicationRepository.adminSearch(q, limit)
                .forEach(app -> results.getApplications().add(toApplicationHit(app)));

        return results;
    }

    private AdminSearchHitDTO toUserHit(User user) {

        AdminSearchHitDTO hit = new AdminSearchHitDTO();
        hit.setCategory("USER");
        hit.setTitle(user.getFullName());
        hit.setSubtitle(user.getEmail());
        hit.setLink("/admin/dashboard#user-management");
        hit.setBadge(RoleNames.displayLabel(user.getRole() != null ? user.getRole().getRoleName() : null));
        hit.setIcon("bi-person-circle");
        return hit;
    }

    private AdminSearchHitDTO toScholarshipHit(Opportunity opportunity) {

        AdminSearchHitDTO hit = new AdminSearchHitDTO();
        hit.setCategory("SCHOLARSHIP");
        hit.setTitle(opportunity.getTitle());
        hit.setSubtitle(buildScholarshipSubtitle(opportunity));
        hit.setLink("/scholarships/" + opportunity.getOpportunityId());
        hit.setBadge(OpportunityStatus.displayLabel(opportunity.getStatus()));
        hit.setIcon("bi-mortarboard");
        return hit;
    }

    private AdminSearchHitDTO toApplicationHit(Application application) {

        User applicant = application.getUser();
        Opportunity opportunity = application.getOpportunity();
        String statusLabel = ApplicationStatus.displayLabel(application.getApplicationStatus());

        AdminSearchHitDTO hit = new AdminSearchHitDTO();
        hit.setCategory("APPLICATION");
        hit.setTitle(applicant != null ? applicant.getFullName() : "Unknown applicant");
        hit.setSubtitle(opportunity != null
                ? opportunity.getTitle() + " · " + statusLabel
                : statusLabel);
        hit.setLink(opportunity != null
                ? "/scholarships/" + opportunity.getOpportunityId()
                : "/admin/dashboard");
        hit.setBadge(statusLabel);
        hit.setIcon("bi-file-earmark-text");
        return hit;
    }

    private static String buildScholarshipSubtitle(Opportunity opportunity) {

        String provider = opportunity.getProviderName();
        if (StringUtils.hasText(provider)) {
            return provider;
        }
        User owner = opportunity.getProvider();
        return owner != null ? owner.getEmail() : "—";
    }
}
