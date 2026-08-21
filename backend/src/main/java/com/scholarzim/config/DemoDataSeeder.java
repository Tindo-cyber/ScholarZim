package com.scholarzim.config;

import com.scholarzim.entity.*;
import com.scholarzim.repository.*;
import com.scholarzim.service.FileStorageService;
import com.scholarzim.util.ApplicationStatus;
import com.scholarzim.util.NotificationType;
import com.scholarzim.util.ProviderOrgType;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.boot.CommandLineRunner;
import org.springframework.core.annotation.Order;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Component;
import org.springframework.transaction.annotation.Transactional;

import java.time.LocalDate;
import java.time.LocalDateTime;
import java.util.List;
import java.util.Map;
import java.util.Set;


@Slf4j
@Component
@Order(2)
public class DemoDataSeeder implements CommandLineRunner {

    private static final String DEMO_PASSWORD = "Password123!";
    private static final String DEMO_APPLICANT_EMAIL = "tanaka.moyo@student.co.zw";

    /** Catalogue titles owned by the seeder — only these are revived on refresh. */
    private static final Set<String> DEMO_TITLES = Set.of(
            "Chevening Scholarships 2026",
            "Higherlife Joshua Nkomo Scholarship",
            "CBZ University Scholarship Programme",
            "Mastercard Foundation Scholars — Africa",
            "DAAD In-Country/In-Region Scholarship",
            "Econet Capernaum Trust STEM Grant",
            "Australia Awards Africa — Zimbabwe",
            "Mandela Rhodes Scholarship");

    private final boolean seedEnabled;
    private final UserRepository userRepository;
    private final RoleRepository roleRepository;
    private final OpportunityRepository opportunityRepository;
    private final ApplicantProfileRepository profileRepository;
    private final ApplicationRepository applicationRepository;
    private final NotificationRepository notificationRepository;
    private final ProviderProfileRepository providerProfileRepository;
    private final SavedScholarshipRepository savedScholarshipRepository;
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
            SavedScholarshipRepository savedScholarshipRepository,
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
        this.savedScholarshipRepository = savedScholarshipRepository;
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
                "BEng Mechanical Engineering — First Class Honours",
                "Mechanical engineer researching renewable energy systems for "
                        + "off-grid communities in Matabeleland.");
        saveProfileWithoutCertificate(simba, "Undergraduate", "Midlands State University",
                "Accounting", "Zimbabwe", "Gweru",
                "A-Level: 12 points",
                "First-year student exploring scholarship opportunities.");

        ensurePendingProviderProfile(pendingProvider, ProviderOrgType.NGO, "NGO-PENDING-2026");

        // Revive demo catalogue rows, then upsert the full set (missing titles backfill).
        refreshExpiredDemoOpportunities();
        log.info("Ensuring demo scholarship catalogue…");
        seedScholarshipCatalogue(chevening, higherlife, cbz, mastercard);

        ensureDemoStudentActivity(tanaka, rudo, simba);

        log.info("Demo data ready (seed accounts available when scholarzim.demo.seed=true).");
        logDemoAccounts();
    }

    private void seedScholarshipCatalogue(
            User chevening, User higherlife, User cbz, User mastercard) {

        saveOpportunity(chevening, "Chevening Scholarships 2026",
                "Fully funded one-year master's degree at any UK university. "
                        + "Covers tuition, living allowance, return flights, and visa costs. "
                        + "Open to Zimbabwean professionals with leadership potential.",
                "British Embassy Harare", "Masters", "Full Scholarship",
                "United Kingdom", LocalDate.now().plusMonths(4),
                "Social Sciences", "Zimbabwe");

        saveOpportunity(higherlife,
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

        saveOpportunity(mastercard,
                "Mastercard Foundation Scholars — Africa",
                "Transformative leadership programme for young Africans. "
                        + "Full scholarship to partner universities including UCT, "
                        + "Makerere, and Ashesi. Includes mentorship and career support.",
                "Mastercard Foundation", "Undergraduate", "Full Scholarship",
                "South Africa", LocalDate.now().plusMonths(5),
                "Computer Science & IT", "Zimbabwe");

        saveOpportunity(chevening, "DAAD In-Country/In-Region Scholarship",
                "German Academic Exchange Service funding for postgraduate studies "
                        + "in engineering and natural sciences at African partner institutions.",
                "DAAD Regional Office", "Masters", "Partial Scholarship",
                "Germany", LocalDate.now().plusMonths(6),
                "Engineering", "Zimbabwe");

        saveOpportunity(higherlife, "Econet Capernaum Trust STEM Grant",
                "STEM bursaries for Zimbabwean students pursuing degrees in "
                        + "technology, engineering, and mathematics at accredited institutions.",
                "Econet Wireless Zimbabwe", "Undergraduate", "Tuition + Accommodation",
                "Zimbabwe", LocalDate.now().plusDays(45),
                "Computer Science & IT", "Zimbabwe");

        saveOpportunity(cbz, "Australia Awards Africa — Zimbabwe",
                "Australian Government scholarships for Zimbabweans to study "
                        + "development-related fields at Australian universities.",
                "Australian Embassy Harare", "Masters", "Full Scholarship",
                "Australia", LocalDate.now().plusMonths(7),
                "Environmental Science", "Zimbabwe");

        saveOpportunity(mastercard,
                "Mandela Rhodes Scholarship",
                "Prestigious scholarship for young African leaders to pursue "
                        + "postgraduate study at any recognised South African institution. "
                        + "Includes tuition, accommodation, and leadership development.",
                "Mandela Rhodes Foundation", "Postgraduate", "Full Scholarship",
                "South Africa", LocalDate.now().plusMonths(3),
                "Law", "Zimbabwe");
    }

    /**
     * Ensures demo students have applications + notifications so dashboards are not empty
     * after a partial seed or Aiven restore that only kept opportunity rows.
     */
    private void ensureDemoStudentActivity(User tanaka, User rudo, User simba) {

        // Only attach demo activity to the seeded catalogue — never to live provider listings.
        List<Opportunity> active = opportunityRepository.search(
                        LocalDate.now(), null, null, null, null, null, null)
                .stream()
                .filter(o -> o.getTitle() != null && DEMO_TITLES.contains(o.getTitle()))
                .toList();
        if (active.isEmpty()) {
            log.warn("No demo-catalogue opportunities available for demo student activity.");
            return;
        }

        Opportunity first = active.get(0);
        Opportunity second = active.size() > 1 ? active.get(1) : first;
        Opportunity third = active.size() > 2 ? active.get(2) : first;

        Application tanakaSubmitted = ensureApplication(tanaka, first, ApplicationStatus.SUBMITTED,
                LocalDateTime.now().minusDays(5));
        ensureApplication(tanaka, second, ApplicationStatus.APPROVED, LocalDateTime.now().minusDays(12));
        Application tanakaRecent = ensureApplication(tanaka, third, ApplicationStatus.SUBMITTED,
                LocalDateTime.now().minusDays(2));

        ensureApplication(rudo, first, ApplicationStatus.APPROVED, LocalDateTime.now().minusDays(20));
        Application rudoSubmitted = ensureApplication(rudo, second, ApplicationStatus.SUBMITTED,
                LocalDateTime.now().minusDays(8));
        ensureApplication(rudo, third, ApplicationStatus.REJECTED, LocalDateTime.now().minusDays(15));

        if (notificationRepository.findByUserOrderByCreatedAtDesc(tanaka).isEmpty()) {
            saveNotification(tanaka, NotificationType.APPLICATION_APPROVED,
                    "Congratulations! Your application for \"" + second.getTitle() + "\" was approved.",
                    "/my-applications", second.getOpportunityId(), false,
                    LocalDateTime.now().minusDays(1));
            saveNotification(tanaka, NotificationType.DEADLINE_REMINDER,
                    "Reminder: \"" + third.getTitle() + "\" closes soon. Apply or update your documents!",
                    "/opportunities", third.getOpportunityId(), false,
                    LocalDateTime.now().minusHours(6));
            saveNotification(tanaka, NotificationType.NEW_OPPORTUNITY,
                    "New opportunity: \"" + first.getTitle() + "\" is now open.",
                    "/opportunities", first.getOpportunityId(), true,
                    LocalDateTime.now().minusDays(3));
        }

        if (notificationRepository.findByUserOrderByCreatedAtDesc(rudo).isEmpty()) {
            saveNotification(rudo, NotificationType.APPLICATION_APPROVED,
                    "Your application for \"" + first.getTitle() + "\" has been approved!",
                    "/my-applications", first.getOpportunityId(), false,
                    LocalDateTime.now().minusDays(2));
            saveNotification(rudo, NotificationType.APPLICATION_REJECTED,
                    "Your application for \"" + third.getTitle() + "\" was not successful this round.",
                    "/my-applications", third.getOpportunityId(), true,
                    LocalDateTime.now().minusDays(10));
        }

        if (notificationRepository.findByUserOrderByCreatedAtDesc(simba).isEmpty()) {
            saveNotification(simba, NotificationType.PROFILE_INCOMPLETE,
                    "Welcome to ScholarZim! Complete your profile and upload your results certificate to apply.",
                    "/applicant/profile", simba.getUserId(), false, LocalDateTime.now().minusHours(1));
        }

        ensureProviderNewApplicationNotification(first.getProvider(), tanaka, tanakaSubmitted);
        ensureProviderNewApplicationNotification(third.getProvider(), tanaka, tanakaRecent);
        ensureProviderNewApplicationNotification(second.getProvider(), rudo, rudoSubmitted);

        ensureSaved(tanaka, first, LocalDateTime.now().minusDays(4));
        ensureSaved(tanaka, second, LocalDateTime.now().minusDays(9));
        ensureSaved(tanaka, third, LocalDateTime.now().minusDays(1));
        ensureSaved(rudo, first, LocalDateTime.now().minusDays(14));
        ensureSaved(rudo, third, LocalDateTime.now().minusDays(6));
    }

    private void ensureSaved(User user, Opportunity opportunity, LocalDateTime savedAt) {
        if (opportunity == null || opportunity.getOpportunityId() == null) {
            return;
        }
        if (savedScholarshipRepository.findByUserAndOpportunityOpportunityId(user, opportunity.getOpportunityId())
                .isPresent()) {
            return;
        }
        SavedScholarship saved = new SavedScholarship();
        saved.setUser(user);
        saved.setOpportunity(opportunity);
        saved.setSavedAt(savedAt);
        savedScholarshipRepository.save(saved);
    }

    private void ensureProviderNewApplicationNotification(User provider, User applicant, Application app) {
        if (provider == null || app == null || app.getApplicationId() == null) {
            return;
        }
        Long relatedId = app.getApplicationId();
        boolean alreadyNotified = notificationRepository.findByUserOrderByCreatedAtDesc(provider).stream()
                .anyMatch(n -> relatedId.equals(n.getRelatedId())
                        && NotificationType.NEW_APPLICATION.equals(n.getType()));
        if (alreadyNotified) {
            return;
        }
        Opportunity opp = app.getOpportunity();
        String title = opp != null ? opp.getTitle() : "a scholarship";
        saveNotification(provider, NotificationType.NEW_APPLICATION,
                applicant.getFullName() + " applied to \"" + title + "\".",
                "/provider/applications/" + app.getApplicationId(),
                app.getApplicationId(), false,
                app.getSubmittedAt() != null ? app.getSubmittedAt() : LocalDateTime.now().minusDays(1));
    }

    private Application ensureApplication(User user, Opportunity opp, String status, LocalDateTime submittedAt) {
        return applicationRepository.findByUserAndOpportunity(user, opp)
                .map(existing -> {
                    if (ApplicationStatus.PENDING.equals(existing.getApplicationStatus())
                            && ApplicationStatus.SUBMITTED.equals(status)) {
                        existing.setApplicationStatus(ApplicationStatus.SUBMITTED);
                        return applicationRepository.save(existing);
                    }
                    return existing;
                })
                .orElseGet(() -> saveApplication(user, opp, status, submittedAt));
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
        user.setEmailVerified(true);
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
        if (storedPath != null) {
            profile.setCertificatePath(storedPath);
            profile.setCertificateFilename(DEMO_CERT_FILENAME);
        }
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
            if (java.nio.file.Files.exists(path)) {
                return storedName;
            }
        } catch (Exception ex) {
            log.warn("Could not create demo provider certificate stub: {}", ex.getMessage());
        }
        return null;
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

        String storedName = "applicant-results-demo-stub.pdf";
        boolean onDisk = false;
        try {
            var path = fileStorageService.resolve(storedName);
            if (!java.nio.file.Files.exists(path)) {
                java.nio.file.Files.writeString(path, "%PDF-1.4 ScholarZim demo results stub");
            }
            onDisk = java.nio.file.Files.exists(path);
        } catch (Exception ex) {
            log.warn("Could not create demo applicant results stub: {}", ex.getMessage());
        }

        // Only persist a path when the file is actually available for hasResultsCertificate().
        if (onDisk) {
            profile.setResultsCertificatePath(storedName);
            profile.setResultsCertificateFilename("demo-results.pdf");
            if (profile.getResultsUploadedAt() == null) {
                profile.setResultsUploadedAt(LocalDateTime.now().minusDays(60));
            }
        }
    }

    /**
     * Roll forward deadlines / revive status for known demo catalogue titles only.
     */
    private void refreshExpiredDemoOpportunities() {

        LocalDate today = LocalDate.now();
        int refreshed = 0;

        for (Opportunity opportunity : opportunityRepository.findAll()) {
            if (opportunity.getTitle() == null || !DEMO_TITLES.contains(opportunity.getTitle())) {
                continue;
            }

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

        Opportunity opp = opportunityRepository.findFirstByTitleOrderByCreatedAtDesc(title)
                .orElseGet(Opportunity::new);
        boolean isNew = opp.getOpportunityId() == null;

        opp.setProvider(provider);
        opp.setTitle(title);
        opp.setDescription(description);
        opp.setProviderName(providerName);
        opp.setEducationLevel(educationLevel);
        opp.setFundingType(fundingType);
        opp.setCountry(country);
        opp.setStatus("ACTIVE");
        if (isNew || opp.getDeadline() == null || opp.getDeadline().isBefore(LocalDate.now())) {
            opp.setDeadline(deadline);
        }
        if (isNew || opp.getCreatedAt() == null) {
            opp.setCreatedAt(LocalDateTime.now().minusDays(
                    (long) (Math.random() * 30) + 5));
        }
        opp.setTargetField(targetField);
        opp.setTargetCountry(targetCountry);
        return opportunityRepository.save(opp);
    }

    private Application saveApplication(User user, Opportunity opp, String status,
                                        LocalDateTime submittedAt) {

        Application app = new Application();
        app.setUser(user);
        app.setOpportunity(opp);
        app.setApplicationStatus(status);
        app.setSubmittedAt(submittedAt);
        return applicationRepository.save(app);
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
