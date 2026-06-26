package com.scholarzim.service;

import com.scholarzim.dto.ApplicantDashboardDTO;

public interface ApplicantDashboardService {

    ApplicantDashboardDTO getDashboardStats(String email);
}
