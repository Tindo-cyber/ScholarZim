# Root Dockerfile for platforms (Render) that build from the repo root.
# Builds the React app first, drops its output into the Spring static resources,
# then packages a single jar that serves both the API and the UI.

# ── 1. React frontend ──────────────────────────────────────────────────────
FROM node:22-alpine AS frontend
WORKDIR /build/frontend
COPY frontend/package.json frontend/package-lock.json* ./
# npm ci needs a lockfile; fall back to install so a fresh clone still builds.
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY frontend/ ./
# vite.config.ts writes to ../backend/src/main/resources/static/app,
# which resolves to /build/backend/... inside this stage.
RUN npm run build

# ── 2. Spring backend ──────────────────────────────────────────────────────
FROM eclipse-temurin:21-jdk AS build
WORKDIR /app
COPY backend/.mvn/ .mvn/
COPY backend/mvnw backend/pom.xml ./
RUN chmod +x mvnw
# Warm the dependency cache before the sources land, so a code-only change
# does not re-download the world on every rebuild.
RUN ./mvnw -B dependency:go-offline
COPY backend/src ./src
COPY --from=frontend /build/backend/src/main/resources/static/app ./src/main/resources/static/app
RUN ./mvnw -B -DskipTests package

# ── 3. Runtime ─────────────────────────────────────────────────────────────
FROM eclipse-temurin:21-jre
WORKDIR /app
COPY --from=build /app/target/*.jar app.jar
RUN mkdir -p /app/uploads
EXPOSE 8080
ENV SPRING_PROFILES_ACTIVE=prod
# Render's free tier gives the whole container ~512MB total. -Xmx must leave
# headroom for metaspace, thread stacks, and native/OS memory on top of the
# heap, or the container gets OOM-killed mid-request under any real load —
# SerialGC is also the better choice at this heap size (G1's default overhead
# isn't worth it below ~1-2GB).
ENV JAVA_OPTS="-Xms128m -Xmx350m -XX:MaxMetaspaceSize=128m -XX:+UseSerialGC"
ENTRYPOINT ["sh", "-c", "java $JAVA_OPTS -jar app.jar"]
