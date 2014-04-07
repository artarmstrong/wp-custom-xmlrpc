<?php
/*
Plugin Name: WP Custom XML-RPC Extension
Plugin URI: http://artarmstrong.com
Description: Add extention for the XML-RPC feature of Wordpress.
Version: 1.0
Author: Art Armstrong
Author URI: http://artarmstrong.com
*/

//ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);


// Add the new XML-RPC methods
function wpc_getUserID( $args ) {

  global $wp_xmlrpc_server;
  $wp_xmlrpc_server->escape( $args );

  $blog_id  = $args[0];
  $username = $args[1];
  $password = $args[2];

  if ( ! $user = $wp_xmlrpc_server->login( $username, $password ) )
      return $wp_xmlrpc_server->error;

  return $user->ID;

}

function wpc_createNewBlog( $args ) {

  global $wp_xmlrpc_server;
  $wp_xmlrpc_server->escape( $args );

  $domain = $args[0];
  $title 	= $args[1];
  $email 	= $args[2];
  $user 	= $args[3];
  $pass 	= $args[4];

  // Login user
  if ( ! $user = $wp_xmlrpc_server->login( $user, $pass ) )
    return $wp_xmlrpc_server->error;

  // Make sure they have permission 'manage_sites'
  if ( ! current_user_can( 'manage_sites' ) )
  	return new IXR_Error( 401, __( 'You do not have sufficient permissions to add sites to this network.' ));

	// Set the domain
	if ( preg_match( '|^([a-zA-Z0-9-])+$|', $domain ) )
		$domain = strtolower( $domain );

	// If not a subdomain install, make sure the domain isn't a reserved word
	if ( ! is_subdomain_install() ) {
		$subdirectory_reserved_names = apply_filters( 'subdirectory_reserved_names', array( 'page', 'comments', 'blog', 'files', 'feed' ) );
		if ( in_array( $domain, $subdirectory_reserved_names ) )
			return new IXR_Error( 401, sprintf( __('The following words are reserved for use by WordPress functions and cannot be used as blog names: <code>%s</code>' ), implode( '</code>, <code>', $subdirectory_reserved_names ) ) );
	}

	// Sanitize the email
	$email = sanitize_email( $email );

	// Verify we have all the data
	if ( empty( $domain ) )
		return new IXR_Error( 401, __( 'Missing or invalid site address.' ) );
	if ( empty( $email ) )
		return new IXR_Error( 401, __( 'Missing email address.' ) );
	if ( !is_email( $email ) )
		return new IXR_Error( 401, __( 'Invalid email address.' ) );

	// Setup the new domain from the passed variables and current domain
	switch_to_blog(1);
	$urlparts = parse_url(get_bloginfo('url'));
	$current_domain = $urlparts [host];
	//$current_domain = get_bloginfo('url');
	restore_current_blog();
	if ( is_subdomain_install() ) {
		$newdomain = $domain . '.' . preg_replace( '|^www\.|', '', $current_domain );
		$path = "/";
	} else {
		$newdomain = $current_domain;
		$path = "/" . $domain . '/';
	}

	// Setup the user
	$password = 'N/A';
	$user_id = email_exists($email);
	if ( !$user_id ) { // Create a new user with a random password
		$password = wp_generate_password( 12, false );
		$user_id = wpmu_create_user( $domain, $password, $email );
		if ( false == $user_id )
			return new IXR_Error( 401, __( 'There was an error creating the user.' ) );
		else
			wp_new_user_notification( $user_id, $password );
	}

	// Create the new site
	$id = wpmu_create_blog( $newdomain, $path, $title, $user_id , array( 'public' => 1 ), 1 );
	if ( !is_wp_error( $id ) ) {
		$content_mail = sprintf( __( "New site created by %1s\n\nAddress: %2s\nName: %3s"), $current_user->user_login , get_site_url( $id ), stripslashes( $title ) );
		wp_mail( get_site_option('admin_email'), sprintf( __( '[%s] New Site Created' ), $current_site->site_name ), $content_mail, 'From: "Site Admin" <' . get_site_option( 'admin_email' ) . '>' );
		wpmu_welcome_notification( $id, $user_id, $password, $title, array( 'public' => 1 ) );

		// Update the XML-RPC option on the new site
		switch_to_blog($id);
		update_option('enable_xmlrpc', 1);
		restore_current_blog();

		// Return
		return $id;

	} else {

		// Return error
		return new IXR_Error( 401, __( $id->get_error_message() ) );

	}

}

function wpc_new_xmlrpc_methods( $methods ) {
    $methods['avelient.createNewBlog'] = 'wpc_createNewBlog';
    return $methods;
}
add_filter( 'xmlrpc_methods', 'wpc_new_xmlrpc_methods');

// Add the menu pages
function wpc_xmlrpc_add_interface() {
	add_menu_page('XML-RPC Ext.', 'XML-RPC Ext.', 'manage_options', 'wpc_xmlrpc', 'wpc_xmlrpc_page_output');
}
add_action('admin_menu', 'wpc_xmlrpc_add_interface');

// Custom page output
function wpc_xmlrpc_page_output() {

	?>

	<div class='wrap'>

		<h2>Avelient XML-RPC Extension Plugin</h2>

		<form method="post" action="">

			<fieldset style="border:1px solid #ccc;margin:0 0 10px 0;padding:0 10px 7px 20px;">

				<legend><strong>Options</strong></legend>

				<?php

				if(isset($_POST['submit']) && $_POST['submit'] == 'Test Option'){

					// Get XML-RPC IXR class
					include_once(ABSPATH.'wp-includes/class-IXR.php');

					// Setup client
					$client = new IXR_Client('http://artarmstrong.com/xmlrpc.php');

					// Send query
					$newblog_domain = "test";
					$newblog_title = "Test Title";
					$newblog_email = "me@artarmstrong.com";
					if (!$client->query('avelient.createNewBlog', $newblog_domain, $newblog_title, $newblog_email, 'artarmstrong', 'idunn0yet')) {
						die('An error occurred - '.$client->getErrorCode().":".$client->getErrorMessage());
					}
					$newblog_id = $client->getResponse();
					echo "New Blog ID: $newblog_id<br />";


					// Setup new client
					$client = new IXR_Client('http://artarmstrong.com/'.$newblog_domain.'/xmlrpc.php');

					// Create new post
			    $content = array(
			        'post_title' => "Post Title",
			        'post_content' => "<p>Post body</p>"
			    );

			    // Send query
					if (!$client->query('wp.newPost', '', 'artarmstrong', 'idunn0yet', $content)) {
						die('An error occurred - '.$client->getErrorCode().":".$client->getErrorMessage());
					}

					echo $client->getResponse();

				}

				?>

				<p><input type="submit" name="submit" value="Test Option" /></p>

			</fieldset>

		</form>

	</div>

	<?php
}
