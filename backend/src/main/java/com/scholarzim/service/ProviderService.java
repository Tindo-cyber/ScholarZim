package com.scholarzim.service;

import com.scholarzim.dto.ProviderDashboardDTO;
import com.scholarzim.entity.Application;
import com.scholarzim.entity.Opportunity;

import java.util.List;


public interface ProviderService {

    ProviderDashboardDTO getDashboardStats(String providerEmail);

    List<Opportunity> getMyOpportunities(String providerEmail);

    List<Application> getRecentApplications(String providerEmail, int limit);
}
