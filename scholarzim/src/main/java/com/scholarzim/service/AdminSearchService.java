package com.scholarzim.service;

import com.scholarzim.dto.AdminSearchResultsDTO;


public interface AdminSearchService {

    AdminSearchResultsDTO search(String query);
}
