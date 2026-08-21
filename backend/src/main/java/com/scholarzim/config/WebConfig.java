package com.scholarzim.config;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Configuration;
import org.springframework.core.io.ClassPathResource;
import org.springframework.core.io.Resource;
import org.springframework.http.CacheControl;
import org.springframework.web.servlet.config.annotation.ResourceHandlerRegistry;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;
import org.springframework.web.servlet.resource.PathResourceResolver;

import java.io.IOException;
import java.util.concurrent.TimeUnit;

@Configuration
public class WebConfig implements WebMvcConfigurer {

    @Value("${scholarzim.assets.long-cache:false}")
    private boolean longCache;

    @Override
    public void addResourceHandlers(ResourceHandlerRegistry registry) {
        CacheControl cache = longCache
                ? CacheControl.maxAge(365, TimeUnit.DAYS).cachePublic().immutable()
                : CacheControl.noStore().mustRevalidate();

        registry.addResourceHandler("/css/**")
                .addResourceLocations("classpath:/static/css/")
                .setCacheControl(cache);

        registry.addResourceHandler("/js/**")
                .addResourceLocations("classpath:/static/js/")
                .setCacheControl(cache);

        registry.addResourceHandler("/images/**")
                .addResourceLocations("classpath:/static/images/")
                .setCacheControl(cache);

        registry.addResourceHandler("/icons/**")
                .addResourceLocations("classpath:/static/icons/")
                .setCacheControl(cache);

        CacheControl swCache = longCache
                ? CacheControl.maxAge(1, TimeUnit.HOURS).cachePublic()
                : CacheControl.noStore().mustRevalidate();

        registry.addResourceHandler("/sw.js")
                .addResourceLocations("classpath:/static/")
                .setCacheControl(swCache);

        registry.addResourceHandler("/manifest.json")
                .addResourceLocations("classpath:/static/")
                .setCacheControl(swCache);

        // ── React SPA (built by frontend/ into static/app) ──
        // Vite fingerprints everything under assets/, so those are immutable.
        registry.addResourceHandler("/app/assets/**")
                .addResourceLocations("classpath:/static/app/assets/")
                .setCacheControl(CacheControl.maxAge(365, TimeUnit.DAYS).cachePublic().immutable());

        // The shell itself must never be cached, or clients pin a stale bundle
        // reference after a deploy. Unresolved paths fall back to index.html so
        // React Router owns client-side routes on a hard refresh.
        registry.addResourceHandler("/app/**")
                .addResourceLocations("classpath:/static/app/")
                .setCacheControl(CacheControl.noStore().mustRevalidate())
                .resourceChain(true)
                .addResolver(new SpaFallbackResourceResolver());
    }

    /**
     * Serves the requested file when it exists, and the SPA shell when it does not —
     * that is what makes a deep link like /app/scholarships/12 survive a refresh.
     * Requests that look like files (anything with an extension) keep 404-ing, so a
     * missing bundle surfaces as a 404 rather than HTML served with the wrong MIME type.
     */
    private static class SpaFallbackResourceResolver extends PathResourceResolver {

        private final Resource shell = new ClassPathResource("static/app/index.html");

        @Override
        protected Resource getResource(String resourcePath, Resource location) throws IOException {
            Resource requested = location.createRelative(resourcePath);
            if (requested.exists() && requested.isReadable()) {
                return requested;
            }
            if (resourcePath.contains(".")) {
                return null;
            }
            return shell.exists() ? shell : null;
        }
    }
}
