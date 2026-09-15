<?php
/** WordPress updater for WSS WooCommerce Bookings Free. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WSS_WC_Bookings_Free_Updater {
	private const PRODUCT_ID = 'wss_woocommerce_bookings_free';
	private const UPDATE_ENDPOINT = 'https://website-support.ru/wp-json/wss-updates/v1/check';
	private const TRANSIENT_KEY = 'wss_wc_bookings_free_update_info';
	private string $plugin_file;
	private string $plugin_basename;
	private string $version;
	private string $homepage;
	public function __construct( string $plugin_file, string $version, string $homepage ) {
		$this->plugin_file=$plugin_file; $this->plugin_basename=plugin_basename($plugin_file); $this->version=$version; $this->homepage=$homepage;
		add_filter('pre_set_site_transient_update_plugins',array($this,'inject_plugin_update'));
		add_filter('plugins_api',array($this,'plugins_api_info'),20,3);
	}
	public function inject_plugin_update($transient) {
		if(!is_object($transient)){return $transient;}
		$update=$this->get_update_info();
		if(empty($update['new_version'])||empty($update['package'])||version_compare($this->version,(string)$update['new_version'],'>=')){return $transient;}
		if(!isset($transient->response)||!is_array($transient->response)){$transient->response=array();}
		$transient->response[$this->plugin_basename]=(object)array('slug'=>dirname($this->plugin_basename),'plugin'=>$this->plugin_basename,'new_version'=>(string)$update['new_version'],'url'=>(string)($update['homepage']??$this->homepage),'package'=>(string)$update['package']);
		return $transient;
	}
	public function plugins_api_info($result,string $action,object $args){
		if('plugin_information'!==$action||empty($args->slug)||dirname($this->plugin_basename)!==$args->slug){return $result;}
		$update=$this->get_update_info();
		return (object)array('name'=>'WSS WooCommerce Bookings','slug'=>dirname($this->plugin_basename),'version'=>(string)($update['new_version']??$this->version),'author'=>'<a href="https://website-support.ru/">WSS</a>','homepage'=>(string)($update['homepage']??$this->homepage),'download_link'=>(string)($update['package']??''),'sections'=>array('description'=>'WSS WooCommerce Bookings'));
	}
	private function get_update_info():array{
		$cached=get_site_transient(self::TRANSIENT_KEY); if(is_array($cached)){return $cached;}
		$response=wp_remote_get(add_query_arg(array('product'=>self::PRODUCT_ID,'version'=>$this->version,'domain'=>home_url()),self::UPDATE_ENDPOINT),array('timeout'=>15));
		if(is_wp_error($response)||200!==(int)wp_remote_retrieve_response_code($response)){return array();}
		$body=json_decode((string)wp_remote_retrieve_body($response),true); if(!is_array($body)||empty($body['success'])){return array();}
		set_site_transient(self::TRANSIENT_KEY,$body,6*HOUR_IN_SECONDS); return $body;
	}
}
