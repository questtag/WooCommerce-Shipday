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

		/*
		init function
		*/
		public static function init() {

			add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
			add_action( 'woocommerce_settings_tabs_settings_tab_shipday', __CLASS__ . '::settings_tab' );
			add_action( 'woocommerce_update_options_settings_tab_shipday', __CLASS__ . '::update_settings' );
			add_action('admin_enqueue_scripts', __CLASS__ . '::register_wcsscript' ); // admin enqueue for registered js file.

		}

		/*
		Function for Register custom js file.
		*/
		public static function register_wcsscript(){
			wp_enqueue_script( 'custom-wcsscript', plugin_dir_url( __FILE__ ) . '/js/wc-shipday-script.js', array(), '1.0' );
			wp_enqueue_script( 'custom-wcsscript' );
		}
		
		/*
		Add a new shipday tab
		*/
		public static function add_settings_tab( $settings_tabs ) {

			$settings_tabs['settings_tab_shipday'] = __( 'Shipday', 'woocommerce-settings-tab-shipday' );
			return $settings_tabs;

		}

		/*
		Uses the woocommerce admin fields API
		*/
		public static function settings_tab() {

			woocommerce_admin_fields( self::get_settings() );
		}

		/*
		Uses the woocommerce options api to save settings.
		*/
		public static function update_settings() {

			woocommerce_update_options( self::get_settings());

			self::sd_create_webhook($_POST);
		}

		/**********************************************
		Function to create & update woocommerce webhook
		**********************************************/

		public static function sd_create_webhook( $postdata ){

			check_admin_referer( 'woocommerce-settings','_wpnonce' );  //verifying that a user was referred from another admin page with the correct security nonce.

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have permission to update Webhooks', 'woocommerce' ) );
			}

			$shipday_key 		= 	sanitize_text_field($postdata['wc_settings_tab_shipday_shipday_key']);
			$business_name 		= 	sanitize_text_field($postdata['wc_settings_tab_shipday_business_name']);
			$pickup_address 	= 	sanitize_text_field($postdata['wc_settings_tab_shipday_pickup_address']);
			$pickup_phone 		= 	sanitize_text_field($postdata['wc_settings_tab_shipday_pickup_phone']);
			
			$shipday_location 	= 	sanitize_text_field($postdata['wc_settings_tab_shipday_location']);
			$vendor_type 		= 	sanitize_text_field($postdata['wc_settings_tab_shipday_vendor_type']);

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
		Get all the settings for this shipday tab
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
				'shipday_location' => array(
					'name' => __( 'Pick up location settings', 'woocommerce-settings-tab-shipday' ),
					'type' => 'select',
					'std' => 'Select Shipday Location',
					'options' => array(
						'single_pickup'        => __( 'Single pick up location (single vendor)' ),
						'multiple_pickup'       => __( 'Multiple pick up location (multi-vendor)' )
					),
					'id'   => 'wc_settings_tab_shipday_location'
				),
				'shipday_vendor_type' => array(
					'name' => __( 'Select Vendor Type', 'woocommerce-settings-tab-shipday' ),
					'type' => 'select',
					'std' => 'Select Vendor Type',
					'options' => array(
						'dokan'        => __( 'Dokan' ),
						'wcfm'       => __( 'WCFM' )
					),
					'id'   => 'wc_settings_tab_shipday_vendor_type',
					'css'  => 'display:none;'
				),
				/*'business_name' => array(
					'name' => __( 'Business Name', 'woocommerce-settings-tab-shipday' ),
					'type' => 'text',
					'id'   => 'wc_settings_tab_shipday_business_name',
					'class' => 'wcs_single_business_data'
				),
				'pickup_address' => array(
					'name' => __( 'Pickup Address', 'woocommerce-settings-tab-shipday' ),
					'type' => 'text',
					'id'   => 'wc_settings_tab_shipday_pickup_address',
					'class' => 'wcs_single_business_pickdata'
				),
				'pickup_phone' => array(
					'name' => __( 'Pickup Phone Number', 'woocommerce-settings-tab-shipday' ),
					'type' => 'tel',
					'css'  => 'width:400px;',
					'id'   => 'wc_settings_tab_shipday_pickup_phone',
					'class' => 'wcs_single_business_data'
				),*/
				'section_end' => array(
					'type' => 'sectionend',
					'id' => 'wc_settings_tab_shipday_section_end'
				)
			);
			return apply_filters( 'wc_settings_tab_shipday_settings', $settings );

		}

	}
	WC_Settings_Tab_Shipday::init();

	/*
	woocommerce delete webhook callback 
	*/

	function action_woocommerce_delete_webhook( $id, $webhook ) {

		if(current_user_can( 'manage_woocommerce' )){

			if(get_option('webhook_id') == $id){

				update_option( 'webhook_id',  "");

			}
		}
	}

	add_action( 'woocommerce_webhook_deleted', 'action_woocommerce_delete_webhook', 10, 2 );
	
	/*
	Function for get Dokan vendor data.
	*/
	if (in_array( 'dokan-lite/dokan.php', apply_filters( 'active_plugins', get_option( 'active_plugins' )))) {
		function get_dokanvendor_data($get_items, $item_meta_data){
			foreach($get_items as $_get_items){
				$itemid = $_get_items->get_id();
				foreach($item_meta_data as $meta_data_item) {
					$vendor_key = $meta_data_item->get_data();
					if($vendor_key['key'] && $vendor_key['key'] == '_dokan_vendor_id'){
						$vendorid = $meta_data_item->value;
						$usermetadata = get_user_meta( $vendorid, 'dokan_profile_settings', true );
						$userdata = get_userdata( $vendorid );
						/* update dokan vendor information in order item meta */
						wc_update_order_item_meta($itemid,'_vendor_id',$vendorid);
					}
				}
			}
		}
	}
	
	/*
	Woocommerce function for get order detail after processing order.
	*/
	// check if dokan plugin is activated or not.
	if (in_array( 'dokan-lite/dokan.php', apply_filters( 'active_plugins', get_option( 'active_plugins' )))) {
		add_action('woocommerce_thankyou', 'vendor_info', 10, 1);
		function vendor_info( $order_id ) {
			include_once(ABSPATH .'wp-admin/includes/plugin.php');
				$sub_orders = get_children( array( 'post_parent' => $order_id, 'post_type' => 'shop_order' ) );
				if($sub_orders && $sub_orders != ''){
					foreach($sub_orders as $_sub_orders){
						$order = wc_get_order( $_sub_orders->ID );
						$get_items = $order->get_items();
						$item_meta_data = $order->get_meta_data();
						get_dokanvendor_data($get_items, $item_meta_data);	 // call Dokan vendor data function.
					}
				}else{
					$order = wc_get_order( $order_id );
					$get_items = $order->get_items();
					$item_meta_data = $order->get_meta_data();
					get_dokanvendor_data($get_items, $item_meta_data);	// call Dokan vendor data function.
				}

		}
	}
	
	/*
	Woocommerce function for remove extra vendor data on order received page.
	*/
	add_filter( 'woocommerce_order_item_get_formatted_meta_data', 'unset_specific_order_item_meta_data', 10, 2);
	function unset_specific_order_item_meta_data($formatted_meta, $item){
		foreach($formatted_meta as $key => $meta){
			if( in_array( $meta->key, array('vendor_phone', 'vendor_email', 'vendor_street', 'vendor_city', 'vendor_zip', 'vendor_country') ) ){
				unset($formatted_meta[$key]);
			}
		}
		return $formatted_meta;
	}
	
	/*
	Function for add extra data in woocommerce orders webhook.
	*/
	/*
	Check if WCFM plugin is activated.
	*/
	if (in_array( 'wc-multivendor-marketplace/wc-multivendor-marketplace.php', apply_filters( 'active_plugins', get_option( 'active_plugins' )))) {
		function wcfmm_add_vendor_info_in_rest_order($response_data){
			$vendor_ids = [];
			foreach ( $response_data as $data ) {
				if ( empty( $data['line_items'] ) ) {
					continue;
				}
				foreach ( $data['line_items'] as $item ) {
					$product_id = ! empty( $item['product_id'] ) ? $item['product_id'] : 0;
					$vendor_id  = get_post_field( 'post_author', $product_id );

					if ( $vendor_id && ! in_array( $vendor_id, $vendor_ids ) ) {
						array_push( $vendor_ids, $vendor_id );
					}
				}
			}

			if ( ! $vendor_ids ) {
				return $response_data;
			}

			$data = $response_data->get_data();
			
			foreach ( $vendor_ids as $store_id ) {
				$name = get_user_meta( $store_id, 'nickname', true );
				$shop_name = get_user_meta( $store_id, 'store_name', true );
				$street1 = get_user_meta( $store_id, '_wcfm_street_1', true );
				$street2 = get_user_meta( $store_id, '_wcfm_street_2', true );
				$city = get_user_meta( $store_id, '_wcfm_city', true );
				$zip = get_user_meta( $store_id, '_wcfm_zip', true );
				$country = get_user_meta( $store_id, '_wcfm_country', true );
				$state = get_user_meta( $store_id, '_wcfm_state', true );
				$usermetadata = get_user_meta( $store_id, 'wcfmmp_profile_settings', true );
				// $vendor_email = $usermetadata['user_email'];
				$vendor_phone = $usermetadata['phone'];
				$data['store_data'][] = [
					'id'        => $store_id,
					'name'      => $name,
					'shop_name' => $shop_name,
					'address'   => array(
						'street_1'		=> $street1,
						'street_2'		=> $street2,
						'city'			=> $city,
						'zip'			=> $zip,
						'country'		=> $country,
						'state'			=> $state,
						'phone'			=> $vendor_phone,
					)
				];
				$data['plugin'] = 'wcfm';
			}
			$response_data->set_data($data);
			return $response_data;
			
		}
		add_filter( 'woocommerce_rest_prepare_shop_order_object', 'wcfmm_add_vendor_info_in_rest_order', 10, 1 );
	}
	/*
	Check if Dokan plugin is activated.
	*/
	if (in_array( 'dokan-lite/dokan.php', apply_filters( 'active_plugins', get_option( 'active_plugins' )))) {
		function dokann_add_vendor_info_in_rest_order($response_data){
			$vendor_ids = [];
			foreach ( $response_data as $data ) {
				if ( empty( $data['line_items'] ) ) {
					continue;
				}
				foreach ( $data['line_items'] as $item ) {
					$product_id = ! empty( $item['product_id'] ) ? $item['product_id'] : 0;
					$vendor_id  = get_post_field( 'post_author', $product_id );

					if ( $vendor_id && ! in_array( $vendor_id, $vendor_ids ) ) {
						array_push( $vendor_ids, $vendor_id );
					}
				}
			}

			if ( ! $vendor_ids ) {
				return $response_data;
			}

			$data = $response_data->get_data();
			foreach ( $vendor_ids as $key => $store_id ) {
				$name = get_user_meta( $store_id, 'nickname', true );
				$shop_name = get_user_meta( $store_id, 'dokan_store_name', true );
				$usermetadata = get_user_meta( $store_id, 'dokan_profile_settings', true );
				$userdata = get_userdata( $store_id );
				$vendor_email = $userdata->user_email;
				$street1 = $usermetadata['address']['street_1'];
				$street2 = $usermetadata['address']['street_2'];
				$city = $usermetadata['address']['city'];
				$zip = $usermetadata['address']['zip'];
				$state = $usermetadata['address']['state'];
				$country = $usermetadata['address']['country'];
				$vendor_phone = $usermetadata['phone'];
				$data['store_data'][] = [
					'id'        => $store_id,
					'name'      => $name,
					'shop_name' => $shop_name,
					'address'   => array(
						'street_1'		=> $street1,
						'street_2'		=> $street2,
						'city'			=> $city,
						'zip'			=> $zip,
						'country'		=> $country,
						'state'			=> $state,
						'phone'			=> $vendor_phone,
					)
				];
				$data['plugin'] = 'dokan';
			}
			$response_data->set_data($data);
			return $response_data;
		}
		add_filter( 'woocommerce_rest_prepare_shop_order_object', 'dokann_add_vendor_info_in_rest_order', 10, 1 );
	}

}