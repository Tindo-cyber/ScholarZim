package com.scholarzim.util;

import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;


class ErrorPageSupportTest {

    @Test
    void resolveTypeMapsStatusCodes() {
        assertEquals(ErrorPageSupport.NOT_FOUND, ErrorPageSupport.resolveType(404));
        assertEquals(ErrorPageSupport.PERMISSION_DENIED, ErrorPageSupport.resolveType(403));
        assertEquals(ErrorPageSupport.SERVER_ERROR, ErrorPageSupport.resolveType(500));
        assertEquals(ErrorPageSupport.SERVER_ERROR, ErrorPageSupport.resolveType(null));
    }

    @Test
    void titlesAndMessagesAreUserFriendly() {
        assertEquals("Page not found", ErrorPageSupport.title(ErrorPageSupport.NOT_FOUND));
        assertEquals("Permission denied", ErrorPageSupport.title(ErrorPageSupport.PERMISSION_DENIED));
        assertFalse(ErrorPageSupport.message(ErrorPageSupport.SERVER_ERROR).contains("Exception"));
        assertFalse(ErrorPageSupport.message(ErrorPageSupport.NETWORK).isBlank());
    }

    @Test
    void supportUrlUsesMailto() {
        assertEquals("mailto:support@scholarzim.co.zw?subject=ScholarZim%20Support",
                ErrorPageSupport.supportUrl());
    }
}
