package com.scholarzim.config;

import com.scholarzim.entity.*;
import com.scholarzim.repository.*;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.util.NotificationType;
import com.scholarzim.util.ProviderOrgType;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.boot.CommandLineRunner;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Component;
import org.springframework.transaction.annotation.Transactional;

import java.time.LocalDate;
import java.time.LocalDateTime;
import java.util.List;
import java.util.Map;


@Slf4j
@Component
public class DemoDataSeeder implements CommandLineRunner {

    private static final String DEMO_PASSWORD = "Password123!";
    private static final String DEMO_APPLICANT_EMAIL = "tanaka.moyo@student.co.zw";

    private final boolean seedEnabled;
    private final UserRepository userRepository;
    private final RoleRepository roleRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicantProfileRepository profileRepository;
    private final ApplicationRepository applicationRepository;
    private final NotificationRepository notificationRepository;
    private final ProviderProfileRepository providerProfileRepository;
    private final FileStorageService fileStorageService;
    private final PasswordEncoder passwordEncoder;

    private static final String DEMO_CERT_FILENAME = "provider-verification-demo.pdf";

    public DemoDataSeeder(
            @Value("${scholarzim.demo.seed:true}") boolean seedEnabled,
            UserRepository userRepository,
            RoleRepository roleRepository,
            OpportunityRepository opportunityRepository,
            ApplicantProfileRepository profileRepository,
            ApplicationRepository applicationRepository,
            NotificationRepository notificationRepository,
            ProviderProfileRepository providerProfileRepository,
            FileStorageService fileStorageService,
            PasswordEncoder passwordEncoder) {

        this.seedEnabled = seedEnabled;
        this.userRepository = userRepository;
        this.roleRepository = roleRepository;
        this.opportunityRepository = opportunityRepository;
        this.profileRepository = profileRepository;
        this.applicationRepository = applicationRepository;
        this.notificationRepository = notificationRepository;
        this.providerProfileRepository = providerProfileRepository;
        this.fileStorageService = fileStorageService;
        this.passwordEncoder = passwordEncoder;
    }

    @Override
    @Transactional
    public void run(String... args) {

        if (!seedEnabled) {
            log.info("Demo data seeding is disabled (scholarzim.demo.seed=false). "
                    + "No demo logins are created — register a new account or set SCHOLARZIM_DEMO_SEED=true.");
            return;
        }

        Map<String, Role> roles = ensureRoles();
        String hash = passwordEncoder.encode(DEMO_PASSWORD);

        ensureUser("System Administrator", "admin@scholarzim.co.zw",
                "+263 77 000 0001", hash, roles.get("ROLE_ADMIN"));
        User chevening = ensureUser("British Embassy Harare", "scholarships@uk.gov.zw",
                "+263 242 858000", hash, roles.get("ROLE_PROVIDER"));
        User higherlife = ensureUser("Higherlife Foundation", "grants@higherlife.co.zw",
                "+263 242 860000", hash, roles.get("ROLE_PROVIDER"));
        User cbz = ensureUser("CBZ Holdings", "scholarships@cbz.co.zw",
                "+263 242 748000", hash, roles.get("ROLE_PROVIDER"));
        User mastercard = ensureUser("Mastercard Foundation", "africa@mastercardfdn.org",
                "+263 4 000 0000", hash, roles.get("ROLE_PROVIDER"));
        User tanaka = ensureUser("Tanaka Moyo", DEMO_APPLICANT_EMAIL,
                "+263 77 234 5678", hash, roles.get("ROLE_APPLICANT"));
        User rudo = ensureUser("Rudo Chikomo", "rudo.chikomo@student.co.zw",
                "+263 71 345 6789", hash, roles.get("ROLE_APPLICANT"));
        User simba = ensureUser("Simbarashe Ndlovu", "simba.ndlovu@student.co.zw",
                "+263 78 456 7890", hash, roles.get("ROLE_APPLICANT"));

        User pendingProvider = ensurePendingProvider(
                "Zimbabwe Education Trust",
                "pending.provider@org.co.zw",
                "+263 77 999 0000",
                hash,
                roles.get("ROLE_PROVIDER"));

        ensureProviderProfile(chevening, ProviderOrgType.GOVERNMENT, "GOV-UK-001");
        ensureProviderProfile(higherlife, ProviderOrgType.FOUNDATION, "TRUST-HLF-2010");
        ensureProviderProfile(cbz, ProviderOrgType.PRIVATE_COMPANY, "12345/2010");
        ensureProviderProfile(mastercard, ProviderOrgType.FOUNDATION, "MCF-AFR-001");

        saveProfile(tanaka, "Undergraduate", "University of Zimbabwe (UZ)",
                "Computer Science & IT", "Zimbabwe", "Harare",
                "A-Level: 15 points (Maths A, Physics B, Chemistry B)",
                "Final-year Computer Science student passionate about fintech and "
                        + "building solutions for rural Zimbabwe.");
        saveProfile(rudo, "Masters", "National University of Science and Technology (NUST)",
                "Engineering", "Zimbabwe", "Bulawayo",
                "BEng Mechanical Engineering — First Class Honours (GPA 3.8)",
                "Mechanical engineer researching renewable energy systems for "
                        + "off-grid communities in Matabeleland.");
        saveProfileWithoutCertificate(simba, "Undergraduate", "Midlands State University",
                "Accounting", "Zimbabwe", "Gweru",
                "A-Level: 12 points",
                "First-year student exploring scholarship opportunities.");

        ensurePendingProviderProfile(pendingProvider, ProviderOrgType.NGO, "NGO-PENDING-2026");

        if (opportunityRepository.count() > 0) {
            refreshExpiredDemoOpportunities();
            log.info("Demo accounts ready (password: {}). Scholarships already in database.",
                    DEMO_PASSWORD);
            logDemoAccounts();
            return;
        }

        log.info("Seeding scholarship demo data…");
        Opportunity cheveningSch = saveOpportunity(chevening, "Chevening Scholarships 2026",
                "Fully funded one-year master's degree at any UK university. "
                        + "Covers tuition, living allowance, return flights, and visa costs. "
                        + "Open to Zimbabwean professionals with leadership potential.",
                "British Embassy Harare", "Masters", "Full Scholarship",
                "United Kingdom", LocalDate.now().plusMonths(4),
                "Social Sciences", "Zimbabwe");

        Opportunity higherlifeSch = saveOpportunity(higherlife,
                "Higherlife Joshua Nkomo Scholarship",
                "Comprehensive support for academically gifted Zimbabwean students "
                        + "from disadvantaged backgrounds. Covers tuition, boarding, "
                        + "books, and a monthly allowance throughout university.",
                "Higherlife Foundation", "Undergraduate", "Full Scholarship",
                "Zimbabwe", LocalDate.now().plusMonths(2),
                "Natural Sciences", "Zimbabwe");

        saveOpportunity(cbz, "CBZ University Scholarship Programme",
                "Annual scholarship for top-performing Zimbabwean undergraduates "
                        + "in banking, finance, accounting, and economics. "
                        + "Includes internship placement at CBZ Bank.",
                "CBZ Holdings", "Undergraduate", "Tuition Only",
                "Zimbabwe", LocalDate.now().plusMonths(3),
                "Business & Finance", "Zimbabwe");

        Opportunity mastercardSch = saveOpportunity(mastercard,
                "Mastercard Foundation Scholars — Africa",
                "Transformative leadership programme for young Africans. "
                        + "Full scholarship to partner universities including UCT, "
                        + "Makerere, and Ashesi. Includes mentorship and career support.",
                "Mastercard Foundation", "Undergraduate", "Full Scholarship",
                "South Africa", LocalDate.now().plusMonths(5),
                "Computer Science & IT", "Zimbabwe");

        Opportunity daadSch = saveOpportunity(chevening, "DAAD In-Country/In-Region Scholarship",
                "German Academic Exchange Service funding for postgraduate studies "
                        + "in engineering and natural sciences at African partner institutions.",
                "DAAD Regional Office", "Masters", "Partial Scholarship",
                "Germany", LocalDate.now().plusMonths(6),
                "Engineering", "Zimbabwe");

        Opportunity econetSch = saveOpportunity(higherlife, "Econet Capernaum Trust STEM Grant",
                "STEM bursaries for Zimbabwean students pursuing degrees in "
                        + "technology, engineering, and mathematics at accredited institutions.",
                "Econet Wireless Zimbabwe", "Undergraduate", "Tuition + Accommodation",
                "Zimbabwe", LocalDate.now().plusDays(45),
                "Computer Science & IT", "Zimbabwe");

        Opportunity ausSch = saveOpportunity(cbz, "Australia Awards Africa — Zimbabwe",
                "Australian Government scholarships for Zimbabweans to study "
                        + "development-related fields at Australian universities.",
                "Australian Embassy Harare", "Masters", "Full Scholarship",
                "Australia", LocalDate.now().plusMonths(7),
                "Environmental Science", "Zimbabwe");

        Opportunity mandelaSch = saveOpportunity(mastercard,
                "Mandela Rhodes Scholarship",
                "Prestigious scholarship for young African leaders to pursue "
                        + "postgraduate study at any recognised South African institution. "
                        + "Includes tuition, accommodation, and leadership development.",
                "Mandela Rhodes Foundation", "Postgraduate", "Full Scholarship",
                "South Africa", LocalDate.now().plusMonths(3),
                "Law", "Zimbabwe");

        saveApplication(tanaka, cheveningSch, "PENDING", LocalDateTime.now().minusDays(5));
        saveApplication(tanaka, econetSch, "APPROVED", LocalDateTime.now().minusDays(12));
        saveApplication(tanaka, higherlifeSch, "PENDING", LocalDateTime.now().minusDays(2));

        saveApplication(rudo, mandelaSch, "APPROVED", LocalDateTime.now().minusDays(20));
        saveApplication(rudo, daadSch, "PENDING", LocalDateTime.now().minusDays(8));
        saveApplication(rudo, ausSch, "REJECTED", LocalDateTime.now().minusDays(15));

        saveNotification(tanaka, NotificationType.APPLICATION_APPROVED,
                "Congratulations! Your application for Econet Capernaum Trust STEM Grant was approved.",
                "/my-applications", econetSch.getOpportunityId(), false,
                LocalDateTime.now().minusDays(1));

        saveNotification(tanaka, NotificationType.DEADLINE_REMINDER,
                "Reminder: Higherlife Joshua Nkomo Scholarship closes in 2 months. Apply soon!",
                "/opportunities", higherlifeSch.getOpportunityId(), false,
                LocalDateTime.now().minusHours(6));

        saveNotification(tanaka, NotificationType.NEW_OPPORTUNITY,
                "New opportunity: Mastercard Foundation Scholars — Africa is now open.",
                "/opportunities", mastercardSch.getOpportunityId(), true,
                LocalDateTime.now().minusDays(3));

        saveNotification(rudo, NotificationType.APPLICATION_APPROVED,
                "Your Mandela Rhodes Scholarship application has been approved!",
                "/my-applications", mandelaSch.getOpportunityId(), false,
                LocalDateTime.now().minusDays(2));

        saveNotification(rudo, NotificationType.APPLICATION_REJECTED,
                "Your Australia Awards Africa application was not successful this round.",
                "/my-applications", ausSch.getOpportunityId(), true,
                LocalDateTime.now().minusDays(10));

        saveNotification(simba, NotificationType.PROFILE_INCOMPLETE,
                "Welcome to ScholarZim! Complete your profile and upload your results certificate to apply.",
                "/applicant/profile", simba.getUserId(), false, LocalDateTime.now().minusHours(1));

        log.info("Demo data seeded. Login with any account using password: {}", DEMO_PASSWORD);
        logDemoAccounts();
    }

    private void logDemoAccounts() {
        log.info("  Admin:            admin@scholarzim.co.zw");
        log.info("  Provider (active): scholarships@uk.gov.zw");
        log.info("  Provider (pending): pending.provider@org.co.zw");
        log.info("  Applicant (cert):  {}", DEMO_APPLICANT_EMAIL);
        log.info("  Applicant (no cert): simba.ndlovu@student.co.zw");
    }

    private User ensurePendingProvider(String name, String email, String phone,
                                       String hash, Role role) {
        User user = userRepository.findByEmail(email).orElseGet(() -> {
            User created = new User();
            created.setEmail(email);
            return created;
        });
        user.setFullName(name);
        user.setPhone(phone);
        user.setPasswordHash(hash);
        user.setRole(role);
        user.setAccountStatus("PENDING_APPROVAL");
        return userRepository.save(user);
    }

    private void ensurePendingProviderProfile(User user, String organisationType,
                                              String registrationNumber) {
        if (providerProfileRepository.findByUser(user).isPresent()) {
            return;
        }
        String storedPath = ensureDemoCertificateOnDisk();
        ProviderProfile profile = new ProviderProfile();
        profile.setUser(user);
        profile.setOrganisationType(organisationType);
        profile.setRegistrationNumber(registrationNumber);
        profile.setCertificatePath(storedPath);
        profile.setCertificateFilename(DEMO_CERT_FILENAME);
        profile.setSubmittedAt(LocalDateTime.now().minusDays(2));
        providerProfileRepository.save(profile);
    }

    private void saveProfileWithoutCertificate(User user, String level, String institution,
                                                 String field, String country, String province,
                                                 String results, String bio) {
        ApplicantProfile profile = profileRepository.findByUser(user).orElseGet(() -> {
            ApplicantProfile created = new ApplicantProfile();
            created.setUser(user);
            return created;
        });
        profile.setEducationLevel(level);
        profile.setInstitutionName(institution);
        profile.setFieldOfStudy(field);
        profile.setCountry(country);
        profile.setProvince(province);
        profile.setAcademicResults(results);
        profile.setBiography(bio);
        profile.setResultsCertificatePath(null);
        profile.setResultsCertificateFilename(null);
        profile.setResultsUploadedAt(null);
        profileRepository.save(profile);
    }

    private Map<String, Role> ensureRoles() {

        List<String[]> roleDefs = List.of(
                new String[]{"ROLE_APPLICANT", "Scholarship applicant"},
                new String[]{"ROLE_PROVIDER", "Scholarship provider"},
                new String[]{"ROLE_ADMIN", "Platform administrator"}
        );

        Map<String, Role> roles = new java.util.HashMap<>();

        for (String[] def : roleDefs) {
            Role role = roleRepository.findByRoleName(def[0])
                    .orElseGet(() -> {
                        Role r = new Role();
                        r.setRoleName(def[0]);
                        r.setDescription(def[1]);
                        return roleRepository.save(r);
                    });
            roles.put(def[0], role);
        }

        return roles;
    }

    private User ensureUser(String name, String email, String phone,
                          String hash, Role role) {

        User user = userRepository.findByEmail(email).orElseGet(() -> {
            User created = new User();
            created.setEmail(email);
            return created;
        });

        user.setFullName(name);
        user.setPhone(phone);
        user.setPasswordHash(hash);
        user.setRole(role);
        user.setAccountStatus("ACTIVE");
        return userRepository.save(user);
    }

    private void ensureProviderProfile(User user, String organisationType, String registrationNumber) {

        if (providerProfileRepository.findByUser(user).isPresent()) {
            return;
        }

        String storedPath = ensureDemoCertificateOnDisk();

        ProviderProfile profile = new ProviderProfile();
        profile.setUser(user);
        profile.setOrganisationType(organisationType);
        profile.setRegistrationNumber(registrationNumber);
        profile.setCertificatePath(storedPath);
        profile.setCertificateFilename(DEMO_CERT_FILENAME);
        profile.setSubmittedAt(LocalDateTime.now().minusDays(30));
        profile.setReviewedAt(LocalDateTime.now().minusDays(29));
        profile.setReviewedBy("admin@scholarzim.co.zw");
        providerProfileRepository.save(profile);
    }

    private String ensureDemoCertificateOnDisk() {

        String storedName = "provider-verification-demo-stub.pdf";
        try {
            var path = fileStorageService.resolve(storedName);
            if (!java.nio.file.Files.exists(path)) {
                java.nio.file.Files.writeString(path, "%PDF-1.4 ScholarZim demo certificate stub");
            }
        } catch (Exception ex) {
            log.warn("Could not create demo provider certificate stub: {}", ex.getMessage());
        }
        return storedName;
    }

    private void saveProfile(User user, String level, String institution,
                             String field, String country, String province,
                             String results, String bio) {

        ApplicantProfile profile = profileRepository.findByUser(user).orElseGet(() -> {
            ApplicantProfile created = new ApplicantProfile();
            created.setUser(user);
            return created;
        });

        profile.setEducationLevel(level);
        profile.setInstitutionName(institution);
        profile.setFieldOfStudy(field);
        profile.setCountry(country);
        profile.setProvince(province);
        profile.setAcademicResults(results);
        profile.setBiography(bio);
        ensureApplicantResultsCertificate(profile);
        profileRepository.save(profile);
    }

    private void ensureApplicantResultsCertificate(ApplicantProfile profile) {

        if (profile.getResultsCertificatePath() != null && !profile.getResultsCertificatePath().isBlank()) {
            return;
        }

        String storedName = "applicant-results-demo-stub.pdf";
        try {
            var path = fileStorageService.resolve(storedName);
            if (!java.nio.file.Files.exists(path)) {
                java.nio.file.Files.writeString(path, "%PDF-1.4 ScholarZim demo results stub");
            }
        } catch (Exception ex) {
            log.warn("Could not create demo applicant results stub: {}", ex.getMessage());
        }

        profile.setResultsCertificatePath(storedName);
        profile.setResultsCertificateFilename("demo-results.pdf");
        profile.setResultsUploadedAt(LocalDateTime.now().minusDays(60));
    }

    /**
     * Persistent dev databases keep old rows but the seeder skips re-insertion.
     * Roll forward deadlines so browse/search still returns active listings.
     */
    private void refreshExpiredDemoOpportunities() {

        LocalDate today = LocalDate.now();
        int refreshed = 0;

        for (Opportunity opportunity : opportunityRepository.findAll()) {
            boolean inactive = opportunity.getStatus() == null
                    || !"ACTIVE".equalsIgnoreCase(opportunity.getStatus());
            boolean expired = opportunity.getDeadline() != null
                    && opportunity.getDeadline().isBefore(today);

            if (!inactive && !expired) {
                continue;
            }

            if (inactive) {
                opportunity.setStatus("ACTIVE");
            }
            if (expired) {
                opportunity.setDeadline(today.plusMonths(2L + (refreshed % 5))
                        .plusDays(refreshed * 11L % 28));
            }
            opportunityRepository.save(opportunity);
            refreshed++;
        }

        if (refreshed > 0) {
            log.info("Refreshed {} demo scholarship listing(s) with future deadlines.", refreshed);
        }
    }

    private Opportunity saveOpportunity(User provider, String title, String description,
                                        String providerName, String educationLevel,
                                        String fundingType, String country,
                                        LocalDate deadline, String targetField,
                                        String targetCountry) {

        Opportunity opp = new Opportunity();
        opp.setProvider(provider);
        opp.setTitle(title);
        opp.setDescription(description);
        opp.setProviderName(providerName);
        opp.setEducationLevel(educationLevel);
        opp.setFundingType(fundingType);
        opp.setCountry(country);
        opp.setDeadline(deadline);
        opp.setStatus("ACTIVE");
        opp.setCreatedAt(LocalDateTime.now().minusDays(
                (long) (Math.random() * 30) + 5));
        opp.setTargetField(targetField);
        opp.setTargetCountry(targetCountry);
        return opportunityRepository.save(opp);
    }

    private void saveApplication(User user, Opportunity opp, String status,
                                 LocalDateTime submittedAt) {

        Application app = new Application();
        app.setUser(user);
        app.setOpportunity(opp);
        app.setApplicationStatus(status);
        app.setSubmittedAt(submittedAt);
        applicationRepository.save(app);
    }

    private void saveNotification(User user, String type, String message,
                                  String link, Long relatedId, boolean read,
                                  LocalDateTime createdAt) {

        Notification n = new Notification();
        n.setUser(user);
        n.setType(type);
        n.setMessage(message);
        n.setLink(link);
        n.setRelatedId(relatedId);
        n.setRead(read);
        n.setCreatedAt(createdAt);
        notificationRepository.save(n);
    }
}
