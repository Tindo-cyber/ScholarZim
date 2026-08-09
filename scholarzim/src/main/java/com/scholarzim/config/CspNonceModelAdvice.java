package com.scholarzim.config;

import com.scholarzim.security.CspNonceHeaderWriter;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.web.bind.annotation.ControllerAdvice;
import org.springframework.web.bind.annotation.ModelAttribute;

@ControllerAdvice
public class CspNonceModelAdvice {

    @ModelAttribute("cspNonce")
    public String cspNonce(HttpServletRequest request) {
        Object nonce = request.getAttribute(CspNonceHeaderWriter.REQUEST_ATTRIBUTE);
        return nonce != null ? nonce.toString() : null;
    }
}
