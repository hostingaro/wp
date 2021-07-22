#!/usr/bin/env php
<?php

$path = isset($_SERVER['argv']['1']) ? $path = strtolower($_SERVER['argv']['1']) : $path = null;
$wpusername = isset($_SERVER['argv']['2']) ? $_SERVER['argv']['2'] : 'admin';
$wppassword = isset($_SERVER['argv']['3']) ? $_SERVER['argv']['3'] : '123456789';
$wpsite_url = isset($_SERVER['argv']['4']) ? $_SERVER['argv']['4'] : null;

function change_perfix($connection,$from,$to){
  $sql = "ALTER TABLE ".$from."commentmeta` RENAME `".$to."commentmeta`; ALTER TABLE ".$from."comments` RENAME `".$to."comments`; ALTER TABLE ".$from."links` RENAME `".$to."links`; ALTER TABLE ".$from."options` RENAME `".$to."options`; ALTER TABLE ".$from."postmeta` RENAME `".$to."postmeta`; ALTER TABLE ".$from."posts` RENAME `".$to."posts`; ALTER TABLE ".$from."termmeta` RENAME `".$to."termmeta`; ALTER TABLE ".$from."terms` RENAME `".$to."terms`; ALTER TABLE ".$from."term_relationships` RENAME `".$to."term_relationships`; ALTER TABLE ".$from."term_taxonomy` RENAME `".$to."term_taxonomy`; ALTER TABLE ".$from."usermeta` RENAME `".$to."usermeta`; ALTER TABLE `".$from."users` RENAME `".$to."users`;";
  $connection->query($sql);
}

function generateRandomString($length = 5) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function security_wp_($dbname,$dbuser,$dbpass,$dbhost $security,$domain,$connection){
$security_perfix = generateRandomString(10);
$perfix = generateRandomString();
$config = "<?php
/**
 * Custom WordPress configurations on \"wp-config.php\" file.
 *
 * This file has the following configurations: MySQL settings, Table Prefix, Secret Keys, WordPress Language, ABSPATH and more.
 * For more information visit {@link https://codex.wordpress.org/Editing_wp-config.php Editing wp-config.php} Codex page.
 *
 * @package WordPress
 */


/* MySQL settings */
define( 'DB_NAME',     '".$dbname."' );
define( 'DB_USER',     '".$dbuser."' );
define( 'DB_PASSWORD', '".$dbpass."' );
define( 'DB_HOST',     '".$dbhost."' );
define( 'DB_CHARSET',  'utf8mb4' );


/* Authentication Unique Keys and Salts. */
define('AUTH_KEY',         '".$security['AUTH_KEY']."');
define('SECURE_AUTH_KEY',  '".$security['SECURE_AUTH_KEY']."');
define('LOGGED_IN_KEY',    '".$security['LOGGED_IN_KEY']."');
define('NONCE_KEY',        '".$security['NONCE_KEY']."');
define('AUTH_SALT',        '".$security['AUTH_SALT']."');
define('SECURE_AUTH_SALT', '".$security['SECURE_AUTH_SALT']."');
define('LOGGED_IN_SALT',   '".$security['LOGGED_IN_SALT']."');
define('NONCE_SALT',       '".$security['NONCE_SALT']."');


/* SSL */
define( 'FORCE_SSL_LOGIN', true );
define( 'FORCE_SSL_ADMIN', true );


/* PHP Memory */
define( 'WP_MEMORY_LIMIT', '128M' );
define( 'WP_MAX_MEMORY_LIMIT', '256M' );


/* WordPress Cache */
define( 'WP_CACHE', true );

#wp files
define ('WP_CONTENT_FOLDERNAME', 'static');

define( 'WP_CONTENT_URL', '".$domain."/static' );
define( 'WP_CONTENT_DIR', dirname(__FILE__) . '/static' );
define( 'WP_PLUGIN_DIR', dirname(__FILE__) . '/static/modules' );
define( 'WP_PLUGIN_URL', '".$domain."/static/modules' );
define( 'UPLOADS', 'static/uploads' );


/* Compression */
define( 'COMPRESS_CSS',        true );
define( 'COMPRESS_SCRIPTS',    true );
define( 'CONCATENATE_SCRIPTS', true );
define( 'ENFORCE_GZIP',        true );

/* MySQL database table prefix. */

$table_prefix = '".$perfix."';
define( 'CUSTOM_USER_TABLE',      $table_prefix . 'accounts' );
define( 'CUSTOM_USER_META_TABLE', $table_prefix . 'accountsmeta' );
// Here we just simulate how it's done in the core
define( 'COOKIEHASH',    hash('sha256', '".$domain."')); 

// Then we override the cookie names:
define( 'USER_COOKIE',          '".$security_perfix."_account_'      . COOKIEHASH );
define( 'PASS_COOKIE',          '".$security_perfix."_account_pass_'      . COOKIEHASH );
define( 'AUTH_COOKIE',          '".$security_perfix."_'           . COOKIEHASH );
define( 'SECURE_AUTH_COOKIE',   '".$security_perfix."_aut_sec_'       . COOKIEHASH );
define( 'LOGGED_IN_COOKIE',     '".$security_perfix."_logged_in_' . COOKIEHASH );
define( 'TEST_COOKIE',          '".$security_perfix."_skp_cookie'             );

/* Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
     define('ABSPATH', dirname(__FILE__) . '/');

/* Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');";
rename($path.'/wp-content', $path.'/static');
rename($path.'/static/plugins', $path.'/static/modules');
change_perfix($connection,$perfix,"wp_");
}

if (empty($path) || !is_dir($path)) {
	exit("wordpress not install in this path");
} elseif (!is_file($path."/wp-config.php")) {
	exit("wordpress not install in this path");
} else {
	include $path."/wp-config.php"; 
	$mysqli = new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
	// Check connection
    if ($mysqli->connect_errno) {
        exit("Invaild Mysql Connection");
    } else {
        $wppassword = password_hash($wppassword, PASSWORD_BCRYPT, [ 'cost' => 12, 'salt' => AUTH_SALT ]);
    	if ($mysqli->query("UPDATE wp_users SET user_login='".$wpusername."' WHERE user_login='admin';") === TRUE) {
            if ($mysqli->query("UPDATE wp_users SET user_pass='".$wppassword."' WHERE user_login='".$wpusername."';") === TRUE) {
            	if ($mysqli->query("UPDATE wp_options SET option_value='".$wpsite_url."' WHERE option_name='siteurl';") === TRUE) {
            	    if ($mysqli->query("UPDATE wp_options SET option_value='".$wpsite_url."' WHERE option_name='home';") === TRUE) {
            	        if ($mysqli->query("UPDATE wp_users SET user_url='".$wpsite_url."' WHERE user_login='".$wpusername."';") === TRUE) {
            	        	$security = array('AUTH_KEY' => AUTH_KEY, 'SECURE_AUTH_KEY' => SECURE_AUTH_KEY, 'LOGGED_IN_KEY' => LOGGED_IN_KEY, 'NONCE_KEY' => NONCE_KEY, 'AUTH_SALT' => AUTH_SALT, 'SECURE_AUTH_SALT' => SECURE_AUTH_SALT, 'LOGGED_IN_SALT' => LOGGED_IN_SALT, 'NONCE_SALT' => NONCE_SALT);
            	        	security_wp_(DB_NAME,DB_USER,DB_PASSWORD,DB_HOST,$security,$wpsite_url,$mysqli);

            	        	exit("Happy Blogging ");
            	        } else {
            	        	exit($mysqli->error);
            	        }
                        
            	    } else {
            	        exit($mysqli->error);
            	    }
            	} else {
            	   exit($mysqli->error);
            	}
            } else {
    		   exit($mysqli->error);
    	    }
    	} else {
    		exit($mysqli->error);
    	}
    }
}
