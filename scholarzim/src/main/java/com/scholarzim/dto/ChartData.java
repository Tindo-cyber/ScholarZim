package com.scholarzim.dto;

import lombok.Getter;
import lombok.Setter;

import java.util.ArrayList;
import java.util.List;

@Getter
@Setter
public class ChartData {

    private List<String> labels = new ArrayList<>();
    private List<Long> data = new ArrayList<>();

    public void add(String label, Long value) {
        labels.add(label);
        data.add(value);
    }
}
