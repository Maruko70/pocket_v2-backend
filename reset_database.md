# Database Reset Instructions

If you encounter foreign key constraint errors when dropping tables, use one of these methods:

## Method 1: Rollback All Migrations (Recommended)

```bash
php artisan migrate:rollback --step=100
```

This will rollback all migrations in reverse order, which should handle foreign keys correctly.

## Method 2: Fresh Migration

```bash
php artisan migrate:fresh
```

This will drop all tables and re-run all migrations from scratch.

## Method 3: Manual SQL Reset

If you need to manually reset, run:

```bash
mysql -u your_username -p your_database < backend/drop_all_tables.sql
```

Then run migrations again:

```bash
php artisan migrate
```

## Method 4: Disable Foreign Key Checks (Use with caution)

```sql
SET FOREIGN_KEY_CHECKS = 0;
-- Drop your tables here
SET FOREIGN_KEY_CHECKS = 1;
```

**Note:** The migrations have been updated to handle foreign key constraints properly during rollback.

