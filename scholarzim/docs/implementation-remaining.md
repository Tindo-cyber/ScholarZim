# FYP implementation — complete

All code-phase items from the FYP professionalization plan are implemented.

## Run demo

```bash
cd scholarzim
docker compose up --build
# or
mvn spring-boot:run -Dspring-boot.run.profiles=demo
```

## Verify

```bash
mvn clean test   # 74 tests
```

See [demo-script.md](demo-script.md) for the viva walkthrough.
