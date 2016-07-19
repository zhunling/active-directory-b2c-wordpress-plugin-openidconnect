<?php

/**
 * Plugin Name: Microsoft Azure Active Directory B2C Authentication
 * Plugin URI: https://github.com/AzureAD/active-directory-b2c-wordpress-plugin-openidconnect
 * Description: A plugin that allows users to log in using B2C policies
 * Version: 1.0
 * Author: Microsoft
 * Author URI: https://azure.microsoft.com/en-us/documentation/services/active-directory-b2c/
 * License: TBD
 */

 
//*****************************************************************************************


/** 
 * Requires the autoloaders.
 */
require 'autoload.php';
require 'vendor/autoload.php';

/**
 * Defines the B2C Page Path, which is the location the ID_token should be posted to.
 * Also defines the response string posted by B2C.
 */
define('B2C_PAGE_PATH', '/b2c-token-verification');
define('B2C_RESPONSE_MODE', 'id_token');

// Adds the B2C Options page to the Admin dashboard, under 'Settings'.
if (is_admin()) $b2c_settings_page = new B2C_Settings_Page();
$b2c_settings = new B2C_Settings();


//*****************************************************************************************


/**
 * Redirects to B2C on a user login request.
 */
function b2c_login() {
	
	$b2c_endpoint_handler = new B2C_Endpoint_Handler(B2C_Settings::$generic_policy);
	$authorization_endpoint = $b2c_endpoint_handler->get_authorization_endpoint()."&state=generic";
	wp_redirect($authorization_endpoint);
	exit;
}

/** 
 * Redirects to B2C on user logout.
 */
function b2c_logout() {
	
	$signout_endpoint_handler = new B2C_Endpoint_Handler(B2C_Settings::$generic_policy);
	$signout_uri = $signout_endpoint_handler->get_end_session_endpoint();
	wp_redirect($signout_uri);
	exit;
}

/** 
 * Verifies the id_token that is POSTed back to the web app from the 
 * B2C authorization endpoint. 
 */
function b2c_verify_token() {
	
	// If and only if ID token is POSTed to the /b2c-token-verification path, 
	// proceeds with verifying the ID token. The path check ensures that other plugins
	// which may POST id tokens do not conflict with this plugin.
	$pagename = $_SERVER['REQUEST_URI'];
	if ($pagename == B2C_PAGE_PATH && isset($_POST[B2C_RESPONSE_MODE])) {
		
		// Check which authorization policy was used
		switch ($_POST['state']) {
			case 'generic': 
				$policy = B2C_Settings::$generic_policy;
				break;
			case 'admin':
				$policy = B2C_Settings::$admin_policy;
				break;
			case 'edit_profile':
				$policy = B2C_Settings::$edit_profile_policy;
				break;
		}	
		
		// Verifies token only if the checkbox "Verify tokens" is checked on the settings page
		$token_checker = new B2C_Token_Checker($_POST[B2C_RESPONSE_MODE], B2C_Settings::$clientID, $policy);
		if (B2C_Settings::$verify_tokens) {
			$verified = $token_checker->authenticate();
			if ($verified == false) wp_die('Token validation error');
		}
		
		// Use the email claim to fetch the user object from the WP database
		$email = $token_checker->get_claim('emails');
		$email = $email[0];
		$user = WP_User::get_data_by('email', $email);
		
		// Get the userID for the user
		if ($user == false) { // User doesn't exist yet, create new userID
			
			$first_name = $token_checker->get_claim('given_name');
			$last_name = $token_checker->get_claim('family_name');

			$our_userdata = array (
					'ID' => 0,
					'user_login' => $email,
					'user_pass' => NULL,
					'user_registered' => true,
					'user_status' => 0,
					'user_email' => $email,
					'display_name' => $first_name . ' ' . $last_name,
					'first_name' => $first_name,
					'last_name' => $last_name
					);

			$userID = wp_insert_user( $our_userdata ); 
		} else if ($policy == B2C_Settings::$edit_profile_policy) { // Update the existing user w/ new attritubtes
			
			$first_name = $token_checker->get_claim('given_name');
			$last_name = $token_checker->get_claim('family_name');
			
			$our_userdata = array (
									'ID' => $user->ID,
									'display_name' => $first_name . ' ' . $last_name,
									'first_name' => $first_name,
									'last_name' => $last_name
									);
												
			$userID = wp_update_user( $our_userdata );
		} else {
			$userID = $user->ID;
		}
		
		// Check if the user is an admin and needs MFA
		$wp_user = new WP_User($userID); 
		if (in_array('administrator', $wp_user->roles)) {
				
			// If user did not authenticate with admin_policy, redirect to admin policy
			if ($token_checker->get_claim('acr') != B2C_Settings::$admin_policy) {
				$b2c_endpoint_handler = new B2C_Endpoint_Handler(B2C_Settings::$admin_policy);
				$authorization_endpoint = $b2c_endpoint_handler->get_authorization_endpoint().'&state=admin';
				wp_redirect($authorization_endpoint);
				exit;
			}
		}
		
		// Set cookies to authenticate on WP side
		wp_set_auth_cookie($userID);
			
		// Redirect to home page
		wp_safe_redirect('/');
		exit;
	}
}

/** 
 * Redirects to B2C's edit profile policy when user edits their profile.
 */
function b2c_edit_profile() {
	
	// Check to see if user was requesting the edit_profile page, if so redirect to B2C
	$pagename = $_SERVER['REQUEST_URI'];
	if ($pagename == '/wp-admin/profile.php') {
		
		// Return URL for edit_profile endpoint
		$b2c_endpoint_handler = new B2C_Endpoint_Handler(B2C_Settings::$edit_profile_policy);
		$authorization_endpoint = $b2c_endpoint_handler->get_authorization_endpoint().'&state=edit_profile';
		wp_redirect($authorization_endpoint);
		exit;
	}
}

/** 
 * Hooks onto the WP login action, so when user logs in on WordPress, user is redirected
 * to B2C's authorization endpoint. 
 */
add_action('wp_authenticate', 'b2c_login');

/**
 * Hooks onto the WP page load action, so when user request to edit their profile, 
 * they are redirected to B2C's edit profile endpoint.
 */
add_action('wp_loaded', 'b2c_edit_profile');

/** 
 * Hooks onto the WP page load action. When B2C redirects back to WordPress site,
 * if an ID token is POSTed to a special path, b2c-token-verification, this verifies 
 * the ID token and authenticates the user.
 */
add_action('wp_loaded', 'b2c_verify_token');

/**
 * Hooks onto the WP logout action, so when a user logs out of WordPress, 
 * they are redirected to B2C's logout endpoint.
 */
add_action('wp_logout', 'b2c_logout');

