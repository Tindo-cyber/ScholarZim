# ISCED-F 2013 field classification used by ScholarZim

This is the full International Standard Classification of Education: Fields of Education and Training 2013 (ISCED-F 2013), as held in `database/seeders/data/fields.csv` and loaded by `php artisan catalogue:import fields`. It is generated from that file, so it cannot drift from the data.

**Source.** Codes, names and parent relationships were read from the UNESCO Institute for Statistics manual *ISCED Fields of Education and Training 2013 (ISCED-F 2013)*, Appendix I (the classification), and checked against the UNESCO General Conference document 37 C/53 Appendix 1. They were not typed from memory. The inclusion lists quoted in the programme reclassification come from the same manual's detailed field descriptions.

**Levels.** Broad (2 digits), narrow (3), detailed (4). The supplementary codes are included: `0` not further defined, `8` inter-disciplinary programmes and qualifications, `9` not elsewhere classified. Code `99` (field unknown) is a data-collection code, not a field of study, and is deliberately left out.

**Targeting.** A field marked *heading* is a navigation heading and is not offered to providers or students as a choice; the fields beneath it are. Choosing a field includes every field below it. Broad fields are selectable only where they hold exactly one discipline (01 Education, 06 ICT), so "Business, administration and law" is a heading and Business and administration (041) and Law (042) are separate choices.

**Counts.** 216 codes: 11 broad, 57 narrow, 148 detailed. 120 are classification fields; 96 are supplementary codes.


## 00 Generic programmes and qualifications *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 00 | broad | Generic programmes and qualifications | official | heading |
| 000 | narrow | &nbsp;&nbsp;Generic programmes and qualifications not further defined | not further defined | heading |
| 001 | narrow | &nbsp;&nbsp;Basic programmes and qualifications | official | yes |
| 002 | narrow | &nbsp;&nbsp;Literacy and numeracy | official | yes |
| 003 | narrow | &nbsp;&nbsp;Personal skills and development | official | yes |
| 009 | narrow | &nbsp;&nbsp;Generic programmes and qualifications not elsewhere classified | not elsewhere classified | heading |
| 0000 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Generic programmes and qualifications not further defined | not further defined | heading |
| 0011 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Basic programmes and qualifications | official | yes |
| 0021 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Literacy and numeracy | official | yes |
| 0031 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Personal skills and development | official | yes |
| 0099 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Generic programmes and qualifications not elsewhere classified | not elsewhere classified | heading |

## 01 Education

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 01 | broad | Education | official | yes |
| 011 | narrow | &nbsp;&nbsp;Education | official | yes |
| 018 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving education | inter-disciplinary | heading |
| 0110 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Education not further defined | not further defined | heading |
| 0111 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Education science | official | yes |
| 0112 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Training for pre-school teachers | official | yes |
| 0113 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Teacher training without subject specialisation | official | yes |
| 0114 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Teacher training with subject specialisation | official | yes |
| 0119 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Education not elsewhere classified | not elsewhere classified | heading |
| 0188 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving education | inter-disciplinary | heading |

## 02 Arts and humanities *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 02 | broad | Arts and humanities | official | heading |
| 020 | narrow | &nbsp;&nbsp;Arts and humanities not further defined | not further defined | heading |
| 021 | narrow | &nbsp;&nbsp;Arts | official | yes |
| 022 | narrow | &nbsp;&nbsp;Humanities (except languages) | official | yes |
| 023 | narrow | &nbsp;&nbsp;Languages | official | yes |
| 028 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving arts and humanities | inter-disciplinary | heading |
| 029 | narrow | &nbsp;&nbsp;Arts and humanities not elsewhere classified | not elsewhere classified | heading |
| 0200 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Arts and humanities not further defined | not further defined | heading |
| 0210 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Arts not further defined | not further defined | heading |
| 0211 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Audio-visual techniques and media production | official | yes |
| 0212 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Fashion, interior and industrial design | official | yes |
| 0213 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Fine arts | official | yes |
| 0214 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Handicrafts | official | yes |
| 0215 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Music and performing arts | official | yes |
| 0219 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Arts not elsewhere classified | not elsewhere classified | heading |
| 0220 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Humanities (except languages) not further defined | not further defined | heading |
| 0221 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Religion and theology | official | yes |
| 0222 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;History and archaeology | official | yes |
| 0223 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Philosophy and ethics | official | yes |
| 0229 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Humanities (except languages) not elsewhere classified | not elsewhere classified | heading |
| 0230 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Languages not further defined | not further defined | heading |
| 0231 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Language acquisition | official | yes |
| 0232 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Literature and linguistics | official | yes |
| 0239 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Languages not elsewhere classified | not elsewhere classified | heading |
| 0288 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving arts and humanities | inter-disciplinary | heading |
| 0299 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Arts and humanities not elsewhere classified | not elsewhere classified | heading |

## 03 Social sciences, journalism and information *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 03 | broad | Social sciences, journalism and information | official | heading |
| 030 | narrow | &nbsp;&nbsp;Social sciences, journalism and information not further defined | not further defined | heading |
| 031 | narrow | &nbsp;&nbsp;Social and behavioural sciences | official | yes |
| 032 | narrow | &nbsp;&nbsp;Journalism and information | official | yes |
| 038 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving social sciences, journalism and information | inter-disciplinary | heading |
| 039 | narrow | &nbsp;&nbsp;Social sciences, journalism and information not elsewhere classified | not elsewhere classified | heading |
| 0300 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Social sciences, journalism and information not further defined | not further defined | heading |
| 0310 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Social and behavioural sciences not further defined | not further defined | heading |
| 0311 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Economics | official | yes |
| 0312 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Political sciences and civics | official | yes |
| 0313 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Psychology | official | yes |
| 0314 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Sociology and cultural studies | official | yes |
| 0319 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Social and behavioural sciences not elsewhere classified | not elsewhere classified | heading |
| 0320 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Journalism and information not further defined | not further defined | heading |
| 0321 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Journalism and reporting | official | yes |
| 0322 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Library, information and archival studies | official | yes |
| 0329 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Journalism and information not elsewhere classified | not elsewhere classified | heading |
| 0388 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving social sciences, journalism and information | inter-disciplinary | heading |
| 0399 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Social sciences, journalism and information not elsewhere classified | not elsewhere classified | heading |

## 04 Business, administration and law *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 04 | broad | Business, administration and law | official | heading |
| 040 | narrow | &nbsp;&nbsp;Business, administration and law not further defined | not further defined | heading |
| 041 | narrow | &nbsp;&nbsp;Business and administration | official | yes |
| 042 | narrow | &nbsp;&nbsp;Law | official | yes |
| 048 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving business, administration and law | inter-disciplinary | heading |
| 049 | narrow | &nbsp;&nbsp;Business, administration and law not elsewhere classified | not elsewhere classified | heading |
| 0400 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Business, administration and law not further defined | not further defined | heading |
| 0410 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Business and administration not further defined | not further defined | heading |
| 0411 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Accounting and taxation | official | yes |
| 0412 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Finance, banking and insurance | official | yes |
| 0413 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Management and administration | official | yes |
| 0414 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Marketing and advertising | official | yes |
| 0415 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Secretarial and office work | official | yes |
| 0416 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Wholesale and retail sales | official | yes |
| 0417 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Work skills | official | yes |
| 0419 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Business and administration not elsewhere classified | not elsewhere classified | heading |
| 0421 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Law | official | yes |
| 0488 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving business, administration and law | inter-disciplinary | heading |
| 0499 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Business, administration and law not elsewhere classified | not elsewhere classified | heading |

## 05 Natural sciences, mathematics and statistics *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 05 | broad | Natural sciences, mathematics and statistics | official | heading |
| 050 | narrow | &nbsp;&nbsp;Natural sciences, mathematics and statistics not further defined | not further defined | heading |
| 051 | narrow | &nbsp;&nbsp;Biological and related sciences | official | yes |
| 052 | narrow | &nbsp;&nbsp;Environment | official | yes |
| 053 | narrow | &nbsp;&nbsp;Physical sciences | official | yes |
| 054 | narrow | &nbsp;&nbsp;Mathematics and statistics | official | yes |
| 058 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving natural sciences, mathematics and statistics | inter-disciplinary | heading |
| 059 | narrow | &nbsp;&nbsp;Natural sciences, mathematics and statistics not elsewhere classified | not elsewhere classified | heading |
| 0500 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Natural sciences, mathematics and statistics not further defined | not further defined | heading |
| 0510 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Biological and related sciences not further defined | not further defined | heading |
| 0511 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Biology | official | yes |
| 0512 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Biochemistry | official | yes |
| 0519 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Biological and related sciences not elsewhere classified | not elsewhere classified | heading |
| 0520 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Environment not further defined | not further defined | heading |
| 0521 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Environmental sciences | official | yes |
| 0522 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Natural environments and wildlife | official | yes |
| 0529 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Environment not elsewhere classified | not elsewhere classified | heading |
| 0530 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Physical sciences not further defined | not further defined | heading |
| 0531 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Chemistry | official | yes |
| 0532 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Earth sciences | official | yes |
| 0533 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Physics | official | yes |
| 0539 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Physical sciences not elsewhere classified | not elsewhere classified | heading |
| 0540 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Mathematics and statistics not further defined | not further defined | heading |
| 0541 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Mathematics | official | yes |
| 0542 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Statistics | official | yes |
| 0588 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving natural sciences, mathematics and statistics | inter-disciplinary | heading |
| 0599 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Natural sciences, mathematics and statistics not elsewhere classified | not elsewhere classified | heading |

## 06 Information and Communication Technologies (ICTs)

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 06 | broad | Information and Communication Technologies (ICTs) | official | yes |
| 061 | narrow | &nbsp;&nbsp;Information and Communication Technologies (ICTs) | official | yes |
| 068 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving Information and Communication Technologies (ICTs) | inter-disciplinary | heading |
| 0610 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Information and Communication Technologies (ICTs) not further defined | not further defined | heading |
| 0611 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Computer use | official | yes |
| 0612 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Database and network design and administration | official | yes |
| 0613 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Software and applications development and analysis | official | yes |
| 0619 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Information and Communication Technologies (ICTs) not elsewhere classified | not elsewhere classified | heading |
| 0688 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving Information and Communication Technologies (ICTs) | inter-disciplinary | heading |

## 07 Engineering, manufacturing and construction *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 07 | broad | Engineering, manufacturing and construction | official | heading |
| 070 | narrow | &nbsp;&nbsp;Engineering, manufacturing and construction not further defined | not further defined | heading |
| 071 | narrow | &nbsp;&nbsp;Engineering and engineering trades | official | yes |
| 072 | narrow | &nbsp;&nbsp;Manufacturing and processing | official | yes |
| 073 | narrow | &nbsp;&nbsp;Architecture and construction | official | yes |
| 078 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving engineering, manufacturing and construction | inter-disciplinary | heading |
| 079 | narrow | &nbsp;&nbsp;Engineering, manufacturing and construction not elsewhere classified | not elsewhere classified | heading |
| 0700 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Engineering, manufacturing and construction not further defined | not further defined | heading |
| 0710 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Engineering and engineering trades not further defined | not further defined | heading |
| 0711 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Chemical engineering and processes | official | yes |
| 0712 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Environmental protection technology | official | yes |
| 0713 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Electricity and energy | official | yes |
| 0714 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Electronics and automation | official | yes |
| 0715 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Mechanics and metal trades | official | yes |
| 0716 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Motor vehicles, ships and aircraft | official | yes |
| 0719 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Engineering and engineering trades not elsewhere classified | not elsewhere classified | heading |
| 0720 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Manufacturing and processing not further defined | not further defined | heading |
| 0721 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Food processing | official | yes |
| 0722 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Materials (glass, paper, plastic and wood) | official | yes |
| 0723 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Textiles (clothes, footwear and leather) | official | yes |
| 0724 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Mining and extraction | official | yes |
| 0729 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Manufacturing and processing not elsewhere classified | not elsewhere classified | heading |
| 0730 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Architecture and construction not further defined | not further defined | heading |
| 0731 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Architecture and town planning | official | yes |
| 0732 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Building and civil engineering | official | yes |
| 0788 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving engineering, manufacturing and construction | inter-disciplinary | heading |
| 0799 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Engineering, manufacturing and construction not elsewhere classified | not elsewhere classified | heading |

## 08 Agriculture, forestry, fisheries and veterinary *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 08 | broad | Agriculture, forestry, fisheries and veterinary | official | heading |
| 080 | narrow | &nbsp;&nbsp;Agriculture, forestry, fisheries and veterinary not further defined | not further defined | heading |
| 081 | narrow | &nbsp;&nbsp;Agriculture | official | yes |
| 082 | narrow | &nbsp;&nbsp;Forestry | official | yes |
| 083 | narrow | &nbsp;&nbsp;Fisheries | official | yes |
| 084 | narrow | &nbsp;&nbsp;Veterinary | official | yes |
| 088 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving agriculture, forestry, fisheries and veterinary | inter-disciplinary | heading |
| 089 | narrow | &nbsp;&nbsp;Agriculture, forestry, fisheries and veterinary not elsewhere classified | not elsewhere classified | heading |
| 0800 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Agriculture, forestry, fisheries and veterinary not further defined | not further defined | heading |
| 0810 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Agriculture not further defined | not further defined | heading |
| 0811 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Crop and livestock production | official | yes |
| 0812 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Horticulture | official | yes |
| 0819 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Agriculture not elsewhere classified | not elsewhere classified | heading |
| 0821 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Forestry | official | yes |
| 0831 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Fisheries | official | yes |
| 0841 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Veterinary | official | yes |
| 0888 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving agriculture, forestry, fisheries and veterinary | inter-disciplinary | heading |
| 0899 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Agriculture, forestry, fisheries and veterinary not elsewhere classified | not elsewhere classified | heading |

## 09 Health and welfare *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 09 | broad | Health and welfare | official | heading |
| 090 | narrow | &nbsp;&nbsp;Health and welfare not further defined | not further defined | heading |
| 091 | narrow | &nbsp;&nbsp;Health | official | yes |
| 092 | narrow | &nbsp;&nbsp;Welfare | official | yes |
| 098 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving health and welfare | inter-disciplinary | heading |
| 099 | narrow | &nbsp;&nbsp;Health and welfare not elsewhere classified | not elsewhere classified | heading |
| 0900 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Health and welfare not further defined | not further defined | heading |
| 0910 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Health not further defined | not further defined | heading |
| 0911 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Dental studies | official | yes |
| 0912 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Medicine | official | yes |
| 0913 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Nursing and midwifery | official | yes |
| 0914 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Medical diagnostic and treatment technology | official | yes |
| 0915 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Therapy and rehabilitation | official | yes |
| 0916 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Pharmacy | official | yes |
| 0917 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Traditional and complementary medicine and therapy | official | yes |
| 0919 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Health not elsewhere classified | not elsewhere classified | heading |
| 0920 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Welfare not further defined | not further defined | heading |
| 0921 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Care of the elderly and of disabled adults | official | yes |
| 0922 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Child care and youth services | official | yes |
| 0923 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Social work and counselling | official | yes |
| 0929 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Welfare not elsewhere classified | not elsewhere classified | heading |
| 0988 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving health and welfare | inter-disciplinary | heading |
| 0999 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Health and welfare not elsewhere classified | not elsewhere classified | heading |

## 10 Services *(heading)*

| Code | Level | Name | Kind | Targetable |
|---|---|---|---|---|
| 10 | broad | Services | official | heading |
| 100 | narrow | &nbsp;&nbsp;Services not further defined | not further defined | heading |
| 101 | narrow | &nbsp;&nbsp;Personal services | official | yes |
| 102 | narrow | &nbsp;&nbsp;Hygiene and occupational health services | official | yes |
| 103 | narrow | &nbsp;&nbsp;Security services | official | yes |
| 104 | narrow | &nbsp;&nbsp;Transport services | official | yes |
| 108 | narrow | &nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving services | inter-disciplinary | heading |
| 109 | narrow | &nbsp;&nbsp;Services not elsewhere classified | not elsewhere classified | heading |
| 1000 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Services not further defined | not further defined | heading |
| 1010 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Personal services not further defined | not further defined | heading |
| 1011 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Domestic services | official | yes |
| 1012 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Hair and beauty services | official | yes |
| 1013 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Hotel, restaurants and catering | official | yes |
| 1014 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Sports | official | yes |
| 1015 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Travel, tourism and leisure | official | yes |
| 1019 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Personal services not elsewhere classified | not elsewhere classified | heading |
| 1020 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Hygiene and occupational health services not further defined | not further defined | heading |
| 1021 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Community sanitation | official | yes |
| 1022 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Occupational health and safety | official | yes |
| 1029 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Hygiene and occupational health services not elsewhere classified | not elsewhere classified | heading |
| 1030 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Security services not further defined | not further defined | heading |
| 1031 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Military and defence | official | yes |
| 1032 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Protection of persons and property | official | yes |
| 1039 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Security services not elsewhere classified | not elsewhere classified | heading |
| 1041 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Transport services | official | yes |
| 1088 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Inter-disciplinary programmes and qualifications involving services | inter-disciplinary | heading |
| 1099 | detailed | &nbsp;&nbsp;&nbsp;&nbsp;Services not elsewhere classified | not elsewhere classified | heading |
