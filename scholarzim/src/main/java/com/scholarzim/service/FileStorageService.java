package com.scholarzim.service;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;
import org.springframework.util.StringUtils;
import org.springframework.web.multipart.MultipartFile;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.nio.file.StandardCopyOption;
import java.util.Set;
import java.util.UUID;


@Service
public class FileStorageService {

    private static final Set<String> ALLOWED = Set.of(
            "application/pdf",
            "image/jpeg",
            "image/png",
            "image/webp");

    private final Path root;

    public FileStorageService(@Value("${scholarzim.upload.dir:uploads}") String uploadDir) {
        this.root = Paths.get(uploadDir).toAbsolutePath().normalize();
        try {
            Files.createDirectories(root);
        } catch (IOException ex) {
            throw new IllegalStateException("Could not create upload directory", ex);
        }
    }

    public String store(MultipartFile file, String prefix) throws IOException {

        if (file == null || file.isEmpty()) {
            return null;
        }

        String contentType = file.getContentType();
        if (contentType == null || !ALLOWED.contains(contentType)) {
            throw new IllegalArgumentException("Only PDF and image files are allowed.");
        }

        if (file.getSize() > 5 * 1024 * 1024) {
            throw new IllegalArgumentException("File must be smaller than 5 MB.");
        }

        return writeFile(file, prefix, extOf(file));
    }

    public String storePdf(MultipartFile file, String prefix) throws IOException {

        if (file == null || file.isEmpty()) {
            throw new IllegalArgumentException("Registration certificate (PDF) is required.");
        }

        String contentType = file.getContentType();
        if (!"application/pdf".equals(contentType)) {
            throw new IllegalArgumentException("Registration certificate must be a PDF file.");
        }

        if (file.getSize() > 5 * 1024 * 1024) {
            throw new IllegalArgumentException("Certificate must be smaller than 5 MB.");
        }

        return writeFile(file, prefix, ".pdf");
    }

    public void deleteIfExists(String filename) {

        if (filename == null || filename.isBlank()) {
            return;
        }
        try {
            Path path = resolve(filename);
            Files.deleteIfExists(path);
        } catch (org.springframework.security.access.AccessDeniedException ex) {
            throw ex;
        } catch (IOException ex) {
            throw new IllegalStateException("Could not delete stored file: " + filename, ex);
        }
    }

    private String writeFile(MultipartFile file, String prefix, String ext) throws IOException {

        String filename = prefix + "-" + UUID.randomUUID() + ext;
        Path target = root.resolve(filename);
        Files.copy(file.getInputStream(), target, StandardCopyOption.REPLACE_EXISTING);
        return filename;
    }

    private static String extOf(MultipartFile file) {
        String ext = StringUtils.getFilenameExtension(file.getOriginalFilename());
        return ext != null ? "." + ext : "";
    }

    public Path resolve(String filename) {
        Path resolved = root.resolve(filename).normalize();
        if (!resolved.startsWith(root)) {
            throw new org.springframework.security.access.AccessDeniedException("Invalid file path.");
        }
        return resolved;
    }
}
