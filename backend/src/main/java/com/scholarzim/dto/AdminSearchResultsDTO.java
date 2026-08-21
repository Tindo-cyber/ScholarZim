package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;

import java.util.ArrayList;
import java.util.List;


@Getter
@Setter
public class AdminSearchResultsDTO {

    private String query;
    private List<AdminSearchHitDTO> users = new ArrayList<>();
    private List<AdminSearchHitDTO> scholarships = new ArrayList<>();
    private List<AdminSearchHitDTO> applications = new ArrayList<>();

    public int getTotalCount() {
        return users.size() + scholarships.size() + applications.size();
    }

    public boolean isEmpty() {
        return getTotalCount() == 0;
    }
}
