package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;


@Getter
@Setter
public class MatchBreakdownDTO {

    private int educationLevelScore;
    private int fieldScore;
    private int locationScore;
    private int deadlineScore;
    private String explanation;

    public int totalScore() {
        return educationLevelScore + fieldScore + locationScore + deadlineScore;
    }
}
