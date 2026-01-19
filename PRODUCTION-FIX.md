# 🚨 Production Database Tables Missing - Quick Fix Guide

## Problem
Your production site shows this error:
```
WordPress database error Table 'hajsysmy_WPIMA.i5t_sacga_rooms' doesn't exist
```

**This means the plugin activation didn't create the required database tables.**

---

## ✅ Solution 1: Reactivate Plugin (Recommended)

The latest code on branch `0.2.8` has improved table creation with error logging.

### Steps:
1. **Deploy latest code to production**
   ```bash
   git pull origin 0.2.8
   ```

2. **Go to WordPress Admin → Plugins**

3. **Deactivate "Classic Games Arcade"**

4. **Reactivate "Classic Games Arcade"**

5. **Check error logs** for detailed diagnostics:
   ```bash
   tail -f /home2/hajsysmy/public_html/website_3f5b7e1d/debug.log | grep '\[SACGA\]'
   ```

6. **Look for these messages:**
   - ✅ `[SACGA] Successfully verified table i5t_sacga_rooms` = SUCCESS
   - ❌ `[SACGA] CRITICAL: Failed to create table` = Check next solution

---

## ✅ Solution 2: Manual SQL (If Reactivation Fails)

If plugin reactivation doesn't work (due to permissions or other issues), run the SQL manually.

### Steps:

1. **Open phpMyAdmin** (via cPanel → phpMyAdmin)

2. **Select your database**: `hajsysmy_WPIMA`

3. **Click "SQL" tab**

4. **Run this SQL** but **REPLACE `wp_` with your actual table prefix (e.g., `i5t_`)**:
   ```sql
   -- Replace wp_ with i5t_ for your prefix

   CREATE TABLE IF NOT EXISTS i5t_sacga_rooms (
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

   CREATE TABLE IF NOT EXISTS i5t_sacga_room_players (
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

   CREATE TABLE IF NOT EXISTS i5t_sacga_game_state (
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

5. **Click "Go" to execute**

6. **Verify tables exist:**
   ```sql
   SHOW TABLES LIKE 'i5t_sacga_%';
   ```

   Should show:
   - `i5t_sacga_rooms`
   - `i5t_sacga_room_players`
   - `i5t_sacga_game_state`

---

## 🔍 Why Did This Happen?

Common causes:
1. **Database user lacks `CREATE TABLE` permission**
2. **Plugin activated while errors occurred**
3. **WordPress `dbDelta()` function failed silently**
4. **Plugin files copied manually instead of activated via WP Admin**

---

## ✅ Verify It's Fixed

After creating tables, test room creation:

1. **Visit your game page**
2. **Open browser console** (F12)
3. **Click "Create Room"**
4. **Check console** - should NOT show database errors
5. **Check error logs** - should NOT show "Table doesn't exist"

---

## 🆘 Still Not Working?

Check these:

### 1. Database User Permissions
Run in phpMyAdmin:
```sql
SHOW GRANTS FOR CURRENT_USER();
```
Should include: `GRANT CREATE, INSERT, UPDATE, DELETE, SELECT`

### 2. Error Logs
```bash
grep "SACGA" /home2/hajsysmy/public_html/website_3f5b7e1d/debug.log
```

### 3. WordPress Table Prefix
Verify in `wp-config.php`:
```php
$table_prefix = 'i5t_';  // Should match error messages
```

---

## 📝 Changes in 0.2.8

Branch `0.2.8` includes these fixes:
- ✅ Enhanced table creation with verification
- ✅ Detailed error logging for failed table creation
- ✅ Admin notice if tables are missing
- ✅ Guest token cookie reliability improvements
- ✅ localStorage fallback for blocked cookies

---

## 🎯 Next Steps After Fix

1. ✅ Create a test room to verify functionality
2. ✅ Monitor error logs for 24 hours
3. ✅ Test on different browsers
4. ✅ Verify guest players can join rooms

---

**Need help?** Check the error logs and provide the `[SACGA]` log entries for troubleshooting.
