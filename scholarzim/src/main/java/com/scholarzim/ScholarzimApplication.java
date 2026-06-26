package com.scholarzim;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.scheduling.annotation.EnableScheduling;

@SpringBootApplication
@EnableScheduling
public class ScholarzimApplication {

	public static void main(String[] args) {
		SpringApplication.run(ScholarzimApplication.class, args);
	}

}