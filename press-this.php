<?php
/*
Plugin Name: Press This
Plugin URI: http://wordpress.org/extend/plugins/press-this/
Description: Posting images, links, and cat gifs will never be the same.
Version: 0.1
Author: Press This Team
Author URI: https://corepressthis.wordpress.com/
Text Domain: press-this
Domain Path: /languages
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

/**
 * Class WpPressThis
 */
class WpPressThis {
	/**
	 * WpPressThis::__construct()
	 * Constructor
	 *
	 * @uses remove_action(), add_action()
	 */
	public function __construct() {

		/*
		 * @TODO: IMPORTANT: must come up with final solution for SAMEORIGIN handling when in modal context (detect, secure, serve).
		 */

		if ( ! is_admin() ) {
			if ( false !== strpos( site_url('wp-login.php'), $_SERVER['SCRIPT_NAME'] ) ) {
				/*
				 * Only remove SAMEORIGIN header for /wp-login.php, so it can be displayed in the modal/iframe if needed,
				 * but only if then redirecting to /wp-admin/press-this.php
				 */
				if ( false !== strpos( $_GET['redirect_to'], self::runtime_url() ) )
					remove_action( 'login_init', 'send_frame_options_header' );
			}
		} else {
			if ( false !== strpos( self::runtime_url(), $_SERVER['SCRIPT_NAME'] ) ) {
				/*
				 * Remove SAMEORIGIN header for /wp-admin/press-this.php on targeted install so it can be used inside the modal's iframe
				 */
				remove_action( 'admin_init', 'send_frame_options_header' );
				/*
				 * Take over /wp-admin/press-this.php
				 */
				add_action( 'admin_init', array( $this, 'press_this_php_override' ), 0 );
			} else if ( false !== strpos( admin_url( 'post.php' ), $_SERVER['SCRIPT_NAME'] ) ) {
				/*
				 * Remove SAMEORIGIN header for /wp-admin/post.php so it can be used inside the modal's iframe,
				 * after saving a draft, but only if referred from /wp-admin/press-this.php
				 */
				if ( false !== strpos( $_SERVER['HTTP_REFERER'], self::runtime_url() ) )
					remove_action( 'admin_init', 'send_frame_options_header' );
			} else if ( false !== strpos( admin_url( 'tools.php' ), $_SERVER['SCRIPT_NAME'] ) ) {
				/*
				 * Take over Press This bookmarklet code in /wp-admin/tools.php
				 */
				add_filter( 'shortcut_link', array( $this, 'shortcut_link_override' ) );
			} else if ( false !== strpos( admin_url( 'admin-ajax.php' ), $_SERVER['SCRIPT_NAME'] ) ) {
				/*
				 * AJAX handling
				 */
				add_action( 'wp_ajax_press_this_site_settings', array( $this, 'press_this_ajax_site_settings' ) );
			}
		}
	}

	/**
	 * WpPressThis::runtime_url()
	 *
	 * @return string|void Full URL to /admin/press-this.php in current install
	 * @uses admin_url()
	 */
	public function runtime_url() {
		return admin_url( 'press-this.php' );
	}

	/**
	 * WpPressThis::plugin_dir_path()
	 *
	 * @return string|void Full URL to /admin/press-this.php in current install
	 * @uses __FILE__, plugin_dir_path()
	 */
	public function plugin_dir_path() {
		return rtrim( plugin_dir_path( __FILE__ ), '/' );
	}

	/**
	 * WpPressThis::plugin_dir_url()
	 *
	 * @return string
	 * @uses __FILE__, plugin_dir_url()
	 */
	public function plugin_dir_url() {
		return rtrim( plugin_dir_url( __FILE__ ), '/' );
	}

	/**
	 * WpPressThis::shortcut_link_override()
	 *
	 * @return mixed Press This bookmarklet JS trigger found in /wp-admin/tools.php
	 */
	public function shortcut_link_override() {
		$url  = esc_js( self::runtime_url() );
		$link = "javascript: var u='{$url}';\n";
		$link .= file_get_contents( self::plugin_dir_path() . '/js/bookmarklet.js' );
		return str_replace( array( "\r", "\n", "\t" ), '', $link );
	}

	/**
	 * WpPressThis::press_this_php_override()
	 * Takes over /wp-admin/press-this.php for backward compatibility and while in feature-as-plugin mode
	 *
	 * @uses $_POST
	 */
	public function press_this_php_override() {
		// Simply drop the following test once/if this becomes the standard Press This code in core
		if ( false === strpos( self::runtime_url(), $_SERVER['SCRIPT_NAME'] ) )
			return;

		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( get_post_type_object( 'post' )->cap->create_posts ) ) {
			wp_die( __( 'Cheatin&#8217; uh?' ) );
		}

		// Decide what to do based on requested action, or lack there of
		if ( ! empty( $_POST['wppt_publish'] ) ) {
			self::publish();
		} else if ( ! empty( $_POST['wppt_draft'] ) ) {
			self::save_draft();
		} else {
			self::serve_app_html();
		}
	}

	/**
	 * WpPressThis::report_and_redirect()
	 *
	 * @param $report
	 * @param $redirect
	 */
	public function report_and_redirect( $report, $redirect, $target = 'self' ){
		$report = esc_js( $report );
		echo <<<________HTMLDOC
<!DOCTYPE html>
<html>
<head lang="en">
	<script language="JavaScript">
		alert("{$report}");
		window.{$target}.location.href = '{$redirect}';
	</script>
</head>
</html>
________HTMLDOC;
		die();
	}

	/**
	 * WpPressThis::format_post_data_for_save()
	 *
	 * @return array('post_title', 'post_content')
	 *
	 * @uses $_POST
	 */
	public function format_post_data_for_save() {
		if ( empty( $_POST ) ) {
			$site_settings = self::press_this_site_settings();
			return array(
				'post_title'   => $site_settings['i18n']['New Post'],
				'post_content' => '',
			);
		}

		$post    = array();
		$content = '';

		if ( ! empty( $_POST['wppt_title'] ) ) {
			$post['post_title'] = sanitize_text_field( $_POST['wppt_title'] );
		}

		if ( ! empty( $_POST['wppt_content'] ) ) {
			$content = $_POST['wppt_content']; // we have to allow this one and let wp_insert_post() filter the content
		}

		if ( ! empty( $_POST['wppt_selected_img'] ) ) {
			$content = '<img src="'.esc_url( $_POST['wppt_selected_img'] ).'" />'
					. $content;
		}

		$post['post_content'] = $content;

		return $post;
	}

	/**
	 * WpPressThis::save()
	 *
	 * @param string $post_status
	 *
	 * @return bool|int|WP_Error
	 */
	public function save( $post_status = 'draft' ) {
		$wp_error      = false;
		$data          = self::format_post_data_for_save();

		if ( 'publish' != $post_status )
			$post_status = 'draft';

		$post = array(
			'post_title'     => $data['post_title'],
			'post_content'   => $data['post_content'],
			'post_status'    => $post_status,
			'post_type'      => 'post',
		);

		$post_id = wp_insert_post( $post, $wp_error );

		return ( ! empty( $wp_error ) ) ? $wp_error : $post_id;
	}

	/**
	 * WpPressThis::publish()
	 */
	public function publish() {
		$post_id = self::save( 'publish' );
		if ( is_wp_error( $post_id ) )
			wp_die( self::press_this_site_settings()['i18n']['Sorry, but an unexpected error occurred.'] );
		wp_safe_redirect( get_permalink( $post_id ) );
	}

	/**
	 * WpPressThis::save_draft()
	 */
	public function save_draft() {
		$post_id = self::save( 'draft' );
		if ( is_wp_error( $post_id ) )
			wp_die( self::press_this_site_settings()['i18n']['Sorry, but an unexpected error occurred.'] );
		wp_safe_redirect('./post.php?post='.$post_id.'&action=edit');
	}

	/**
	 * WpPressThis::serve_app_html()
	 *
	 * @uses $_POST, WpPressThis::runtime_url(), WpPressThis::plugin_dir_url()
	 */
	public function serve_app_html() {
		$plugin_data              = get_plugin_data( __FILE__, false, false );
		$nonce                    = wp_create_nonce( 'press_this_site_settings' );
		$_POST['_version']        = ( ! empty( $plugin_data ) && ! empty( $plugin_data['Version'] ) ) ? $plugin_data['Version'] : 0;
		$_POST['_runtime_url']    = self::runtime_url();
		$_POST['_plugin_dir_url'] = self::plugin_dir_url();
		$_POST['_ajax_url']       = admin_url( 'admin-ajax.php' );
		$_POST['_nonce']          = $nonce;
		$json                     = json_encode( $_POST );
		$js_inc_dir               = preg_replace( '/^(.+)\/wp-admin\/.+$/', '\1/wp-includes/js', self::runtime_url() );
		$json_js_inc              = $js_inc_dir . '/json2.min.js';
		$jquery_js_inc            = $js_inc_dir . '/jquery/jquery.js';
		$app_css_inc              = self::plugin_dir_url() . '/css/press-this.css';
		$load_js_inc              = self::plugin_dir_url() . '/js/load.js';
		$form_action              = self::runtime_url();
		echo <<<________HTMLDOC
<!DOCTYPE html>
<html>
<head lang="en">
	<meta charset="UTF-8">
	<title></title>
	<link rel='stylesheet' id='all-css' href='{$app_css_inc}' type='text/css' media='all' />
	<script language="JavaScript">
		window.wp_pressthis_data = {$json};
	</script>
	<script src="{$json_js_inc}" language="JavaScript"></script>
	<script src="{$jquery_js_inc}" language="JavaScript"></script>
	<script src="{$load_js_inc}" language="JavaScript"></script>
</head>
<body>
	<div id='wppt_app_container' class="editor">
		<h2 id='wppt_title_container' contenteditable="true"></h2>
		<div id='wppt_featured_image_container' class="featured-image-container">
			<a href="#" id="wppt_other_images_switch" class="other-images__switch button--secondary"></a>
			<div id='wppt_other_images_widget' class="other-images">
				<div id='wppt_other_images_container'></div>
			</div>
		</div>
		<div id='wppt_suggested_content_container' contenteditable="true"></div>
	</div>
	<div class="actions">
		<form id="wppt_form" class="post-actions" name="wppt_form" method="POST" action="{$form_action}" target="_self">
			<input type="hidden" name="wppt_nonce" id="wppt_nonce_field" value="{$nonce}"/>
			<input type="hidden" name="wppt_title" id="wppt_title_field" value=""/>
			<input type="hidden" name="wppt_selected_img" id="wppt_selected_img_field" value=""/>
			<input type="hidden" name="wppt_content" id="wppt_content_field" value=""/>
			<input type="submit" class="button--subtle" name="wppt_draft" id="wppt_draft" value=""/>
			<input type="submit" class="button--primary" name="wppt_publish" id="wppt_publish" value=""/>
		</form>
	</div>
</body>
</html>
________HTMLDOC;
		die();
	}

	/**
	 * WpPressThis::press_this_ajax_site_settings()
	 *
	 * @uses admin_url(), wp_create_nonce()
	 */
	public function press_this_site_settings() {
		$domain      = 'press-this';
		$plugin_data = get_plugin_data( __FILE__, false, false );
		return array(
			'version'        => ( ! empty( $plugin_data ) && ! empty( $plugin_data['Version'] ) ) ? $plugin_data['Version'] : 0,
			'i18n'           => array(
				'Welcome to Press This!' => __('Welcome to Press This!', $domain ),
				'Source:'                => __( 'Source:', $domain ),
				'Show other images'      => __( 'Show other images', $domain ),
				'Hide other images'      => __( 'Hide other images', $domain ),
				'Publish'                => __( 'Publish', $domain ),
				'Save Draft'             => __( 'Save Draft', $domain ),
				'New Post'               => __( 'New Post', $domain ),
				'Start typing here.'     => __( 'Start typing here.', $domain ),
				'Sorry, but an unexpected error occurred.' => __( 'Sorry, but an unexpected error occurred.', $domain ),
			),
		);
	}

	/**
	 * WpPressThis::press_this_ajax_site_settings()
	 *
	 * @uses admin_url(), wp_create_nonce()
	 */
	public function press_this_ajax_site_settings() {
		header( 'content-type: application/json' );
		echo json_encode( self::press_this_site_settings() );
		die();
	}
}

new WpPressThis;