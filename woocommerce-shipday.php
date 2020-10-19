<?php
/*
Plugin Name: Shipday Integration for WooCommerce
Plugin URI: https://www.shipday.com/woocommerce
Version: 1.0
Description: Allows you to add shipday API configuration and create connection with shipday. Then anyone places any order to the WooCommerce site it should also appear on your Shipday dispatch dashboard.
Author: shipdayinc
Author URI: https://www.shipday.com/
Text Domain: woocommerce-shipday
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**************************************************
	To check if WooCommerce plugin is actived
***************************************************/

if (in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' )))) {


	class WC_Settings_Tab_Shipday {

		/* init function */
		public static function init() {

			add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
			add_action( 'woocommerce_settings_tabs_settings_tab_shipday', __CLASS__ . '::settings_tab' );
			add_action( 'woocommerce_update_options_settings_tab_shipday', __CLASS__ . '::update_settings' );

		}

		/* Add a new shipday tab */
		public static function add_settings_tab( $settings_tabs ) {

			$settings_tabs['settings_tab_shipday'] = __( 'Shipday', 'woocommerce-settings-tab-shipday' );
			return $settings_tabs;

		}

		/* Uses the woocommerce admin fields API  */
		public static function settings_tab() {

			woocommerce_admin_fields( self::get_settings() );
		}

		/*
		 * Uses the woocommerce options api to save settings.
		*/
		public static function update_settings() {

			woocommerce_update_options( self::get_settings());

			self::sd_create_webhook($_POST);
		}

		/*******************************************************
			Function to create & update woocommerce webhook
		*******************************************************/

		public static function sd_create_webhook( $postdata ){

			check_admin_referer( 'woocommerce-settings','_wpnonce' );            //verifying that a user was referred from another admin page with the correct security nonce.

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have permission to update Webhooks', 'woocommerce' ) );
			}

			$shipday_key 		= 	sanitize_text_field($postdata['wc_settings_tab_shipday_shipday_key']);
			$business_name 		= 	sanitize_text_field($postdata['wc_settings_tab_shipday_business_name']);
			$pickup_address 	= 	sanitize_text_field($postdata['wc_settings_tab_shipday_pickup_address']);
			$pickup_phone 		= 	sanitize_text_field($postdata['wc_settings_tab_shipday_pickup_phone']);

			$webhook_name 		= 	"Shipday Webhook";
			$webhook_status 	= 	"active";
			$webhook_topic 		= 	"order.updated";
			$api_version 		= 	"3";

            $user = wp_get_current_user();
			$delivery_url = wp_nonce_url("https://integration.shipday.com/integration/woocommerce/delegateOrder?key=$shipday_key&businessname=$business_name&pickupaddress=$pickup_address&pickupphone=$pickup_phone",'shipday-delivery-url'.$user->ID);

			$errors  = array();

			$webhook_id = get_option('webhook_id');

			// check if webhook already exist
			$webhook_exist = wc_get_webhook(sanitize_text_field($webhook_id));

			$webhook_id = (isset( $webhook_id ) && $webhook_exist )? absint( $webhook_id ) : 0;

			$webhook_id = sanitize_text_field($webhook_id);

			$webhook    = new WC_Webhook( $webhook_id );

			// webhook Name
			if ( ! empty( $webhook_name ) ) {
				$name = sanitize_text_field( wp_unslash( $webhook_name ) );
			}

			$webhook->set_name( $name );

			if ( ! $webhook->get_user_id() ) {
				$webhook->set_user_id( get_current_user_id() );
			}

			// webhook status
			$webhook->set_status( ! empty( $webhook_status ) ? sanitize_text_field( wp_unslash( $webhook_status ) ) : 'disabled' );

			// Delivery URL.
			$delivery_url = ! empty( $delivery_url ) ? esc_url_raw( wp_unslash( $delivery_url ) ) : '';

			if ( wc_is_valid_url( $delivery_url ) ) {
				$webhook->set_delivery_url( $delivery_url );
			}

			// webhook Secret key
			$secret = wp_generate_password( 50, true, true );
			$webhook->set_secret( $secret );

			// webhook Topic.
			if ( ! empty( $webhook_topic ) ) {
				$resource = '';
				$event    = '';

				switch ( $webhook_topic ) {
					case 'action':
						$resource = 'action';
						$event    = ! empty( $_POST['webhook_action_event'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_action_event'] ) ) : '';
						break;

					default:
						list( $resource, $event ) = explode( '.', sanitize_text_field( wp_unslash( $webhook_topic ) ) );
						break;
				}

				$topic = $resource . '.' . $event;

				if ( wc_is_webhook_valid_topic( $topic ) ) {
					$webhook->set_topic( $topic );
				} else {
					$errors[] = __( 'Webhook topic unknown. Please select a valid topic.', 'woocommerce' );
				}
			}

			// to check API version.
			$rest_api_versions = wc_get_webhook_rest_api_versions();
			$webhook->set_api_version( ! empty( $api_version ) ? sanitize_text_field( wp_unslash( $api_version ) ) : end( $rest_api_versions ) );

			$webhook->save();

			// Run actions.
			do_action( 'woocommerce_webhook_options_save', $webhook->get_id() );

			update_option( 'webhook_id',  $webhook->get_id() );

			if ( $errors ) {

				// Redirect to shipday edit page with errors
				wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=settings_tab_shipday&section=webhooks&edit-webhook=' . $webhook->get_id() . '&error=' . rawurlencode( implode( '|', $errors ) ) ) );
				exit();

			} elseif ( isset( $webhook_status ) && 'active' === $webhook_status && $webhook->get_pending_delivery() ) {
				// Ping the webhook at the first time that is activated.
				$result = $webhook->deliver_ping();

				if ( is_wp_error( $result ) && $result->get_error_message() != "Error: Delivery URL returned response code: 202") {

					// Redirect to shipday edit page with errors
					wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=settings_tab_shipday&section=webhooks&edit-webhook=' . $webhook->get_id() . '&error=' . rawurlencode( $error ) ) );
					exit();
				}
			}

			// Redirect to shipday edit page
			wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=settings_tab_shipday&section=webhooks&edit-webhook=' . $webhook->get_id() . '&updated=1' ) );
			exit();

		}

		/*
		 * Get all the settings for this shipday tab
		*/
		public static function get_settings() {

			$settings = array(
				'section_title' => array(
					'name'     => __( 'Shipday Settings', 'woocommerce-settings-tab-shipday' ),
					'type'     => 'title',
					'desc'     => 'Login to your Shipday account to get the API key. It’s in the following, My Account > Profile > Api key',
					'id'       => 'wc_settings_tab_shipday_section_title'
				),
				'shipday_key' => array(
					'name' => __( 'Shipday API Key', 'woocommerce-settings-tab-shipday' ),
					'type' => 'text',
					'custom_attributes' => array( 'required' => 'required' ),
					'id'   => 'wc_settings_tab_shipday_shipday_key'
				),
				'business_name' => array(
					'name' => __( 'Business Name', 'woocommerce-settings-tab-shipday' ),
					'type' => 'text',
					'id'   => 'wc_settings_tab_shipday_business_name'
				),
				'pickup_address' => array(
					'name' => __( 'Pickup Address', 'woocommerce-settings-tab-shipday' ),
					'type' => 'text',
					'id'   => 'wc_settings_tab_shipday_pickup_address'
				),
				'pickup_phone' => array(
					'name' => __( 'Pickup Phone Number', 'woocommerce-settings-tab-shipday' ),
					'type' => 'tel',
					'css'  => 'width:400px;',
					'id'   => 'wc_settings_tab_shipday_pickup_phone'
				),
				'section_end' => array(
					 'type' => 'sectionend',
					 'id' => 'wc_settings_tab_shipday_section_end'
				)
			);
			return apply_filters( 'wc_settings_tab_shipday_settings', $settings );

		}

	}
	WC_Settings_Tab_Shipday::init();

	/* woocommerce delete webhook callback  */

	function action_woocommerce_delete_webhook( $id, $webhook ) {

			if(current_user_can( 'manage_woocommerce' )){

				if(get_option('webhook_id') == $id){

					update_option( 'webhook_id',  "");

				}
			}
		}

	add_action( 'woocommerce_webhook_deleted', 'action_woocommerce_delete_webhook', 10, 2 );

}

