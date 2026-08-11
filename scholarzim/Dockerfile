FROM eclipse-temurin:21-jdk AS build
WORKDIR /app
COPY .mvn/ .mvn/
COPY mvnw pom.xml ./
RUN chmod +x mvnw
COPY src ./src
RUN ./mvnw -B -DskipTests package

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
