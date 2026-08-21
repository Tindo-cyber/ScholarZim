package com.scholarzim.dto;

import lombok.AllArgsConstructor;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;


@Getter
@Setter
@NoArgsConstructor
@AllArgsConstructor
public class MatchReasonDTO {

    private String key;
    private String label;
    private boolean satisfied;
}
