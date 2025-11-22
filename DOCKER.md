# Docker Testing Environment

This package includes a Docker-based testing environment that supports testing against multiple database engines (PostgreSQL, MySQL, SQLite) with different primary key types (id, uuid, ulid) and morph types.

## Quick Start

```bash
# Build the containers
docker-compose build

# Install dependencies
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 composer install

# Run tests with default configuration (SQLite, id primary keys)
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 vendor/bin/pest

# Run tests with coverage
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 vendor/bin/pest --coverage
```

## Testing Different Configurations

### PostgreSQL with UUID Primary Keys

```bash
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 bash -c '
export DB_CONNECTION=pgsql
export DB_HOST=postgres
export DB_PORT=5432
export DB_DATABASE=toggl_test
export DB_USERNAME=postgres
export DB_PASSWORD=password
export TOGGL_PRIMARY_KEY_TYPE=uuid
export TOGGL_MORPH_TYPE=uuidMorph
vendor/bin/pest
'
```

### MySQL with ULID Primary Keys

```bash
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 bash -c '
export DB_CONNECTION=mysql
export DB_HOST=mysql
export DB_PORT=3306
export DB_DATABASE=toggl_test
export DB_USERNAME=root
export DB_PASSWORD=password
export TOGGL_PRIMARY_KEY_TYPE=ulid
export TOGGL_MORPH_TYPE=ulidMorph
vendor/bin/pest
'
```

### SQLite with Integer Primary Keys (Default)

```bash
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 bash -c '
export DB_CONNECTION=sqlite
export TOGGL_PRIMARY_KEY_TYPE=id
export TOGGL_MORPH_TYPE=morph
vendor/bin/pest
'
```

## Interactive Shell

Open an interactive shell in the container to run tests selectively:

```bash
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 bash
```

Inside the container:

```bash
# Run specific test file
vendor/bin/pest tests/Feature/MorphKeyIntegrationTest.php

# Run specific test group
vendor/bin/pest --group=integration

# Run with coverage
vendor/bin/pest --coverage --min=90
```

## Configuration Options

### Primary Key Types

- `id` - Auto-incrementing integers (default)
- `uuid` - UUID strings
- `ulid` - ULID strings

### Morph Types

- `morph` - Numeric morph columns (for integer primary keys)
- `uuidMorph` - UUID morph columns
- `ulidMorph` - ULID morph columns

### Database Engines

- `sqlite` - SQLite (in-memory, default)
- `pgsql` - PostgreSQL 18
- `mysql` - MySQL 9

## CI/CD Integration

The GitHub Actions workflow automatically tests all combinations:

- 3 databases × 3 primary key types = 9 test matrix combinations
- All tests must pass across all configurations
- Coverage threshold: 90%

See `.github/workflows/quality-assurance.yaml` for the complete CI configuration.

## Cleanup

```bash
# Stop and remove all containers, networks, volumes
docker-compose down -v

# Remove built images
docker-compose down --rmi all
```

## Troubleshooting

### Permission Issues

If you encounter permission issues with mounted volumes:

```bash
# Run with your user ID
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 vendor/bin/pest
```

### Database Connection Issues

Ensure database services are running:

```bash
# Check service status
docker-compose ps

# View logs
docker-compose logs postgres
docker-compose logs mysql
```

### Clean Start

```bash
# Full cleanup and rebuild
docker-compose down -v
docker-compose build --no-cache
docker-compose run -u $(id -u ${USER}):$(id -g ${USER}) --rm php84 composer install
```
