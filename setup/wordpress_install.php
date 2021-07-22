#!/usr/bin/env php
<?php

$path = isset($_SERVER['argv']['1']) ? $path = strtolower($_SERVER['argv']['1']) : $path = null;
$wpusername = isset($_SERVER['argv']['2']) ? $_SERVER['argv']['2'] : 'admin';
$wppassword = isset($_SERVER['argv']['3']) ? $_SERVER['argv']['3'] : '123456789';
$wpsite_url = isset($_SERVER['argv']['4']) ? $_SERVER['argv']['4'] : null;

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
            	        	exit("Happy Blogging");
            	        } else {
            	        	xit($mysqli->error);
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
?>
