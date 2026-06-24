<?php
/**
 * Production database credentials — environment override.
 *
 * Copy this file to `db_config.local.php` on the SERVER and fill in the real
 * values. config.php requires it automatically if present and it overrides the
 * local XAMPP defaults. `db_config.local.php` is git-ignored, so production
 * credentials never get committed (same pattern as bakong_config.local.php).
 *
 * On Hostinger/cPanel hosts the username and database name are usually prefixed
 * (e.g. u123456_cafe / u123456_dbcoffee) and $servername stays "localhost".
 */

$servername = "localhost";
$username   = "REPLACE_WITH_DB_USER";
$password   = "REPLACE_WITH_DB_PASSWORD";
$dbname     = "REPLACE_WITH_DB_NAME";
