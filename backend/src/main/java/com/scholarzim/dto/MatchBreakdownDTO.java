package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;

import java.util.ArrayList;
import java.util.List;


@Getter
@Setter
public class MatchBreakdownDTO {

    private int educationLevelScore;
    private int fieldScore;
    private int locationScore;
    private int deadlineScore;
    private int academicScore;
    private int certificateScore;

    private String explanation;
    private String confidenceLevel;
    private String confidenceLabel;

    private List<MatchReasonDTO> reasons = new ArrayList<>();
    private List<String> missingRequirements = new ArrayList<>();

    public int totalScore() {
        return educationLevelScore + fieldScore + locationScore + deadlineScore
                + academicScore + certificateScore;
    }
}
