package com.scholarzim.exception;

public class DuplicateApplicationException extends RuntimeException {

    public DuplicateApplicationException(String message) {
        super(message);
    }
}
