<?php
/*
Plugin Name: th23 Contact
Description: Simple contact form via block or legacy shortcode, optional spam and bot protection for messages by not-registered visitors
Version: 3.0.1
Author: Thorsten Hartmann (th23)
Author URI: https://th23.net
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: th23-contact
Domain Path: /lang

Coded 2012-2024 by Thorsten Hartmann (th23)
https://th23.net/
*/

// Security - exit if accessed directly
if(!defined('ABSPATH')) {
    exit;
}

class th23_contact {

	// Initialize class-wide variables
	public $plugin = array(); // plugin (setup) information
	public $options = array(); // plugin options (user defined, changable)
	public $data = array(); // data exchange between plugin functions

	function __construct() {

		// Setup basics
		$this->plugin['slug'] = 'th23-contact';
		$this->plugin['file'] = __FILE__;
		$this->plugin['basename'] = plugin_basename($this->plugin['file']);
		$this->plugin['dir_url'] = plugin_dir_url($this->plugin['file']);
		$this->plugin['version'] = '3.0.1';

		// Load plugin options
		$this->options = (array) get_option($this->plugin['slug']);

		// Localization
		add_action('init', array(&$this, 'localize'));

		// == customization: from here on plugin specific ==

		// Handle JS and CSS
		add_action('init', array(&$this, 'register_js_css'));
		add_action('wp_enqueue_scripts', array(&$this, 'load_css'));

		// Handle contact form shortcodes
		add_shortcode('th23-contact', array(&$this, 'contact_form'));

		// Add contact form block
		// note: follow simple way to add a block replacing previous shortcode - based on https://kau-boys.com/3108/wordpress/replace-a-shortcode-with-a-block-using-as-little-and-simple-code-as-possible
		add_action('init', array(&$this, 'register_block'));

	}

	// Error logging
	function log($msg) {
		if(!empty(WP_DEBUG) && !empty(WP_DEBUG_LOG)) {
			if(empty($this->plugin['data'])) {
				$plugin_data = get_file_data($this->plugin['file'], array('Name' => 'Plugin Name'));
				$plugin_name = $plugin_data['Name'];
			}
			else {
				$plugin_name = $this->plugin['data']['Name'];
			}
			error_log($plugin_name . ': ' . print_r($msg, true));
		}
	}

	// Localization
	function localize() {
		load_plugin_textdomain('th23-contact', false, dirname($this->plugin['basename']) . '/lang');
	}

	// == customization: from here on plugin specific ==

	// Register JS and CSS
	function register_js_css() {
		wp_register_script('th23-contact-js', $this->plugin['dir_url'] . 'th23-contact.js', array('jquery'), $this->plugin['version'], true);
		wp_register_script('th23-contact-captcha-js', 'https://www.google.com/recaptcha/api.js?hl=' . get_bloginfo('language'), array('jquery', 'th23-contact-js'), $this->plugin['version'], true);
		wp_register_style('th23-contact-css', $this->plugin['dir_url'] . 'th23-contact.css', array(), $this->plugin['version']);
	}


	// Load CSS
	function load_css() {

		// ensure contact form is set up correctly
		if(empty($this->options['admin_email']) || !is_email($this->options['admin_email'])) {
			return;
		}

		// what page/post are we showing
		$needle = array();
		if(is_page()) {
			$needle[] = 'pages';
			$needle[] = get_the_ID();
		}
		elseif(is_single()) {
			$needle[] = 'posts';
			$needle[] = get_the_ID();
		}

		// don't show contact form on archive / overview pages
		if(empty($needle)) {
			return;
		}

		// limited usage and not on respective page/post
		if(!empty($this->options['post_ids']) && empty(array_intersect($needle, $this->options['post_ids']))) {
			return;
		}
		wp_enqueue_style('th23-contact-css');
		$this->data['active'] = true;

	}

	// Handle contact form shortcodes
	function contact_form($atts = array()) {

		$html = '';

		// detect rendering via REST API for block editor (on backend)
		$editor = (defined('REST_REQUEST')) ? true : false;

		// check if contact form enabled, check correct configuration and no (other) block / shortcode has yet been rendered
		if($editor) {
			// check valid recepient address - warn accordingly in admin / block editor
			if(empty($this->options['admin_email']) || !is_email($this->options['admin_email'])) {
				$html .= '<div class="th23-message th23-contact-message error editor">';
				$html .= '<strong>' . esc_html__('Error', 'th23-contact') . '</strong>: ' . esc_html__('No valid e-mail address is specified as recipient - contact form is disabled until you specify one', 'th23-contact');
				$html .= '<br />' . esc_html__('See plugin settings: "Settings / th23 Contact"', 'th23-contact');
				$html .= '</div>';
			}
			// check active for current post/page being edited - warn accordingly in admin / block editor
			elseif(!empty($this->options['post_ids']) && !in_array(get_the_ID(), $this->options['post_ids']) && !in_array(get_post_type($content_id) . 's', $this->options['post_ids'])) {
				$html .= '<div class="th23-message th23-contact-message error editor">';
				$html .= '<strong>' . esc_html__('Error', 'th23-contact') . '</strong>: ' . esc_html__('Contact form is not enabled for this post / page - it does not appear on the frontend until you enable it', 'th23-contact');
				$html .= '<br />' . esc_html__('See plugin settings: "Settings / th23 Contact"', 'th23-contact');
				$html .= '</div>';
			}
			// check if already rendered - not possible via REST API as each block is a separate call (see https://github.com/WordPress/gutenberg/issues/16731), but "supports -> multiple: false" in th23-contact-block-editor.js should prevent more than one block in a page (except shortcode + block, but tolerated and only first of these rendered on frontpage)
		}
		elseif(empty($this->data['active']) || !empty($this->data['rendered'])) {
			return '';
		}
		else {
			$this->data['rendered']	= true;
		}

		// contact form disabled for visitors?
		if(!is_user_logged_in() && empty($this->options['visitors'])) {
			/* translators: parses in link to login */
			return '<div class="th23-contact-form"><div class="th23-message th23-contact-message info">' . sprintf(esc_html__('You must be %1$slogged in%2$s to use the contact form.', 'th23-contact'), '<a href="' . wp_login_url(get_permalink()) . '">', '</a>') . ' </div></div>';
		}

		// get user data
		$current_user = wp_get_current_user();
		if(is_user_logged_in()) {
			$user_name = $current_user->display_name;
			$user_mail = $current_user->user_email;
			$disabled = ' disabled="disabled"';
		}
		else {
			$user_name = '';
			$user_mail = '';
			$disabled = '';
		}

		// do action for contact form submission
		if(isset($_POST['th23-contact-submit'])) {

			$msg = array();

			// verify nonce
			if(!wp_verify_nonce($_POST['th23-contact-nonce'], 'th23_contact_submit')) {
				$msg['invalid'] = array('type' => 'error', 'text' => __('Invalid request - please fill out the form below and try again', 'th23-contact'));
			}

			// get user data for not-logged-in unsers
			if(!is_user_logged_in()) {
				$user_name = (isset($_POST['user_name'])) ? stripslashes(sanitize_text_field($_POST['user_name'])) : '';
				// note: check for (valid) e-mail address done before sending
				$user_mail = (isset($_POST['user_mail'])) ? stripslashes(sanitize_text_field($_POST['user_mail'])) : '';
			}
			// get message details
			$mail_subject = (isset($_POST['mail_subject'])) ? stripslashes(sanitize_text_field($_POST['mail_subject'])) : '';
			$mail_message = (isset($_POST['mail_message'])) ? stripslashes(sanitize_textarea_field($_POST['mail_message'])) : '';

			// verify captcha
			if(empty($msg) && !is_user_logged_in() && !empty($this->options['captcha']) && !empty($this->options['captcha_public']) && !empty($this->options['captcha_private'])) {
				$response = isset($_POST['g-recaptcha-response']) ? sanitize_text_field($_POST['g-recaptcha-response']) : '';
				$request = wp_remote_get('https://www.google.com/recaptcha/api/siteverify?secret=' . $this->options['captcha_private'] . '&response=' . $response . '&remoteip=' . $_SERVER["REMOTE_ADDR"]);
				$response_body = wp_remote_retrieve_body($request);
				$result = json_decode($response_body, true);
				if(!$result['success']){
					$msg['captcha'] = array('type' => 'error', 'text' => __('Sorry, you do not seem to be human - please solve the captcha shown to proof otherwise', 'th23-contact'));
				}
			}

			// verify terms
			if(empty($msg) && !is_user_logged_in() && !empty($this->options['terms']) && empty($_POST['terms'])) {
				$terms = (empty($title = get_option('th23_terms_title'))) ? __('Terms of Usage', 'th23-contact') : $title;
				/* translators: %s: title of terms & conditions page, as defined by admin */
				$msg['terms'] = array('type' => 'error', 'text' => sprintf(__('Please accept the %s and agree with processing your data', 'th23-contact'), $terms));
			}

			// verify user name, mail and message
			if(empty($msg)) {
				if(!is_user_logged_in()) {
					// check user_name
					if(empty($user_name)) {
						$msg['user_name'] = array('type' => 'error', 'text' => __('Please enter your name', 'th23-contact'));
					}
					// check mail
					if(empty($user_mail)) {
						$msg['empty_mail'] = array('type' => 'error', 'text' => __('Please enter your e-mail address', 'th23-contact'));
					}
					elseif(!is_email($user_mail)) {
						$msg['invalid_mail'] = array('type' => 'error', 'text' => __('Please enter your valid e-mail address', 'th23-contact'));
					}
				}
				// check message
				if(empty($mail_message)) {
					$msg['empty_message'] = array('type' => 'error', 'text' => __('Please enter a message', 'th23-contact'));
				}
			}

			// send message via mail
			if(empty($msg)) {
				$subject_line = (!empty($this->options['pre_subject'])) ? $this->options['pre_subject'] . ' ' : '';
				$subject_line .= (!empty($mail_subject)) ? $mail_subject : __('New message', 'th23-contact');
				$user_login = (!empty($current_user->user_login)) ? $current_user->user_login : __('visitor', 'th23-contact');
				/* translators: mail body to send with user message - 1: user display name, 2: user login, 3: user message, 4: reply e-mail address */
				$text = sprintf(__('%1$s (%2$s) sent you the following message via the contact form:

%3$s

---
Reply e-mail: %4$s', 'th23-contact'), $user_name, $user_login, $mail_message, $user_mail);
				$headers = 'Reply-To: ' . $user_mail . "\r\n";
				if(!wp_mail($this->options['admin_email'], $subject_line, $text, $headers)) {
					$msg['sending_failed'] = array('type' => 'error', 'text' => __('Your message could not be sent due to an error - please try again', 'th23-contact'));
				}
				else {
					$msg['sending_success'] = array('type' => 'success', 'text' => __('Your message has been sent - thank you', 'th23-contact'));
				}
			}

			// show feedback to user
			if(!empty($msg)) {
				foreach($msg as $message) {
					$html .= '<div class="th23-message th23-contact-message ' . esc_attr($message['type']) . '">' . esc_html($message['text']) . '</div>';
				}
			}

			// don't show form again upon successful sending
			if(!empty($msg['sending_success'])) {
				return $html;
			}

		}

		// show contact form
		wp_enqueue_script('th23-contact-js');
		$html .= '<div class="th23-form th23-contact-form"><form name="th23-contact-form" action="' . get_permalink() . '" method="post">';

		// user_name
		$error = (isset($msg['user_name'])) ? ' error' : '';
		$html .= '<p class="user_name"><span class="input-wrap' . $error . '">';
		$html .= '<label for="user_name">' . esc_html__('Name', 'th23-contact') . '<span class="required" data-hint="' . esc_attr(__('Required', 'th23-contact')) . '"></span></label>';
		$html .= '<input type="text" name="user_name" id="user_name" class="input" value="' . esc_attr($user_name) . '" size="20"' . $disabled . ' />';
		$html .= '</span></p>';

		// user_mail
		$error = (isset($msg['empty_mail']) || isset($msg['invalid_mail'])) ? ' error' : '';
		$html .= '<p class="user_mail"><span class="input-wrap' . $error . '">';
		$html .= '<label for="user_mail">' . esc_html__('E-mail', 'th23-contact') . '<span class="required" data-hint="' . esc_attr(__('Required', 'th23-contact')) . '"></span></label>';
		$html .= '<input type="text" name="user_mail" id="user_mail" class="input" value="' . esc_attr($user_mail) . '" size="20"' . $disabled . ' />';
		$html .= '</span></p>';

		// mail_subject
		$html .= '<p class="mail_subject"><span class="input-wrap">';
		$html .= '<label for="mail_subject">' . esc_html__('Subject', 'th23-contact') . '</label>';
		$html .= '<input type="text" name="mail_subject" id="mail_subject" class="input" value="' . esc_attr($mail_subject) . '" size="40" />';
		$html .= '</span></p>';

		// mail_message
		$error = (isset($msg['empty_message'])) ? ' error' : '';
		$html .= '<p class="mail_message"><span class="input-wrap' . $error . '">';
		$html .= '<label for="mail_message">' . esc_html__('Message', 'th23-contact') . '<span class="required" data-hint="' . esc_attr(__('Required', 'th23-contact')) . '"></span></label>';
		$html .= '<textarea type="text" name="mail_message" id="mail_message" class="input" cols="40" rows="5">' . esc_textarea($mail_message) . '</textarea>';
		$html .= '</span></p>';

		// terms
		if(!is_user_logged_in() && !empty($this->options['terms'])) {
			$terms = (empty($title = get_option('th23_terms_title'))) ? __('Terms of Usage', 'th23-contact') : $title;
			$terms = (!empty($url = get_option('th23_terms_url'))) ? '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($terms) . '</a>' : esc_html($terms);
			$error = (isset($msg['terms'])) ? ' error' : '';
			$html .= '<p class="terms' . $error . '">';
			/* translators: %s: link with/or title to page with terms & conditions, as defined by admin */
			$html .= '<input name="terms" type="checkbox" id="terms" value="ok"' . checked(true, $_POST['terms'], false) . ' /> <label for="terms">' . sprintf(esc_html__('I accept the %s and agree with processing my data', 'th23-contact'), $terms) . '<span class="required" data-hint="' . esc_attr(__('Required', 'th23-contact')) . '"></span></label>';
			$html .= '</p>';
		}

		// captcha
		if(!is_user_logged_in() && !empty($this->options['captcha']) && !empty($this->options['captcha_public']) && !empty($this->options['captcha_private'])) {
			wp_enqueue_script('th23-contact-captcha-js');
			$error = (isset($msg['captcha'])) ? ' error' : '';
			$html .= '<p class="captcha"><span class="input-wrap' . $error . '">';
			/* translators: "&#xa;" initiates a line break using this text as a tooltip, "&#128161;" light bulb symbol, "&#128077;" thumbs up sign */
			$hint = __('Required&#xa;&#128161; What? A captcha is a small test aiming to distinguish humans from computers.&#xa;&#128077; Why? Internet today needs to fight a lot of spam and this small test is required to keep this website clean.', 'th23-contact');
			$html .= '<label for="captcha">' . esc_html__('Captcha', 'th23-contact') . '<span class="required" data-hint="' . esc_attr($hint) . '"></span></label>';
			$html .= '<span class="g-recaptcha" data-sitekey="' . $this->options['captcha_public'] . '" data-theme="light"></span>';
			$html .= '</span>';
			$html .= '<span class="description">' . esc_html__('Confirm being a human', 'th23-contact') . '</span>';
			$html .= '</p>';
		}

		// action (disabled when viewing in admin via block editor)
		$html .= '<p class="action">';
		$html .= '<input type="submit" name="th23-contact-submit" class="button button-primary" value="' . esc_attr__('Send', 'th23-contact') . '"' . disabled($editor, true, false) . ' />';
		$html .= wp_nonce_field('th23_contact_submit', 'th23-contact-nonce', true, false);
		$html .= '</p>';

		$html .= '</form></div>';
		return $html;

	}

	// Add contact form block
	// note: below important frontend render function and internationalization - see th23-contact-admin.php and th23-contact-block-editor.js for more details
	function register_block() {
		register_block_type('th23-contact/contact-form', array(
			/* translators: title of the contact form block in the editor */
			'title' => __('th23 Contact Form', 'th23-contact'),
			'description' => __('Show contact form according to plugin settings for current user. Note: Might look different on frontend due to theme styling.', 'th23-contact'),
			'render_callback' => array(&$this, 'contact_form'),
		));
	}

}

// === INITIALIZATION ===

$th23_contact_path = plugin_dir_path(__FILE__);

// Load additional admin class, if required...
if(is_admin() && file_exists($th23_contact_path . 'th23-contact-admin.php')) {
	require($th23_contact_path . 'th23-contact-admin.php');
	$th23_contact = new th23_contact_admin();
}
// ...or initiate plugin directly
else {
	$th23_contact = new th23_contact();
}

?>
