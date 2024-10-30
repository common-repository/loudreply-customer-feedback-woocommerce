<?php
/**
 * Plugin Name: LoudReply Customer Feedback - WooCommerce
 * Plugin URI: https://loudreply.com/integrations/woocommerce/
 * Description:  LoudReply is the free solution for sending customer satisfaction surveys from your WooCommerce store.
 * Version: 1.0.0
 * Author: LoudReply
 * Author URI: https://loudreply.com/integrations/woocommerce
 * Text Domain: lcf-woocommerce
 * Domain Path: /languages
 *
 * WC requires at least: 3.0.7
 * WC tested up to: 3.0.7
 *
 * Copyright: © 2009-2015 WooCommerce.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
 
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

//Checks if the WooCommerce plugins is installed and active.
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	if (!class_exists('LCF_WooCommerce')) {
		class LCF_WooCommerce {
			private $API = 'https://app.loudreply.com/inc/incomingSurvey';
			/**
			 * __construct function.
			 *
			 * @access public
			 * @return void
			 */
			public function __construct() {
				load_plugin_textdomain('lcf-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
				
				add_action('woocommerce_init', array(&$this, 'lcf_init'), 0);
				add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'lcf_action_links') );
			}
			
			/**
			 * init function.
			 *
			 * @access public
			 * @return void
			 */
			public function lcf_init () {
				add_action( 'admin_menu', array( $this, 'lcf_woocommerce_admin_settings' ) );
				add_action( 'woocommerce_order_status_pending', array( $this, 'lcf_woocommerce_order_pending') );
				add_action( 'woocommerce_order_status_failed', array( $this, 'lcf_woocommerce_order_failed') );
				add_action( 'woocommerce_order_status_on-hold', array( $this, 'lcf_woocommerce_order_hold') );
				add_action( 'woocommerce_order_status_processing', array( $this, 'lcf_woocommerce_order_processing') );
				add_action( 'woocommerce_order_status_completed', array( $this, 'lcf_woocommerce_order_completed') );
				add_action( 'woocommerce_order_status_refunded', array( $this, 'lcf_woocommerce_order_refunded') );
				add_action( 'woocommerce_order_status_cancelled', array( $this, 'lcf_woocommerce_order_cancelled') );

				register_activation_hook(__FILE__, array( $this, 'lcf_activation' ));
				register_uninstall_hook(__FILE__, array( $this, 'lcf_uninstall' ));
			} // End init()
			
			/**
			 * add the action links to plugin admin page
			 *
			 * @access public
			 * @return mixed
			 */
			public function lcf_action_links( $links ) {
				$links[] = '<a href="' . ( ( is_ssl() || force_ssl_admin() || force_ssl_login() ) ? str_replace( 'http:', 'https:', admin_url( 'admin.php?page=lcf-woocommerce-settings-page' ) ) : str_replace( 'https:', 'http:', admin_url( 'admin.php?page=lcf-woocommerce-settings-page' ) ) ) . '">' . _e( 'Settings', 'lcf-woocommerce' ) . '</a>';
				return $links;
			}
			
			/**
			 * activation function.
			 *
			 * @access public
			 * @return void
			 */			
			public function lcf_activation() {
				if(current_user_can( 'activate_plugins' )) {
					$default_settings = get_option('lcf_woocommerce_settings', false);
					if(!is_array($default_settings)) {
						add_option('lcf_woocommerce_settings', array( $this, 'lcf_woocommerce_get_default_settings'));
					}
				}        
			}// End activation()

			/**
			 * uninstall function.
			 *
			 * @access public
			 * @return void
			 */
			public function lcf_uninstall() {
				if(current_user_can( 'activate_plugins' ) && __FILE__ == WP_UNINSTALL_PLUGIN ) {
					delete_option('lcf_woocommerce_settings');	
				}	
			}// End uninstall()
			
			/**
			 * get default settings function.
			 *
			 * @access public
			 * @return void
			 */
			public function lcf_woocommerce_get_default_settings() {
				return array( 'userHash' => '1234567890',
							  'channel' => '',
							  'source' => 'WooCommerce',
							  'orderStatus' => '');
			}
			
			/**
			 * add sub menu function.
			 *
			 * @access public
			 * @return void
			 */		
			public function lcf_woocommerce_admin_settings () {
				add_submenu_page( 'woocommerce', 'LoudReply Surveys', 'LoudReply Surveys', 'manage_woocommerce', 'lcf-woocommerce-settings-page', array( $this, 'lcf_woocommerce_display_admin_page' ) );

			}
			
			/**
			 * sub menu callback function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_display_admin_page() {
				if ( function_exists('current_user_can') && !current_user_can('manage_options') ) {
					die(_e(''));
				}
				if (isset($_POST['lcf_woocommerce_settings'])) {
					check_admin_referer( 'lcf_woocommerce_settings_form' );
					$this->lcf_woocommerce_process_settings();
					$this->lcf_woocommerce_display_settings();
				} else {
					$this->lcf_woocommerce_display_settings();
				}
			}
			
			/**
			 * display settings page function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_display_settings() {
				$surveys_settings = get_option('lcf_woocommerce_settings', $this->lcf_woocommerce_get_default_settings());
				$userHash = $surveys_settings['userHash'];
				$orderStatus = $surveys_settings['orderStatus'];
				$statuses = wc_get_order_statuses();
				$options = '';
				
				foreach($statuses as $key => $value){
					$options .= "<option value='" . $key . "' ".selected($key,$surveys_settings['orderStatus'], false).">" . $value . "</option>";
				}
				
				$settings_html =  
					"<div class='wrap'>			
					    <img src=\"https://loudreply.com/img/loudReplyLogo.png\">
						<h2>LoudReply Customer Survey Settings</h2>
						<p>LoudReply allows you to gather customer feedback in realtime from your store. Best of all, it's free to use!</p>
						<p>Don't have an account? Sign up at <a href=\"https://loudReply.com\">LoudReply.com</a> to get started. </p>
						<hr />
						  <form  method='post' id='lcf_woocommerce_settings_form'>
							<table class='form-table'>".
								wp_nonce_field('lcf_woocommerce_settings_form').
							  "<fieldset>
								 <tr valign='top'>
									<th scope='row'><div>Your LoudReply Secret Key:</div></th>
									<td><div><input type='text' class='survey_usersecret' name='survey_usersecret' value='$userHash' size='50'/></div><i>Your Secret Key is found within your LoudReply Account. To find it, Log in to LoudReply, select &quot; Settings,&quot; then select &quot;Personal Details.&quot;</i></td>
								 </tr>               	                 
								 <tr valign='top'>			
								   <th scope='row'><div>Preferred Survey Channel:</div></th>
								   <td>
									 <select name='survey_channel' class='survey_channel'>
									   <option value='email' ".selected('email',$surveys_settings['channel'], false).">Email</option>
									   <option value='SMS' ".selected('SMS',$surveys_settings['channel'], false).">SMS / Text Messages</option>
									 </select>
								   <br/><i>If you have a premium LoudReply subscription, you can select to send either SMS text message surveys, or email. </i></td>
								 </tr>
								 <tr valign='top'>			
								   <th scope='row'><div>Order Status State:</div></th>
								   <td>
									 <select name='survey_orderstatus' class='survey_orderstatus'>".
									   $options.
									 "</select>
									 <br/><i>Select the order state when you want LoudReply surveys to be sent. </i>
									 
									 <input type='hidden' class='survey_source' name='survey_source' value='woocommerce'/>
								   </td>
								 </tr>
							   </fieldset>
							 </table></br>			  		
							 <div class='buttons-container'>
							<input type='submit' name='lcf_woocommerce_settings' value='Save Settings' class='button-primary' id='lcf_woocommerce_settings'/>
						</div>
					  </form>
					</div>";		

				echo $settings_html;		  
			}

			/**
			 * process settings function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_process_settings() {
				$errors = array();
				$survey_usersecret = sanitize_text_field(strval($_POST['survey_usersecret']));
				$survey_channel = sanitize_text_field(strval($_POST['survey_channel']));
				$survey_source = sanitize_text_field(strval($_POST['survey_source']));
				$survey_orderstatus = sanitize_text_field(strval($_POST['survey_orderstatus']));
				
				if ($survey_usersecret === '') {
					array_push($errors, 'Please provide your LoudReply account\'s secret ID.');
				}
				
				if ($survey_channel === '') {
					array_push($errors, 'Please select a preferred survey channel.');
				}
				
				if ($survey_orderstatus === '') {
					array_push($errors, 'Please select when you want to send surveys.');
				}
				
				if(count($errors) == 0) {				
					$current_settings = get_option('lcf_woocommerce_settings', $this->lcf_woocommerce_get_default_settings());
					$new_settings = array('userHash' => $survey_usersecret,
										 'channel' => $survey_channel,
										 'source' => $survey_source,
										 'orderStatus' => $survey_orderstatus);
					update_option( 'lcf_woocommerce_settings', $new_settings );
					$this->lcf_woocommerce_display_message('Settings successfully saved!', false);
				} else {
					$this->lcf_woocommerce_display_message($errors, true);
				}
			}
			
			/**
			 * display alert messages function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_display_message($messages = array(), $is_error = false) {
				$class = $is_error ? 'error' : 'updated fade';
				if(is_array($messages)) {
					foreach ($messages as $message) {
						echo sprintf(__('<div id="message" class="%1$s"><p><strong>%2$s</strong></p></div>','lcf-woocommerce'),$class,$message);
					}
				}
				elseif(is_string($messages)) {
					echo sprintf(__('<div id="message" class="%1$s"><p><strong>%2$s</strong></p></div>','lcf-woocommerce'),$class,$messages);
				}
			}
			
			/**
			 * order status change - pending function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_order_pending($order_id) {
				$order = new WC_Order( $order_id );
				$this->postSurvey($order_id, $order->billing_email, $order->billing_phone, 'wc-' . $order->status);
			}
			
			/**
			 * order status change - failed function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_order_failed($order_id) {
				$order = new WC_Order( $order_id );
				$this->postSurvey($order_id, $order->billing_email, $order->billing_phone, 'wc-' . $order->status);
			}
			
			/**
			 * order status change - hold function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_order_hold($order_id) {
				$order = new WC_Order( $order_id );
				$this->postSurvey($order_id, $order->billing_email, $order->billing_phone, 'wc-' . $order->status);
			}
			
			/**
			 * order status change - processing function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_order_processing($order_id) {
				$order = new WC_Order( $order_id );
				$this->postSurvey($order_id, $order->billing_email, $order->billing_phone, 'wc-' . $order->status);
			}
			
			/**
			 * order status change - completed function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_order_completed($order_id) {
				$order = new WC_Order( $order_id );
				$this->postSurvey($order_id, $order->billing_email, $order->billing_phone, 'wc-' . $order->status);
			}
			
			/**
			 * order status change - refunded function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_order_refunded($order_id) {
				$order = new WC_Order( $order_id );
				$this->postSurvey($order_id, $order->billing_email, $order->billing_phone, 'wc-' . $order->status);
			}
			
			/**
			 * order status change - cancelled function.
			 *
			 * @access public
			 * @return void
			 */	
			public function lcf_woocommerce_order_cancelled($order_id) {
				$order = new WC_Order( $order_id );
				$this->postSurvey($order_id, $order->billing_email, $order->billing_phone, 'wc-' . $order->status);
			}
			
			/**
			 * post order to survey API function.
			 *
			 * @access public
			 * @return void
			 */
			 public function postSurvey($order_id, $buyerEmail, $buyerPhone = null, $status) {
				$surveys_settings = get_option('lcf_woocommerce_settings', $this->lcf_woocommerce_get_default_settings());
				$userHash = strval($surveys_settings['userHash']);
				$orderStatus = strval($surveys_settings['orderStatus']);
				$channel = strval($surveys_settings['channel']);
				$source = strval($surveys_settings['source']);
				
				if($status == $orderStatus){
					$url = $this->API;
					$body = array(
						'userHash' => $userHash,
						'buyerEmail' => $buyerEmail,
						'buyerPhone' => $buyerPhone,
						'channel' => $channel, 
						'source' => $source
					);
					
					$args = array(
						'method' => 'POST',
						'timeout' => 30,
						'sslverify' => false,
						'redirection' => 5,
						'httpversion' => '1.0',
						'blocking' => true,
						'headers' => array(),						
						'body' => $body,
						'cookies' => array()
					);
					$response = wp_remote_post( $url, $args );
					
					if (!is_wp_error( $response ) ) {
						$result = json_decode( wp_remote_retrieve_body( $response ), true );	
						$order = new WC_Order( $order_id );
						$note = sprintf(__('Survey Sent Status: %1$s', 'lcf-woocommerce'), $result['status']);
						$order->add_order_note( $note );
					}
				}
			}
			
		}//end of the class  
	}//end of the if, if the class exists
	/*
	* Instantiate plugin class and add it to the set of globals.
	*/
	$lcf_woocommerce = new LCF_WooCommerce();

	$plugin = plugin_basename( __FILE__ );
} else {//end if,if installed woocommerce
	add_action('admin_notices', 'lcf_woocommerce_error_notice');

	function lcf_woocommerce_error_notice() {
		global $current_screen;
		if ($current_screen->parent_base == 'plugins') {
		  echo sprintf(__('LoudReply WooCommerce requires WooCommerce to be installed and activated first.  Please download and install WooCommerce.','lcf-woocommerce'),'<div class="error"><p>', admin_url('plugin-install.php?tab=search&type=term&s=WooCommerce'), '</p></div>');
		}
	}
}