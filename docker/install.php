<?php
/**
 * Non-interactive ClipBucket installer.
 *
 * The shipped installer (upload/cb_install) is a multi-step browser wizard.
 * This script performs the same steps from the command line so a fresh
 * database can be provisioned by docker compose:
 *
 *   structure -> configs -> ads_placements -> countries -> email_templates
 *   -> pages -> user_levels -> categories -> admin account -> config values
 *   -> language phrases
 *
 * It is idempotent: when the config table already holds rows it exits 0.
 */

$basedir  = rtrim(getenv('CB_BASEDIR') ?: '/var/www/html', '/');
$dbHost   = getenv('DB_HOST') ?: 'db';
$dbName   = getenv('DB_NAME') ?: 'clipbucket';
$dbUser   = getenv('DB_USER') ?: 'clipbucket';
$dbPass   = getenv('DB_PASS') ?: '';
$dbPrefix = getenv('DB_PREFIX') ?: 'cb_';
// Base URL stored in the config table. Preview mode overrides it at runtime
// (see the BASE44_PREVIEW_MODE block in includes/common.php).
$baseUrl  = getenv('CB_BASE_URL') ?: 'http://localhost:3000';

function out($msg)
{
    echo '[install] ' . $msg . PHP_EOL;
}

/* ------------------------------------------------------------------ *
 * Connect (the database container may still be starting up)
 * ------------------------------------------------------------------ */
$db = null;
for ($attempt = 1; $attempt <= 60; $attempt++) {
    $try = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if (!$try->connect_errno) {
        $db = $try;
        break;
    }
    out('waiting for database: ' . $try->connect_error);
    sleep(2);
}
if ($db === null) {
    fwrite(STDERR, "[install] database unreachable, giving up\n");
    exit(1);
}
$db->set_charset('utf8');

/* ------------------------------------------------------------------ *
 * The shipped wizard deletes this marker on its final step. While the
 * file exists, check_install('after') (includes/functions.php) forces the
 * site into "closed" mode, so drop it here as well.
 * ------------------------------------------------------------------ */
$installMarker = $basedir . '/files/temp/install.me';
if (file_exists($installMarker)) {
    unlink($installMarker);
    out('removed files/temp/install.me');
}

/* ------------------------------------------------------------------ *
 * Already installed?
 * ------------------------------------------------------------------ */
$res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($dbPrefix) . "config'");
if ($res && $res->num_rows > 0) {
    $res = $db->query('SELECT COUNT(*) AS c FROM `' . $dbPrefix . 'config`');
    $row = $res ? $res->fetch_assoc() : null;
    if ($row && (int) $row['c'] > 0) {
        out('database already installed, nothing to do');
        exit(0);
    }
}

/* ------------------------------------------------------------------ *
 * SQL helpers
 * ------------------------------------------------------------------ */
function split_statements($sql)
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('!/\*.*?\*/!s', '', $sql);

    $statements = array();
    $buffer = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\') {
                if ($i + 1 < $length) {
                    $buffer .= $sql[++$i];
                }
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }
        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }
    return $statements;
}

function run_sql_file($db, $file, $prefix)
{
    if (!file_exists($file)) {
        out('missing SQL file: ' . basename($file));
        return;
    }
    $errors = 0;
    foreach (split_statements(file_get_contents($file)) as $statement) {
        $statement = str_replace('{tbl_prefix}', $prefix, $statement);
        if ($db->query($statement) === false) {
            $errors++;
            out('  ' . basename($file) . ': ' . $db->error . ' :: ' . substr($statement, 0, 120));
        }
    }
    out(basename($file) . ' imported' . ($errors ? ' (' . $errors . ' statement error(s))' : ''));
}

/* ------------------------------------------------------------------ *
 * Schema + data (same order as upload/cb_install/ajax.php)
 * ------------------------------------------------------------------ */
$sqlDir = $basedir . '/cb_install/sql/';
foreach (array(
    'structure.sql',
    'configs.sql',
    'ads_placements.sql',
    'countries.sql',
    'email_templates.sql',
    'pages.sql',
    'user_levels.sql',
    'categories.sql',
    'add_admin.sql',
) as $file) {
    run_sql_file($db, $sqlDir . $file, $dbPrefix);
}

/* ------------------------------------------------------------------ *
 * Database connection file used by the application
 * ------------------------------------------------------------------ */
$template = file_get_contents($basedir . '/cb_install/dbconnect.php');
$dbconnect = str_replace(
    array('_DB_HOST_', '_DB_NAME_', '_DB_USER_', '_DB_PASS_', '_TABLE_PREFIX_'),
    array($dbHost, $dbName, $dbUser, $dbPass, $dbPrefix),
    $template
);
file_put_contents($basedir . '/includes/dbconnect.php', $dbconnect);
copy($basedir . '/cb_install/clipbucket.php', $basedir . '/includes/clipbucket.php');
out('includes/dbconnect.php and includes/clipbucket.php written');

/* ------------------------------------------------------------------ *
 * Basic site configuration (cb_install/modes/adminsettings.php)
 * ------------------------------------------------------------------ */
$now = date('Y-m-d H:i:s');
foreach (array(
    'baseurl'        => $baseUrl,
    'basedir'        => $basedir,
    'version'        => '4.2',
    'type'           => 'RC1',
    'date_installed' => $now,
    'date_updated'   => $now,
    // No mail transport in the sandbox, so new accounts would never be able
    // to confirm their address and signup would dead-end. Re-enable in the
    // admin area once SMTP is configured.
    'email_verification' => '0',
) as $name => $value) {
    $escapedName = $db->real_escape_string($name);
    $escapedValue = $db->real_escape_string($value);
    $db->query("UPDATE `{$dbPrefix}config` SET `value` = '$escapedValue' WHERE `name` = '$escapedName'");
    if ($db->affected_rows === 0) {
        $db->query("INSERT INTO `{$dbPrefix}config` (`name`, `value`) VALUES ('$escapedName', '$escapedValue')");
    }
}

/* ------------------------------------------------------------------ *
 * Admin account: keep the row seeded by add_admin.sql, refresh credentials
 * ------------------------------------------------------------------ */
$adminPass = md5(md5(sha1(sha1(md5('admin')))));
$db->query(
    "UPDATE `{$dbPrefix}users` SET `username` = 'admin', `password` = '$adminPass'," .
    " `email` = 'admin@clipbucket.local', `usr_status` = 'Ok', `ban_status` = 'no' WHERE `userid` = 1"
);
out('admin account ready (admin / admin)');

/* ------------------------------------------------------------------ *
 * Language phrases (cb_install/modes/adminsettings.php -> updateFromPack)
 * ------------------------------------------------------------------ */
$langFile = $basedir . '/includes/langs/en.lang';
if (file_exists($langFile)) {
    $phrases = json_decode(file_get_contents($langFile), true);
    if (is_array($phrases)) {
        $db->query("DELETE FROM `{$dbPrefix}phrases` WHERE `lang_iso` = 'en'");
        $rows = array();
        foreach ($phrases as $key => $text) {
            $rows[] = "('en', '" . $db->real_escape_string($key) . "', '" . $db->real_escape_string($text) . "')";
            if (count($rows) === 250) {
                $db->query("INSERT INTO `{$dbPrefix}phrases` (`lang_iso`, `varname`, `text`) VALUES " . implode(',', $rows));
                $rows = array();
            }
        }
        if ($rows) {
            $db->query("INSERT INTO `{$dbPrefix}phrases` (`lang_iso`, `varname`, `text`) VALUES " . implode(',', $rows));
        }
        out(count($phrases) . ' language phrases imported');
    }
}

out('done');
