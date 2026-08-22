# FYP implementation — complete

All code-phase items from the FYP professionalization plan are implemented.

## Run demo

```bash
cd ScholarZim
docker compose up --build
# or
php artisan migrate --seed && php artisan serve
```

## Verify

```bash
php artisan test   # 29 tests
```

See [demo-script.md](demo-script.md) for the viva walkthrough.
