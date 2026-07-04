package com.scholarzim.util;

import java.util.List;
import java.util.stream.Stream;


public final class FormOptions {

    public static final String DEFAULT_COUNTRY = "Zimbabwe";

    private FormOptions() {
    }

    public static final List<String> PRIMARY_GRADES = List.of(
            "Primary — Grade 1",
            "Primary — Grade 2",
            "Primary — Grade 3",
            "Primary — Grade 4",
            "Primary — Grade 5",
            "Primary — Grade 6",
            "Primary — Grade 7"
    );

    public static final List<String> SECONDARY_FORMS = List.of(
            "Secondary — Form 1",
            "Secondary — Form 2",
            "Secondary — Form 3",
            "Secondary — Form 4",
            "Secondary — Form 5",
            "Secondary — Form 6"
    );

    public static final List<String> TERTIARY_LEVELS = List.of(
            "High School (A-Level)",
            "Certificate",
            "Diploma",
            "Undergraduate",
            "Honours Degree",
            "Postgraduate",
            "Masters",
            "PhD"
    );

    public static final List<String> EDUCATION_LEVELS = Stream.of(
                    PRIMARY_GRADES.stream(),
                    SECONDARY_FORMS.stream(),
                    TERTIARY_LEVELS.stream())
            .flatMap(s -> s)
            .toList();

    public static final List<String> FIELDS_OF_STUDY = List.of(
            "Computer Science & IT",
            "Engineering",
            "Medicine & Health Sciences",
            "Law",
            "Business & Finance",
            "Education",
            "Agriculture & Agribusiness",
            "Arts & Humanities",
            "Natural Sciences",
            "Social Sciences",
            "Nursing",
            "Accounting",
            "Environmental Science",
            "Mining & Metallurgy",
            "General Primary",
            "General Secondary"
    );

    public static final List<String> COUNTRIES = List.of(
            "Zimbabwe",
            "South Africa",
            "Botswana",
            "Namibia",
            "Zambia",
            "Mozambique",
            "Malawi",
            "Kenya",
            "United Kingdom",
            "United States",
            "Canada",
            "Australia",
            "Germany",
            "China"
    );

    public static final List<String> ZIMBABWE_PROVINCES = List.of(
            "Bulawayo",
            "Harare",
            "Manicaland",
            "Mashonaland Central",
            "Mashonaland East",
            "Mashonaland West",
            "Masvingo",
            "Matabeleland North",
            "Matabeleland South",
            "Midlands"
    );

    public static final List<String> INSTITUTIONS = List.of(
            "University of Zimbabwe (UZ)",
            "National University of Science and Technology (NUST)",
            "Midlands State University (MSU)",
            "Chinhoyi University of Technology (CUT)",
            "Great Zimbabwe University (GZU)",
            "Bindura University of Science Education (BUSE)",
            "Lupane State University (LSU)",
            "Zimbabwe Open University (ZOU)",
            "Harare Institute of Technology (HIT)",
            "Solusi University",
            "Catholic University of Zimbabwe",
            "Africa University",
            "Bulawayo Polytechnic",
            "Harare Polytechnic",
            "Gweru Polytechnic",
            "Mutare Polytechnic"
    );

    public static final List<String> FUNDING_TYPES = List.of(
            "Full Scholarship",
            "Partial Scholarship",
            "Tuition Only",
            "Tuition + Accommodation",
            "Monthly Stipend",
            "Research Grant"
    );
}
