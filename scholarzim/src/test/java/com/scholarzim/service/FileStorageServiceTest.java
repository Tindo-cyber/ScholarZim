package com.scholarzim.service;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.security.access.AccessDeniedException;

import java.nio.file.Files;
import java.nio.file.Path;

import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

class FileStorageServiceTest {

    @TempDir
    Path tempDir;

    @Test
    void resolveReturnsPathInsideUploadRoot() {
        FileStorageService service = new FileStorageService(tempDir.toString());
        Path resolved = service.resolve("applicant-abc.pdf");
        assertTrue(resolved.startsWith(tempDir));
    }

    @Test
    void resolveRejectsPathTraversal() {
        FileStorageService service = new FileStorageService(tempDir.toString());
        assertThrows(AccessDeniedException.class, () -> service.resolve("../secrets.txt"));
    }

    @Test
    void storePdfEmptyFileThrows() {
        FileStorageService service = new FileStorageService(tempDir.toString());
        MockMultipartFile empty = new MockMultipartFile(
                "certificate", "empty.pdf", "application/pdf", new byte[0]);
        assertThrows(IllegalArgumentException.class, () -> service.storePdf(empty, "test"));
    }

    @Test
    void storePdfNonPdfContentTypeRejected() {
        FileStorageService service = new FileStorageService(tempDir.toString());
        MockMultipartFile image = new MockMultipartFile(
                "certificate", "doc.jpg", "image/jpeg", "jpeg".getBytes());
        assertThrows(IllegalArgumentException.class, () -> service.storePdf(image, "test"));
    }

    @Test
    void storePdfValidPdfStoredUnderPrefix() throws Exception {
        FileStorageService service = new FileStorageService(tempDir.toString());
        MockMultipartFile pdf = new MockMultipartFile(
                "certificate", "cert.pdf", "application/pdf", "%PDF-1.4 test".getBytes());
        String stored = service.storePdf(pdf, "applicant-results-1");
        assertTrue(stored.startsWith("applicant-results-1-"));
        assertTrue(Files.exists(tempDir.resolve(stored)));
    }

    @Test
    void deleteIfExistsRemovesFile() throws Exception {
        FileStorageService service = new FileStorageService(tempDir.toString());
        Files.writeString(tempDir.resolve("to-delete.pdf"), "data");
        service.deleteIfExists("to-delete.pdf");
        assertFalse(Files.exists(tempDir.resolve("to-delete.pdf")));
    }

    @Test
    void deleteIfExistsNullPathIsNoOp() {
        FileStorageService service = new FileStorageService(tempDir.toString());
        service.deleteIfExists(null);
        service.deleteIfExists("  ");
    }
}
