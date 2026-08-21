package com.scholarzim.support;

import com.scholarzim.config.TestRoleBootstrap;
import com.scholarzim.repository.ApplicationRepository;
import com.scholarzim.repository.ApplicantProfileRepository;
import com.scholarzim.repository.OpportunityRepository;
import com.scholarzim.repository.ProviderProfileRepository;
import com.scholarzim.repository.RoleRepository;
import com.scholarzim.repository.UserRepository;
import com.scholarzim.service.FileStorageService;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.context.annotation.Import;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;

@SpringBootTest
@AutoConfigureMockMvc
@ActiveProfiles("test")
@Import(TestRoleBootstrap.class)
public abstract class MvcIntegrationTestBase {

    @Autowired
    protected MockMvc mockMvc;

    @Autowired
    protected UserRepository userRepository;

    @Autowired
    protected RoleRepository roleRepository;

    @Autowired
    protected OpportunityRepository opportunityRepository;

    @Autowired
    protected ApplicationRepository applicationRepository;

    @Autowired
    protected ApplicantProfileRepository applicantProfileRepository;

    @Autowired
    protected ProviderProfileRepository providerProfileRepository;

    @Autowired
    protected FileStorageService fileStorageService;

    protected TestDataFactory data;

    @BeforeEach
    void baseSetUp() {
        data = new TestDataFactory(
                userRepository,
                roleRepository,
                opportunityRepository,
                applicationRepository,
                applicantProfileRepository,
                providerProfileRepository,
                fileStorageService);
    }

    @AfterEach
    void baseTearDown() {
        MvcTestSupport.clearSecurityContext();
    }
}
