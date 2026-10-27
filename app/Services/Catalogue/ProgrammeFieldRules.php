<?php

namespace App\Services\Catalogue;

/**
 * What a programme's name says about its ISCED-F 2013 detailed field, written down so it can be read and argued with.
 *
 * Keyed by the narrow field the catalogue files the programme under today. Each rule is [pattern, detailed code, confidence,
 * basis]; the first pattern that matches the name wins, so the specific rules come before the general ones. The basis is the
 * wording of the UNESCO Institute for Statistics manual (ISCED-F 2013 detailed field descriptions) that the match rests on,
 * and is what the review shows next to a proposal.
 *
 * "high" means the name is about one discipline and the manual lists that discipline under the code. "medium" means the name
 * joins two disciplines, or the manual does not list it by name: it is proposed for a person to confirm, never applied.
 * A name no rule matches stays where it is, in its narrow field, which is a true statement and not a guess.
 *
 * A rule whose code lies in a different narrow field from the one the programme is in is a move; ProgrammeReclassifier never
 * applies a move by itself.
 */
final class ProgrammeFieldRules
{
    /** @return array<string, array<int, array{0: string, 1: string, 2: string, 3: string}>> */
    public static function all(): array
    {
        $h = 'high';
        $m = 'medium';

        return [
            // ---------------------------------------------------------------- 01 Education
            '011' => [
                ['~early childhood|pre-?school|\becd\b~i', '0112', $h, 'Training for pre-school teachers'],
                ['~primary technical~i', '0114', $m, 'Primary technical teaching is teacher training with a subject specialisation'],
                ['~\(primary\)|primary education|junior education~i', '0113', $h, 'Teacher training without subject specialisation (primary)'],
                ['~master of education$|phd in education$|bachelor of education$~i', '0111', $m, 'Education studies with no subject or phase named'],
                ['~educational? (leadership|management|foundations|sociology|psychology|technology|materials)|curriculum|instructional|measurement, assessment|inclusive|needs education|assistive|lifelong|adult and continuing|higher and tertiary|technical and vocational|trainers|educational materials~i', '0111', $h, 'Education science: curricula, educational management, special needs and assessment'],
                ['~(psychology|sociology|youth development|philosophy|heritage|geomatics|health professions|nursing education|midwifery education|theatre arts and entrepreneurial)~i', '0114', $m, 'Teacher training, but the subject named may be education science instead'],
                ['~(education|\bba\b|\bbsc\b).*\b(english|chishona|shona|ndebele|isindebele|mathematics|chemistry|physics|biology|geography|history|music|religious|physical education|agriculture|art and design|food|textile|clothing|metal|business|commerc|stem|computer|information communication|languages|literature|performing arts|theatre|civic|science|social science|sociology|philosophy|psychology|heritage|youth|geomatics|nursing|midwifery|health professions|sport|technology)~i', '0114', $h, 'Teacher training with subject specialisation'],
                ['~with education~i', '0114', $h, 'Teacher training with subject specialisation'],
                ['~secondary~i', '0114', $m, 'Secondary teaching normally needs a subject, but none is named'],
            ],
            // ---------------------------------------------------------------- 02 Arts and humanities
            '021' => [
                ['~film and theatre|theatre, performance~i', '0215', $m, 'Joins film (0211) and theatre (0215)'],
                ['~visual and performing~i', '0215', $m, 'Joins fine arts (0213) and performing arts (0215)'],
                ['~music|performing arts~i', '0215', $h, 'Music and performing arts'],
                ['~fine art~i', '0213', $h, 'Fine arts: painting, sculpture'],
                ['~fashion|industrial design|interior|creative art and industrial~i', '0212', $h, 'Fashion, interior and industrial design'],
                ['~film|television|radio|photograph|multimedia|graphic|print |printing|print$|visual communication|design for print~i', '0211', $h, 'Photography, film, graphic design, printing, multimedia production'],
                ['~commercial design~i', '0211', $m, 'Commercial design is graphic or product design; the name does not say which'],
            ],
            '022' => [
                ['~mass communication~i', '0321', $h, 'Journalism and reporting, mass communication'],
                ['~mobile and wireless communication~i', '0714', $m, 'Telecommunications technology'],
                ['~smart settlement~i', '0731', $m, 'Urban design and planning'],
                ['~nambya and culture|sotho and culture~i', '0232', $m, 'A language and its culture; first-language study is 0232'],
                ['~theolog|religio|divinity|chaplain|catechetical|pastoral~i', '0221', $h, 'Religion and theology'],
                ['~forensic archaeology~i', '0222', $m, 'Archaeology'],
                ['~history|archaeolog|heritage|museum|cultural history|culture and heritage~i', '0222', $h, 'History, archaeology, heritage'],
                ['~philosophy in philosophy|arts in philosophy|phd in philosophy|^ba philosophy$~i', '0223', $h, 'Philosophy and ethics'],
            ],
            '023' => [
                ['~translation|interpret~i', '0231', $h, 'Translation and interpretation'],
                ['~linguistic|literature|literary|african languages|ndebele|shona|tonga|kalanga~i', '0232', $h, 'Linguistics, literature, first and indigenous languages'],
                ['~english~i', '0232', $m, 'English studied as a first language is 0232; as a second language 0231'],
                ['~language~i', '0232', $m, 'Language studies; first or second language is not stated'],
            ],
            // ---------------------------------------------------------------- 03 Social sciences, journalism and information
            '031' => [
                ['~banking and financial economics|development finance~i', '0412', $m, 'Banking and finance'],
                ['~professional accounting and corporate governance~i', '0411', $m, 'Accounting'],
                ['~strategic management and corporate governance~i', '0413', $m, 'Management'],
                ['~management and entrepreneurial development~i', '0413', $m, 'Entrepreneurship and management'],
                ['~property development and estate management~i', '0416', $m, 'Real-estate business'],
                ['~computer (hardware|software) development~i', '0613', $m, 'Software and hardware development belong to computing'],
                ['~public health in monitoring~i', '0912', $m, 'Public health'],
                ['~palliative care~i', '0913', $m, 'Nursing care'],
                ['~counselling psychology|psychology~i', '0313', $h, 'Psychology'],
                ['~counselling~i', '0923', $m, 'Counselling is listed under social work and counselling'],
                ['~industrial strategy and trade policy~i', '0311', $m, 'Economics'],
                ['~economics and finance~i', '0311', $m, 'Joins economics (0311) and finance (0412)'],
                ['~econom|econometric~i', '0311', $h, 'Economics, econometrics'],
                ['~international relations|international studies|politic|peace|governance|public policy|diplomacy|human rights|conflict|negotiation|migration, citizenship|african leadership~i', '0312', $h, 'Political science, international relations, peace and conflict studies, public policy'],
                ['~sociolog|anthropolog|gender|demograph|population|social science|child and family|cultural studies~i', '0314', $h, 'Sociology, anthropology, demography, gender studies'],
                ['~development|rural~i', '0314', $m, 'Development studies is a social science but the manual does not list it by name'],
            ],
            '032' => [
                ['~theatre~i', '0215', $m, 'Theatre and performance (0215) with media technology'],
                ['~african languages~i', '0232', $m, 'Joins African languages (0232) and media studies (0321)'],
                ['~public relations~i', '0414', $m, 'Public relations is listed under marketing and advertising (0414)'],
                ['~print media and digital|publishing~i', '0211', $m, 'Printing and publishing production'],
                ['~health information management~i', '0322', $m, 'Records and information management'],
                ['~library|records|archiv|information science|knowledge management|information and knowledge~i', '0322', $h, 'Library, information, archival studies'],
                ['~journalism|media|communication|broadcast~i', '0321', $h, 'Journalism, mass communication, reporting'],
            ],
            // ---------------------------------------------------------------- 04 Business, administration and law
            '041' => [
                ['~arts management~i', '0413', $m, 'Management applied to the arts'],
                ['~business analytics|financial intelligence~i', '0413', $m, 'Analytics applied to business; the manual lists no matching discipline'],
                ['~church leadership~i', '0221', $m, 'Religion and theology'],
                ['~agritourism|sustainable tourism~i', '1015', $m, 'Travel, tourism and leisure'],
                ['~econometric modelling|industrial strategy and trade policy~i', '0311', $m, 'Economics and econometrics'],
                ['~spatial planning~i', '0731', $h, 'Town and country planning'],
                ['~correctional service~i', '1032', $m, 'Protection of persons and property'],
                ['~food safety and quality~i', '0721', $m, 'Food science and technology'],
                ['~water and sanitation|land, water and waste~i', '1021', $m, 'Community sanitation: waste management, water supply'],
                ['~water resources management~i', '0521', $m, 'Environmental sciences'],
                ['~information management and technology~i', '0613', $m, 'Informatics'],
                ['~health services management|health care management~i', '0413', $h, 'Health administration'],
                ['~purchasing|retail|merchandis|wholesale~i', '0416', $h, 'Purchasing, retailing, wholesaling'],
                ['~secretarial~i', '0415', $h, 'Secretarial and office work'],
                ['~accounting and finance|accounting and management~i', '0411', $m, 'Joins accounting (0411) and finance (0412)'],
                ['~banking and (finance|investment)|bachelor of banking|finance and investment|financial engineering|financial intelligence|risk management and insurance|insurance and risk|pensions|microfinance|\bmsc finance|in finance$|master of commerce in finance|banking$~i', '0412', $h, 'Banking and finance, insurance, investment'],
                ['~risk management~i', '0412', $m, 'Risk management is treated here as insurance and finance'],
                ['~account|audit|bookkeeping|payroll|fiscal|taxation~i', '0411', $h, 'Accounting, auditing, bookkeeping, tax'],
                ['~marketing|advertis|sales|consumer|brand|digital marketing~i', '0414', $h, 'Marketing, advertising, consumer behaviour'],
                ['~supply chain|logistic|human (capital|resource)|personnel|entrepreneur|business administration|business management|business studies|business leadership|public administration|public management|public sector management|management|leadership|strategic|project|office|mba|master of commerce$|business analytics|small business~i', '0413', $h, 'Management and administration, entrepreneurship, human resources'],
                ['~arts management|creative arts management~i', '0413', $m, 'Management applied to the arts'],
                ['~commerce|electronic commerce~i', '0413', $m, 'General commerce'],
            ],
            '042' => [
                ['~law|paralegal|intellectual property~i', '0421', $h, 'Law'],
            ],
            // ---------------------------------------------------------------- 05 Natural sciences, mathematics and statistics
            '051' => [
                ['~biochem|biotechnolog|bioinformatics|bioengineering|molecular~i', '0512', $h, 'Biochemistry, cell technology, genetic engineering'],
                ['~genetics|immunolog|microbiolog|biological sciences~i', '0511', $h, 'Biology, genetics, microbiology'],
            ],
            '052' => [
                ['~chemical and environmental process~i', '0711', $m, 'Chemical process engineering'],
                ['~sustainable tourism~i', '1015', $m, 'Travel, tourism and leisure'],
                ['~environmental science and natural resource~i', '0521', $h, 'Environmental science'],
                ['~environmental engineering|environmental systems engineering~i', '0712', $h, 'Environmental engineering'],
                ['~water resources engineering~i', '0732', $m, 'Hydraulic and civil engineering'],
                ['~earth science|meteorolog|geographic(al)? information|geoinformation|remote sensing|land and geographic|geography and landscape|geography and disaster~i', '0532', $m, 'Earth sciences, geography and meteorology'],
                ['~environmental physics~i', '0533', $m, 'Physics'],
                ['~pollution and environmental chemistry~i', '0531', $m, 'Chemistry'],
                ['~industrial and environmental microbiology~i', '0511', $m, 'Microbiology'],
                ['~architecture and heritage~i', '0731', $m, 'Architecture'],
                ['~risk and disaster|disaster|security and disaster~i', '1032', $m, 'Protection of persons: civil security and disaster management'],
                ['~wildlife|biodiversity|conservation|natural resource|protected area|safari|ecotourism~i', '0522', $h, 'Nature conservation, national parks and wildlife management'],
                ['~environmental|climate|water resources|sustainab|geography~i', '0521', $h, 'Environmental science, ecology'],
            ],
            '053' => [
                ['~forensic auditing~i', '0411', $h, 'Auditing'],
                ['~forensic science~i', '0512', $h, 'Forensic sciences are listed under 0512'],
                ['~forensic archaeology~i', '0222', $m, 'Archaeology'],
                ['~forensic psychology~i', '0313', $m, 'Psychology'],
                ['~laboratory technology~i', '0711', $h, 'Laboratory technology is listed under chemical engineering and processes'],
                ['~meat science|food quality|insect science and food~i', '0721', $m, 'Food science and technology'],
                ['~international trade and diplomacy~i', '0312', $m, 'International relations'],
                ['~child sensitive social policies~i', '0923', $h, 'Social policy is listed under social work and counselling'],
                ['~rehabilitative assistive~i', '0915', $m, 'Therapy and rehabilitation'],
                ['~chemistry|chemical~i', '0531', $h, 'Chemistry'],
                ['~geolog~i', '0532', $h, 'Earth sciences'],
                ['~physics|space science~i', '0533', $h, 'Physics, astronomy, space science'],
            ],
            '054' => [
                ['~statistics and financial mathematics|mathematics and statistics~i', '0541', $m, 'Joins mathematics (0541) and statistics (0542)'],
                ['~actuarial|statistics~i', '0542', $h, 'Statistics, actuarial science, probability'],
                ['~mathemat|operations research~i', '0541', $h, 'Mathematics and operational research'],
            ],
            // ---------------------------------------------------------------- 06 Information and Communication Technologies
            '061' => [
                ['~computer engineering~i', '0714', $h, 'Computer engineering is listed under electronics and automation'],
                ['~mechatronics~i', '0714', $m, 'Control engineering and robotics'],
                ['~digital archival~i', '0322', $m, 'Archival sciences'],
                ['~digital banking|finance and~i', '0412', $m, 'Banking and finance'],
                ['~integrated manufacturing~i', '0715', $m, 'Mechanical engineering and manufacturing'],
                ['~mathematics and computational|computational science|applied mathematics~i', '0541', $m, 'Joins mathematics and computing'],
                ['~artificial intelligence~i', '0619', $h, 'Artificial intelligence is listed under ICT not elsewhere classified'],
                ['~network|cloud|cyber|information security|security and assurance~i', '0612', $h, 'Network design and administration, information technology security'],
                ['~computer science|software|informatics|information systems|information technology|data science|big data|analytics|computer studies|computer systems|computing|business computing|information communication|ict~i', '0613', $h, 'Computer science, software development, informatics'],
            ],
            // ---------------------------------------------------------------- 07 Engineering, manufacturing and construction
            '071' => [
                ['~geotechnical|structural engineering|water resources|sanitary~i', '0732', $m, 'Building and civil engineering'],
                ['~agricultural engineering|irrigation~i', '0811', $m, 'Agricultural engineering is farming-related; the manual has no separate agricultural engineering code'],
                ['~spatial planning~i', '0731', $h, 'Town and country planning'],
                ['~wood machining~i', '0722', $h, 'Wood machining and turning'],
                ['~machine printing~i', '0211', $h, 'Printing'],
                ['~engineering management~i', '0413', $m, 'Management'],
                ['~chemical|process systems~i', '0711', $h, 'Chemical engineering and process technology'],
                ['~environmental~i', '0712', $h, 'Environmental engineering'],
                ['~electrical and electronics~i', '0713', $m, 'Joins electrical (0713) and electronic (0714) engineering'],
                ['~electrical|power|energy|refrigeration|renewable~i', '0713', $h, 'Electrical engineering, energy, refrigeration'],
                ['~lasers and optics~i', '0533', $m, 'Optics is listed under physics'],
                ['~electronic|telecommunication|communication engineering|mechatronic|instrumentation|control|hardware|optic fibre|photonic|automation~i', '0714', $h, 'Electronic engineering, control engineering, telecommunications'],
                ['~automotive|automobile|motor|vehicle|aeronautical|aerospace|auto-|auto |diesel~i', '0716', $h, 'Motor vehicles, ships and aircraft'],
                ['~mechanical|fabrication|welding|boiler|machine shop|machining|millwright|foundry|fitting|plant|production engineering|industrial engineering|hydraulic~i', '0715', $h, 'Mechanical engineering, metal fitting and machining, welding'],
            ],
            '072' => [
                ['~chemical and processing engineering~i', '0711', $m, 'Chemical process engineering'],
                ['~clothing fashion design|textile design~i', '0212', $m, 'Fashion and industrial design'],
                ['~metallurgical engineering|^.*metallurgy$|industrial metallurgy|metallurgical assaying~i', '0715', $m, 'Metallurgical engineering is listed under mechanical engineering and metal trades'],
                ['~mineral and geological exploration~i', '0532', $m, 'Earth sciences'],
                ['~mining|mineral|extractive~i', '0724', $h, 'Mining and mineral technology'],
                ['~food|baking|meat|dairy|grain|agricultural products~i', '0721', $h, 'Food science and technology, baking, meat and dairy processing'],
                ['~wood|plastic|polymer|rubber|fibre~i', '0722', $h, 'Wood, plastics, rubber and paper materials'],
                ['~clothing|textile|fashion|leather~i', '0723', $h, 'Clothing, textiles and leather'],
            ],
            '073' => [
                ['~quantity surveying~i', '0732', $m, 'Construction cost work; surveying (0731) in name only'],
                ['~real estate|valuation|estate management|property~i', '0416', $m, 'Real-estate business'],
                ['~vehicle body building~i', '0716', $m, 'Motor vehicle trades'],
                ['~geomatics engineering~i', '0731', $m, 'Land surveying'],
                ['~architect|urban|regional|landscape|cartograph|survey|geomatics|land administration~i', '0731', $h, 'Architecture, urban planning, surveying'],
                ['~civil|construction|building|carpentry|joinery|plumbing|painting|decorat|draughting|structural~i', '0732', $h, 'Building and civil engineering, construction trades'],
            ],
            // ---------------------------------------------------------------- 08 Agriculture, forestry, fisheries and veterinary
            '081' => [
                ['~rural development and food security~i', '0314', $m, 'Development studies'],
                ['~agroforestry~i', '0821', $m, 'Forestry'],
                ['~engineering|equipment|informatics|environmental monitoring|chemicals~i', '0811', $m, 'Agricultural technology; the manual has no separate code for it'],
                ['~horticulture and landscaping|horticultur~i', '0812', $h, 'Horticulture, gardening, nursery management'],
                ['~agri|crop|animal|livestock|soil|agronom|dairy|rangeland|farm|plant|seed~i', '0811', $h, 'Crop and livestock production, soil science, farm management'],
            ],
            '082' => [
                ['~forest~i', '0821', $h, 'Forestry'],
                ['~wildlife~i', '0522', $m, 'Wildlife management'],
            ],
            '083' => [
                ['~aqua|fisher~i', '0831', $h, 'Aquaculture and fishery science'],
            ],
            '084' => [
                ['~veterinar|animal health~i', '0841', $h, 'Veterinary medicine and science, animal health care'],
            ],
            // ---------------------------------------------------------------- 09 Health and welfare
            '091' => [
                ['~medical physics~i', '0533', $h, 'Medical physics is listed under physics'],
                ['~health services management~i', '0413', $h, 'Health administration'],
                ['~business health care management~i', '0413', $m, 'Management'],
                ['~biomedical informatics|medical analytics and informatics~i', '0613', $m, 'Informatics'],
                ['~animal science and health~i', '0841', $m, 'Animal health care'],
                ['~environmental science (and|in) (health|public health)~i', '1021', $m, 'Community sanitation'],
                ['~counselling and community mental health~i', '0923', $m, 'Counselling'],
                ['~medical geology~i', '0532', $m, 'Earth sciences'],
                ['~clinical biochemistry and toxicology~i', '0512', $m, 'Toxicology is listed under 0512'],
                ['~biomedical engineering~i', '0719', $m, 'Engineering applied to medicine'],
                ['~dent|oral~i', '0911', $h, 'Dental science and surgery'],
                ['~nursing|midwifery|palliative~i', '0913', $h, 'Nursing and midwifery'],
                ['~medical laboratory|radiograph|radiolog|ultrasound|imaging|dosimetry|nuclear medicine|haematolog|densitometry|tomography|biomedical sciences~i', '0914', $h, 'Medical diagnostic and treatment technology'],
                ['~optometry~i', '0914', $m, 'Diagnostic and treatment technology'],
                ['~audiology|speech|occupational therapy|physiotherapy|rehabilitation|dietetics|nutrition|therapy~i', '0915', $h, 'Therapy and rehabilitation; nutrition and dietetics'],
                ['~pharmac|drug discovery|therapeutics~i', '0916', $h, 'Pharmacy'],
                ['~public health|one health|medicine|surgery|obstetrics|gynaecolog|paediatric|forensic medicine|medical~i', '0912', $h, 'Medicine, surgery, public health'],
            ],
            '092' => [
                ['~child~i', '0922', $h, 'Child care and youth services'],
                ['~social work~i', '0923', $h, 'Social work and counselling'],
            ],
            // ---------------------------------------------------------------- 10 Services
            '101' => [
                ['~tourism and hospitality|hospitality and tourism|hotel, catering and tourism|hospitality, tourism~i', '1013', $m, 'Joins hospitality (1013) and tourism (1015)'],
                ['~hospitality|hotel|catering|culinary|cookery~i', '1013', $h, 'Hospitality services, catering, cooking'],
                ['~tourism~i', '1015', $h, 'Travel, tourism and leisure'],
                ['~beauty|hairdress|cosmetolog~i', '1012', $h, 'Hair and beauty services'],
                ['~sport~i', '1014', $h, 'Sports'],
            ],
            '102' => [
                ['~infection prevention~i', '0912', $m, 'Medicine and public health'],
                ['~occupational|safety, health~i', '1022', $h, 'Occupational health and safety'],
                ['~environmental health|sanitation|water supply|port health|disease surveillance|hygiene|inspection|food safety~i', '1021', $h, 'Community sanitation and hygiene'],
            ],
            '103' => [
                ['~conflict prevention~i', '0312', $m, 'Peace and conflict studies'],
                ['~industrial safety~i', '1022', $m, 'Occupational health and safety'],
                ['~defence~i', '1031', $h, 'Defence studies, military science'],
                ['~security|police~i', '1032', $h, 'Protection of persons and property'],
            ],
            '104' => [
                ['~highway and transportation engineering~i', '0732', $m, 'Civil engineering'],
                ['~aviation|transport|logistics|rail|road|mobility~i', '1041', $h, 'Transport services: aircraft operation, shipping, transport studies'],
            ],
        ];
    }
}
