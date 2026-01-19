# Classic Games Arcade - Database Table Creation Troubleshooting

## Issue: Database Tables Not Created on Plugin Activation

This document provides comprehensive troubleshooting steps for when database tables fail to create during plugin activation, particularly in production environments.

## Overview

The plugin requires three database tables:
- `{prefix}sacga_rooms`
- `{prefix}sacga_room_players`
- `{prefix}sacga_game_state`

## Automatic Recovery Features

**As of version 0.2.7+**, the plugin includes automatic table creation:

1. **Runtime Verification**: Tables are checked before each room operation
2. **Automatic Creation**: Missing tables are created automatically when needed
3. **Admin Notices**: Clear warnings appear in WordPress admin if tables are missing
4. **One-Click Fix**: Admin notice includes a "Create Tables Now" button

## Troubleshooting Steps

### Step 1: Check Current Status

1. Log into WordPress admin
2. Look for the "Classic Games Arcade: Database Tables Missing" notice
3. Review which tables are missing

### Step 2: Try Automatic Creation

Click the **"Create Tables Now"** button in the admin notice. This will:
- Attempt to create tables using dbDelta
- Fall back to manual SQL creation if dbDelta fails
- Log detailed diagnostic information

### Step 3: Check Error Logs

Enable WordPress debugging and check logs:

```php
// Add to wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check `wp-content/debug.log` for messages starting with `[SACGA]`.

### Step 4: Verify Database Permissions

The database user must have CREATE TABLE privileges:

```sql
-- Check privileges
SHOW GRANTS FOR 'your_db_user'@'localhost';

-- Should include:
-- GRANT CREATE ON `database_name`.* TO 'user'@'host';
```

### Step 5: Manual Table Creation

If automatic creation fails, create tables manually via phpMyAdmin or MySQL client:

```sql
-- Replace {prefix} with your actual table prefix (e.g., wp_ or i5t_)

CREATE TABLE {prefix}sacga_rooms (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  room_code varchar(6) NOT NULL,
  game_id varchar(50) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'lobby',
  settings longtext,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY room_code (room_code),
  KEY status (status),
  KEY expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}sacga_room_players (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  room_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  guest_token varchar(36) DEFAULT NULL,
  display_name varchar(100) NOT NULL,
  seat_position tinyint(3) unsigned NOT NULL,
  is_ai tinyint(1) NOT NULL DEFAULT 0,
  ai_difficulty varchar(20) DEFAULT NULL,
  joined_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY room_id (room_id),
  KEY user_id (user_id),
  KEY guest_token (guest_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}sacga_game_state (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  room_id bigint(20) unsigned NOT NULL,
  state_version int(11) NOT NULL DEFAULT 1,
  current_turn int(11) NOT NULL DEFAULT 0,
  game_data longtext NOT NULL,
  etag varchar(32) NOT NULL,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY room_id (room_id),
  KEY current_turn (current_turn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Step 6: Check Environment Differences

Compare test and production environments:

**PHP Version**
```bash
php -v
```

**MySQL/MariaDB Version**
```sql
SELECT VERSION();
```

**WordPress Database Settings**
```php
// Check in wp-config.php
DB_NAME
DB_USER
DB_HOST
DB_CHARSET
DB_COLLATE
```

## Schema Migration for Existing Installations

If you have an existing installation with the old schema (before version 0.2.7), the plugin will automatically migrate your database schema. The migration adds:

- `state_version` column for optimistic locking
- `current_turn` column with index for performance
- Renames `state` column to `game_data` for clarity

**Automatic Migration**: Runs automatically on plugin activation or first use.

**Manual Migration** (if automatic fails):

```sql
-- For existing installations with old schema
ALTER TABLE {prefix}sacga_game_state CHANGE COLUMN `state` `game_data` longtext NOT NULL;
ALTER TABLE {prefix}sacga_game_state ADD COLUMN `state_version` int(11) NOT NULL DEFAULT 1 AFTER `room_id`;
ALTER TABLE {prefix}sacga_game_state ADD COLUMN `current_turn` int(11) NOT NULL DEFAULT 0 AFTER `state_version`;
ALTER TABLE {prefix}sacga_game_state ADD KEY `current_turn` (`current_turn`);
```

## Common Issues and Solutions

### Issue: Unknown column 'state_version' or 'game_data'

**Cause**: Database schema is outdated (pre-0.2.7)

**Solution**:
1. Deactivate and reactivate the plugin (runs migration automatically)
2. Or use the "Create Tables Now" button in admin
3. Or run the manual migration SQL above

### Issue: dbDelta Silently Fails

**Cause**: dbDelta requires very specific SQL formatting

**Solution**: The plugin now includes a fallback that uses direct SQL queries if dbDelta fails. Check error logs for details.

### Issue: Missing PRIMARY KEY

**Cause**: All tables require a PRIMARY KEY for dbDelta to work

**Solution**: Fixed in version 0.2.7+. All tables include proper PRIMARY KEY definitions.

### Issue: Database Permission Denied

**Cause**: Production database user lacks CREATE privilege

**Solution**: Grant CREATE privileges or have host administrator create tables manually.

### Issue: Charset/Collation Mismatch

**Cause**: Production uses different charset than test environment

**Solution**: Plugin uses `$wpdb->get_charset_collate()` to match WordPress settings automatically.

### Issue: Strict Mode Conflicts

**Cause**: MySQL strict mode rejects certain SQL syntax

**Solution**: Plugin uses compatible SQL syntax that works with strict mode enabled.

## Diagnostic Information

When reporting issues, include:

1. **WordPress Version**: From Admin → Dashboard
2. **PHP Version**: From admin notice or `phpinfo()`
3. **MySQL Version**: From admin notice or `SELECT VERSION();`
4. **Table Prefix**: From admin notice
5. **Error Logs**: Relevant `[SACGA]` messages from debug.log
6. **Database Permissions**: Output of `SHOW GRANTS`

## Prevention

The plugin now includes runtime verification:

1. Tables are checked before each room creation
2. Missing tables trigger automatic creation
3. Errors are logged for debugging
4. Admin notices guide administrators to fix issues

This ensures that even if activation hook fails, tables will be created when the plugin is first used.

## Support

If issues persist after following this guide:

1. Check the plugin's GitHub issues
2. Include diagnostic information from the admin notice
3. Attach relevant error log entries
4. Describe differences between test and production environments

## Technical Details

### Why dbDelta is Problematic

WordPress's `dbDelta()` function is notoriously finicky:

- Requires exactly two spaces before PRIMARY KEY
- Sensitive to indentation and spacing
- Silently fails on syntax it doesn't like
- Different behavior across WordPress versions

### Our Solution

1. **Strictly formatted SQL**: Follows all dbDelta requirements
2. **Fallback mechanism**: Uses direct SQL if dbDelta fails
3. **Runtime verification**: Doesn't rely solely on activation hook
4. **Comprehensive logging**: Tracks every step of table creation

### File References

- **Main plugin**: `classic-games-arcade.php:301-416` (create_tables method)
- **Room Manager**: `includes/engine/class-room-manager.php:18-34` (ensure_tables method)
- **Admin notice**: `classic-games-arcade.php:421-487` (check_tables_admin_notice method)
