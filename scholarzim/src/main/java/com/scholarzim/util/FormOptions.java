package com.scholarzim.util;

import java.util.List;

public final class FormOptions {

    private FormOptions() {
    }

    public static final List<String> EDUCATION_LEVELS = List.of(
            "High School (A-Level)",
            "Certificate",
            "Diploma",
            "Undergraduate",
            "Honours Degree",
            "Postgraduate",
            "Masters",
            "PhD"
    );

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
            "Mining & Metallurgy"
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
            "China",
            "Other"
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
            "Mutare Polytechnic",
            "Other"
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
