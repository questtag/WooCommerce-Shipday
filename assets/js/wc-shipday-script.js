/*
jQuery script for show hide pickup address and other options according to the selected pickup location.
*/
jQuery(document).ready(function(){
	
	jQuery("#wc_settings_tab_shipday_vendor_type").closest('tr').hide();
	jQuery("#wc_settings_tab_shipday_vendor_type").hide();
	var checkselect_val = jQuery("#wc_settings_tab_shipday_location").val();
	
	if(checkselect_val == 'single_pickup'){
		jQuery(".wcs_single_business_data").show();
		jQuery(".wcs_single_business_pickdata").hide();
		jQuery(".wcs_single_business_pickdata").closest('tr').hide();
	}else{
		jQuery(".wcs_single_business_data").hide();
		jQuery(".wcs_single_business_data").closest('tr').hide();
		jQuery("#wc_settings_tab_shipday_vendor_type").closest('tr').show();
		jQuery("#wc_settings_tab_shipday_vendor_type").show();
	}
	
	// Select Shipday location on change event.
	jQuery("#wc_settings_tab_shipday_location").change(function(){
		if(this.value == 'multiple_pickup'){
			jQuery(".wcs_single_business_data").hide();
			jQuery(".wcs_single_business_data").closest('tr').hide();
			jQuery("#wc_settings_tab_shipday_vendor_type").closest('tr').show();
			jQuery("#wc_settings_tab_shipday_vendor_type").show();
		}else{
			jQuery(".wcs_single_business_data").show();
			jQuery("#wc_settings_tab_shipday_vendor_type").closest('tr').hide();
			jQuery(".wcs_single_business_data").closest('tr').show();
			jQuery(".wcs_single_business_pickdata").hide();
			jQuery(".wcs_single_business_pickdata").closest('tr').hide();
		}
	});
	
});