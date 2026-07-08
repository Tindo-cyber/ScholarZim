# Root Dockerfile for platforms (Render) that build from the repo root.
# Canonical app lives in scholarzim/
FROM eclipse-temurin:21-jdk AS build
WORKDIR /app
COPY scholarzim/.mvn/ .mvn/
COPY scholarzim/mvnw scholarzim/pom.xml ./
RUN chmod +x mvnw
COPY scholarzim/src ./src
RUN ./mvnw -B -DskipTests package

FROM eclipse-temurin:21-jre
WORKDIR /app
COPY --from=build /app/target/*.jar app.jar
RUN mkdir -p /app/uploads
EXPOSE 8080
ENV SPRING_PROFILES_ACTIVE=prod
ENV JAVA_OPTS="-Xms256m -Xmx512m"
ENTRYPOINT ["sh", "-c", "java $JAVA_OPTS -jar app.jar"]
