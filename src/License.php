<?php
namespace Fusion_Addons\Plugin;

/**
 * if accessed directly, exit.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @package Plugin
 * @subpackage License
 * @author Fusion_Addons <hi@fusion10.io>
 */
class License {
	
	public $slug;
	
	public $plugin;
	
	public $name;
	
	public $license_page;

	/**
	 * Is it in the validating state?
	 */
	public $validating = false;

	public function __construct( $plugin ) {

		$this->plugin 	= $plugin;

		$this->server 		= untrailingslashit( $this->plugin['server'] );
		$this->slug 		= $this->plugin['TextDomain'];
		$this->name 		= $this->plugin['Name'];
		$this->license_page = isset( $this->plugin['license_page'] ) ? $this->plugin['license_page'] : admin_url( "admin.php?page={$this->slug}" );
		
		if( isset( $this->plugin['updatable'] ) && $this->plugin['updatable'] ) {
			$this->plugin['license'] = $this;
			$update	= new Update( $this->plugin );
		}

		self::hooks();
	}

	public function hooks() {
		register_activation_hook( __FILE__, [ $this, 'install' ] );
		add_action( 'fusion10-daily', [ $this, 'validate' ] );
		add_action( 'admin_init', [ $this, 'init' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ], 99 );
		add_action( 'plugins_loaded', [ $this, 'gather_notices' ] );
		add_action( 'rest_api_init', [ $this, 'register_endpoints' ] );
	}

	/**
	 * Installer. Runs once when the plugin in activated.
	 *
	 * @since 1.0
	 */
	public function install() {
		/**
		 * Schedule an event to sync help docs
		 */
		if ( ! wp_next_scheduled ( 'fusion10-daily' )) {
		    wp_schedule_event( time(), 'daily', 'fusion10-daily' );
		}
	}

	public function validate() {
		if( $this->_is_activated() ) {

			/**
			 * It's in the validating state
			 */
			$this->validating = true;

			$validation = $this->do( 'check', $this->get_license_key(), $this->name ) ;
			if( $validation['status'] != true ) {
				update_option( $this->get_license_status_name(), 'invalid' );
			}
			else {
				update_option( $this->get_license_status_name(), 'valid' );
				update_option( $this->get_license_expiry_name(), ( $validation['data']->expires == 'lifetime' ? 4765132799 : strtotime( $validation['data']->expires ) ) );
			}
		}
	}

	public function init() {
		if( !isset( $_GET['pb-license'] ) ) return;

		if( $_GET['pb-license'] == 'deactivate' ) {
			if( ! wp_verify_nonce( $_GET['pb-nonce'], 'fusion10' ) ) {
				// print an error message. maybe store in a temporary session and print later?
			}
			else {
				$this->do( 'deactivate', $this->get_license_key(), $this->name );
			}
		}

		elseif( $_GET['pb-license'] == 'activate' ) {
			if( ! wp_verify_nonce( $_GET['pb-nonce'], 'fusion10' ) || $_GET['key'] == '' ) {
				// print an error message. maybe store in a temporary session and print later?
			}
			else {
				$this->do( 'activate', $_GET['key'], $this->name );
			}
		}

		$query = isset( $_GET ) ? $_GET : [];
		foreach ( [ 'item_id', 'item_slug', 'pb-license', 'pb-nonce', 'key' ] as $key ) {
			if( isset( $query[ $key ] ) ) {
				unset( $query[ $key ] );
			}
		}

		wp_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
	}

	public function enqueue_scripts() {
		wp_enqueue_style( 'fusion10-product-license', plugins_url( 'assets/css/license.css', __FILE__ ), [], $this->plugin['Version'] );
	}

	public function gather_notices() {

		if( did_action( "_license_{$this->slug}_notice" ) ) return;
		do_action( "_license_{$this->slug}_notice" );

		global $tan_notices;

		// not activated
		if( ! $this->_is_activated() ) {

			$notice = '';
			$notice .= '<p>' . sprintf( __( '<strong>ALERT:</strong> In order to enjoy the features of <strong>%1$s</strong>, you need to activate the license first. Sorry, but the plugin won\'t work without activation! Please <a href="%2$s">activate it now</a>.', 'fusion10' ), $this->name, $this->get_activation_url() ) . '</p>';

			Notice::add( $notice, 'error', true );
		}

		// about to expire?
		if( $this->_is_activated() && ( time() + apply_filters( 'fusion10-expiry-notice-time', MONTH_IN_SECONDS, $this ) ) > ( $expiry = get_option( $this->get_license_expiry_name() ) ) && time() < $expiry ) {

			$notice = '';
			$notice .= '<p>' . sprintf( __( '<strong>ALERT:</strong> Your license for <strong>%1$s</strong> is about to expire in <strong>%2$s</strong>. The plugin will stop working without a valid license key. <a href="%3$s">Renew your license</a> now and get a special <strong>%4$s discount</strong>!', 'fusion10' ), $this->name, human_time_diff( $expiry, time() ), $this->get_renewal_url(), '20%' ) . '</p>';

			Notice::add( $notice, 'error', true );
		}

		// expired to invalid license?
		if( $this->_is_activated() && ( $this->_is_invalid() || $this->_is_expired() ) && apply_filters( 'fusion10-show_validation_notice', true, $this->plugin ) ) {

			$notice = '';
			$notice .= '<p>' . sprintf( __( '<strong>WARNING:</strong> It looks like <strong>%1$s</strong> can\'t connect to our server and is unable to receive updates! The plugin might stop working if it\'s not connected. <a href="%2$s">Reconnect Now</a>.', 'fusion10' ), $this->name, $this->get_deactivation_url() ) . '</p>';

			Notice::add( $notice, 'warning', true );
		}
	}

	public function activation_form() {
		$html = '';

		if( ! $this->_is_activated() ) {
			$activation_url = $this->get_activation_url();
			$activate_label	= apply_filters( "{$this->slug}_activate_label", __( 'Activate', 'fusion10' ), $this->plugin );

			$html .= '<p class="tan-desc">' . sprintf( __( 'Thanks for installing <strong>%1$s</strong> 👋', 'fusion10' ), $this->name ) . '</p>';
			$html .= '<p class="tan-desc">' . __( 'In order to make the plugin work, you need to activate the license by clicking the button below. Please reach out to us if you need any help.', 'fusion10' ) . '</p>';
			$html .= "<a id='tan-activate' class='tan-button button button-primary' href='{$activation_url}'>" . $activate_label . "</a>";
		}

		else {
			$deactivation_url	= $this->get_deactivation_url();
			$deactivate_label	= apply_filters( "{$this->slug}_deactivate_label", __( 'Deactivate', 'fusion10' ), $this->plugin );
			$license_meta		= get_option( $this->get_license_meta_name() );
			
			$html .= '<p class="tan-desc">' . sprintf( __( 'Congratulations! Your license for <strong>%s</strong> is activated. 🎉', 'fusion10' ), $this->name ) . '</p>';
			
			
			if( isset( $license_meta->customer_name ) ) {
				$html .= '<p class="tan-info">' . sprintf( __( 'Name: %s', 'fusion10' ), $license_meta->customer_name ) . '</p>';
			}

			if( isset( $license_meta->customer_email ) ) {
				$html .= '<p class="tan-info">' . sprintf( __( 'Email: %s', 'fusion10' ), $license_meta->customer_email ) . '</p>';
			}

			if( isset( $license_meta->payment_id ) ) {
				$html .= '<p class="tan-info">' . sprintf( __( 'Order ID: %s', 'fusion10' ), $license_meta->payment_id ) . '</p>';
			}

			$html .= '<p class="tan-info">' . sprintf( __( 'Expiry: %s', 'fusion10' ), $this->get_license_expiry() ) . '</p>';

			$html .= '<p class="tan-info">' . __( 'You can deactivate the license by clicking the button below.', 'fusion10' ) . '</p>';
			$html .= "<a id='tan-deactivate' class='tan-button button button-secondary' href='{$deactivation_url}'>" . $deactivate_label . "</a>";
		}

		return apply_filters( "{$this->slug}_activation_form", $html, $this->plugin );
	}

	// backward compatibility
	public function activator_form() {
		return $this->activation_form();
	}

	public function register_endpoints() {
		register_rest_route( 'fusion10', 'license', [
			'methods'				=> 'GET',
			'callback'				=> [ $this, 'callback_action' ],
			'permission_callback'	=> '__return_true'
		] );
	}

	public function callback_action( $request ) {
		
		add_filter( 'fusion10-is_forced', '__return_true' );
		
		$parameters = $request->get_params();
		return $this->do( $parameters['action'], $parameters['license_key'], $parameters['item_name'] );
	}

	/**
	 * Perform an action
	 *
	 * @param string $action activate|deactivate|check
	 * @param string $item_name the plugin name
	 */
	public function do( $action, $license, $item_name ) {

		if( did_action( "_{$this->slug}_did_license_action" ) && $this->validating !== true ) return;
		do_action( "_{$this->slug}_did_license_action" );

		// for activate and deactivate, if slug doesn't match, abort
		if( in_array( $action, [ 'activate', 'deactivate' ] ) && ( ! isset( $_GET['item_slug'] ) || $_GET['item_slug'] != $this->slug ) ) return;

		$_response = [
			'status'	=> false,
			'message'	=> __( 'Something is wrong', 'fusion10' ),
			'data'		=> []
		];

		// data to send in our API request
		$api_params = [
			'edd_action'	=> "{$action}_license",
			'license'		=> $license,
			'item_name'		=> urlencode( $item_name ),
			'url'			=> home_url()
		];

		$response		= wp_remote_post( $this->server, [ 'timeout' => 15, 'sslverify' => false, 'body' => $api_params ] );
		
		$license_data	= json_decode( wp_remote_retrieve_body( $response ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$_response['message'] = is_wp_error( $response ) ? $response->get_error_message() : __( 'An error occurred, please try again.', 'fusion10' );
		}

		// it's an activation request
		elseif( $action == 'activate' ) {

			// license key is not OK?
			if ( false === $license_data->success ) {
				switch( $license_data->error ) {
					case 'expired' :

						$_response['message'] = sprintf(
							__( 'Your license key expired on %s.', 'fusion10' ),
							date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires, current_time( 'timestamp' ) ) )
						);
						break;

					case 'disabled' :
					case 'revoked' :

						$_response['message'] = __( 'Your license key has been disabled.', 'fusion10' );
						break;

					case 'missing' :

						$_response['message'] = __( 'Invalid license.', 'fusion10' );
						break;

					case 'invalid' :
					case 'site_inactive' :

						$_response['message'] = __( 'Your license is not active for this URL.', 'fusion10' );
						break;

					case 'item_name_mismatch' :

						$_response['message'] = sprintf( __( 'This appears to be an invalid license key for %s.', 'fusion10' ), $item_name );
						break;

					case 'no_activations_left':

						$_response['message'] = __( 'Your license key has reached its activation limit.', 'fusion10' );
						break;

					default :

						$_response['message'] = __( 'An error occurred, please try again.', 'fusion10' );
						break;
				}

			}

			// license key is OK
			else {

				if( isset( $_GET['item_slug'] ) && '' != $_GET['item_slug'] ) {
					$this->slug = $_GET['item_slug'];
				}

				update_option( $this->get_license_key_name(), $license );
				update_option( $this->get_license_status_name(), $license_data->license );
				update_option( $this->get_license_expiry_name(), ( $license_data->expires == 'lifetime' ? 4765132799 : strtotime( $license_data->expires ) ) );
				update_option( $this->get_license_meta_name(), $license_data );

				$_response['status']	= $license_data;
				$_response['message']	= __( 'License activated', 'fusion10' );
			} 

		}

		// it's a deactivation request
		elseif( $action == 'deactivate' ) {
			// if( ( isset( $license_data->license ) && $license_data->license == 'deactivated' ) || $this->_is_forced() ) { // "deactivated" or "failed"

			// 	if( isset( $_GET['item_slug'] ) && '' != $_GET['item_slug'] ) {
			// 		$this->slug = $_GET['item_slug'];
			// 	}

			// 	delete_option( $this->get_license_key_name() );
			// 	delete_option( $this->get_license_status_name() );
			// 	delete_option( $this->get_license_expiry_name() );
			// 	delete_option( $this->get_license_meta_name() );

			// 	$_response['status']	= true;
			// 	$_response['message'] = __( 'License deactivated', 'fusion10' );
			// }

			// want to deactivate? do it first without validating the license
			delete_option( $this->get_license_key_name() );
			delete_option( $this->get_license_status_name() );
			delete_option( $this->get_license_expiry_name() );
			delete_option( $this->get_license_meta_name() );
		}

		// it's a verification request
		elseif( $action == 'check' ) {
			if( isset( $license_data->license ) && $license_data->license == 'valid' ) {
				$_response['status']	= true;
				$_response['message']	= __( 'License valid', 'fusion10' );
				$_response['data']		= $license_data;
				update_option( $this->get_license_meta_name(), $license_data );
			} else {
				$_response['status']	= false;
				$_response['message']	= __( 'License invalid', 'fusion10' );
			}
		}

		return $_response;
	}

	public function get_activation_url() {
		$query					= isset( $_GET ) ? $_GET : [];
		$query['pb-nonce']		= wp_create_nonce( 'fusion10' );

		$activation_url = add_query_arg( [
			'item_id'	=> $this->plugin['item_id'],
			'item_slug'	=> $this->slug,
			'pb-nonce'	=> wp_create_nonce( 'fusion10' ),
			'track'		=> base64_encode( $this->license_page )
		], trailingslashit( $this->get_activation_page() ) );

		return apply_filters( 'fusion10-activation_url', $activation_url, $this->plugin );
	}

	public function get_deactivation_url() {
		$query					= isset( $_GET ) ? $_GET : [];
		$query['item_id']		= $this->plugin['item_id'];
		$query['item_slug']		= $this->slug;
		$query['pb-nonce']		= wp_create_nonce( 'fusion10' );
		$query['pb-license']	= 'deactivate';

		$deactivation_url = add_query_arg( $query, $this->license_page );

		return apply_filters( 'fusion10-deactivation_url', $deactivation_url, $this->plugin );
	}

	public function get_renewal_url() {
		$query = [
			'edd_license_key'	=> $this->get_license_key(),
			'download_id'		=> $this->plugin['item_id'],
		];

		$renewal_url = add_query_arg( $query, trailingslashit( $this->server ) . 'order' );

		return apply_filters( 'fusion10-renewal_url', $renewal_url, $this->plugin );
	}

	public function get_activation_page() {
		return apply_filters( 'fusion10-activation_page', "{$this->server}/connect", $this->plugin );
	}

	// option_key in the wp_options table
	public function get_license_key_name() {
		return "_license_{$this->slug}_key";
	}

	// option_key in the wp_options table
	public function get_license_status_name() {
		return "_license_{$this->slug}_status";
	}

	// option_key in the wp_options table
	public function get_license_expiry_name() {
		return "_license_{$this->slug}_expiry";
	}

	// option_key in the wp_options table
	public function get_license_meta_name() {
		return "_license_{$this->slug}_meta";
	}

	public function get_license_key() {
		return get_option( $this->get_license_key_name() );
	}

	public function get_license_status() {
		return get_option( $this->get_license_status_name() );
	}

	public function get_license_expiry() {
		$expiry = get_option( $this->get_license_expiry_name() );
		
		if( $expiry == 4765132799 ) return 'lifetime';

		return date_i18n( get_option( 'date_format' ), $expiry );
	}

	public function _is_activated() {
		return $this->get_license_key() != '';
	}

	// backward compatibility
	public function _is_active() {
		return $this->_is_activated();
	}

	public function _is_invalid() {
		return $this->get_license_status() != 'valid';
	}

	public function _is_expired() {
		return time() >= get_option( $this->get_license_expiry_name() );
	}

	public function _is_forced() {
		return apply_filters( 'fusion10-is_forced', ( $this->_is_invalid() || $this->_is_expired() ), $this->plugin );
	}
}