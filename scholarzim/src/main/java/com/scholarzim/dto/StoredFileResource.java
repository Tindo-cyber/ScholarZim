package com.scholarzim.dto;

import org.springframework.core.io.Resource;


public record StoredFileResource(Resource resource, String contentType, String displayName) {
}
