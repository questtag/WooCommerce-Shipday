<?php
/*
Plugin Name: WooCommerce Shipday
Plugin URI: http://localhost/woocommerce
Version: 0.1.0
Description: Allows you to add shipday API configuration and create connection with shipday. Then anyone places any order to the WooCommerce site it should also appear on your Shipday dispatch dashboard.
Author: moinislam
Author URI: http://localhost/woocommerce
Text Domain: woocommerce-shipday
*/

/**************************************************
	To check if WooCommerce plugin is actived
***************************************************/

 
if (in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' )))) {


	add_action( 'woocommerce_settings_tabs', 'sd_add_shipday_setting_tab' );

	function sd_add_shipday_setting_tab() {
	   //link to shipday tab
	   $current_tab = ( isset($_GET['tab']) && $_GET['tab'] == 'shipday' ) ? 'nav-tab-active' : '';
	   echo '<a href="admin.php?page=wc-settings&tab=shipday" class="nav-tab '.$current_tab.'">'.__( "Shipday", "domain" ).'</a>';
	}

	add_action( 'woocommerce_settings_shipday', 'sd_shipday_tab_content' );
	
	
	/*******************************************************
		Function to create & update woocommerce webhook 
	*******************************************************/
	
	function sd_create_webhook( $postdata ){
		
			$shipday_key 		= 	$postdata['shipday_key'];
			$business_name 		= 	$postdata['business_name'];
			$pickup_address 	= 	$postdata['pickup_address'];
			$pickup_phone 		= 	$postdata['pickup_phone'];
			
			$webhook_name 		= 	"Shipday Webhook";
			$webhook_status 	= 	"active";
			$webhook_topic 		= 	"order.updated";
			$api_version 		= 	"3";
			
			$delivery_url = "https://integration.shipday.com/integration/woocommerce/delegateOrder?key=$shipday_key&businessname=$business_name&pickupaddress=$pickup_address&pickupphone=$pickup_phone";
			
			check_admin_referer( 'woocommerce-settings' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have permission to update Webhooks', 'woocommerce' ) );
			}

			$errors  = array();
			
			// check if webhook already exist
			$webhook_exist = wc_get_webhook($postdata['webhook_id']);
						
			$webhook_id = (isset( $postdata['webhook_id'] ) && $webhook_exist )? absint( $postdata['webhook_id'] ) : 0;  
					
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
				wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=shipday&section=webhooks&edit-webhook=' . $webhook->get_id() . '&error=' . rawurlencode( implode( '|', $errors ) ) ) );
				exit();
				
			} elseif ( isset( $webhook_status ) && 'active' === $webhook_status && $webhook->get_pending_delivery() ) { 
				// Ping the webhook at the first time that is activated.
				$result = $webhook->deliver_ping();

				if ( is_wp_error( $result ) && $result->get_error_message() != "Error: Delivery URL returned response code: 202") {
					
					// Redirect to shipday edit page with errors
					wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=shipday&section=webhooks&edit-webhook=' . $webhook->get_id() . '&error=' . rawurlencode( $error ) ) );
					exit();
				}
			}
			
			// Redirect to shipday edit page
			wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=shipday&section=webhooks&edit-webhook=' . $webhook->get_id() . '&updated=1' ) );
			exit();
			
	}
	
	/***************************************************
		Function to display Shipday API form
	***************************************************/
	
	function sd_shipday_tab_content() {
		
		if(isset($_POST['save'])){
			
			$postdata = $_POST;
			
			foreach($postdata as $k => $v){
				
				if($k != 'save'){
					if(get_option( $k )!== false) {
						// The option already exists, so we just update it.
						if(update_option( $k, $v )){
							$success_msg = true;
						}
					}else{
						if(add_option( $k, $v )){
							$success_msg = true;
						}else{
							$error_msg = true;
						}
					}
				}
			}
			
			sd_create_webhook($postdata);
			
				
		}
		
		echo '<h2>Shipday Settings</h2>
				<div id="store_address-description">
					<p>Login to your QuestTag account to get the API key. It’s in the following, My Account > Profile > Api key</p>
				</div>
				<table class="form-table">
				<tbody><tr valign="top">
					<th scope="row" class="titledesc">
						<label for="shipday_key">Shipday API Key</label>
					</th>
					<td class="forminp forminp-text">
						<input name="shipday_key" id="shipday_key" type="text" value="'.get_option('shipday_key').'" required=""> 							
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="business_name">Business Name</label>
					</th>
					<td class="forminp forminp-text">
						<input name="business_name" id="business_name" type="text" value="'.get_option('business_name').'"> 						
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="pickup_address">Pickup Address</label>
					</th>
					<td class="forminp forminp-text">
						<input name="pickup_address" id="pickup_address" type="text" value="'.get_option('pickup_address').'"> 							
					</td>
				</tr>
									
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="pickup_phone">Pickup Phone Number</label>
					</th>
					<td class="forminp forminp-text">
						<input name="pickup_phone" id="pickup_phone" type="text" style="min-width:50px;" value="'.get_option('pickup_phone').'"> 							
					</td>
					<input type="hidden" id="webhook_id" name="webhook_id" value="'.get_option('webhook_id').'">
				</tr>
				</tbody>
			</table>';
	}
	
	// woocommerce delete webhook callback 
	function action_woocommerce_delete_webhook( $id, $webhook ) { 
		
		if(get_option('webhook_id') == $id){
			
			update_option( 'webhook_id',  "");
			
		}
		
	} 
			 
	add_action( 'woocommerce_webhook_deleted', 'action_woocommerce_delete_webhook', 10, 2 ); 
	
}