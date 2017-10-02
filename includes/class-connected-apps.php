<?php

/**
 * Gravity Flow
 *
 * @package     GravityFlow
 * @copyright   Copyright (c) 2015-2017, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.8.1
 */
class Gravity_Flow_Connected_Apps {

	/**
	 * Holds connection status keys and details
	 *
	 * @var array
	 */
	protected $oauth_connection_statuses = array();

	/**
	 * A holder for the instance
	 *
	 * @var object
	 */
	public static $_instance;

	/**
	 * The Oauth1 Client
	 *
	 * @var Gravity_Flow_Oauth1_Client
	 */
	protected $oauth1_client;

	/**
	 * The ID of the app currently being processed.
	 *
	 * @var string
	 */
	protected $current_app_id;

	/**
	 * The app config currently being processed.
	 *
	 * @var array
	 */
	protected $current_app;

	/**
	 * Add actions and set up connection statuses.
	 *
	 */
	function __construct() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			add_action( 'wp_ajax_gravity_flow_reauth_app', array( $this, 'reauthorize_app' ) );
		} else {
			add_action( 'admin_init', array( $this, 'maybe_process_auth_flow' ) );
		}
	}

	/**
	 * Wrap status messages in styled spans.
	 *
	 * @param string $message Incoming status message should be one-two word string.
	 *
	 * @return string
	 */
	public function wrap_status_message( $message ) {
		return '<span class="oauth-' . strtolower( str_replace( ' ', '-', $message ) ) . '">' . esc_html( $message ) . '</span>';
	}

	/**
	 * Clears the current credentials so that it can be reauthorized.
	 *
	 */
	function reauthorize_app() {
		check_admin_referer( 'gflow_settings_js', 'security' );
		$app_id = sanitize_text_field( rgpost( 'app' ) );
		$app = $this->get_app( $app_id );
		$new_app = array(
			'app_id' => $app['app_id'],
			'app_name' => $app['app_name'],
			'api_url' => $app['api_url'],
			'app_type' => $app['app_type'],
			'status' => 'Not Verified',
		);
		$this->update_app( $app['app_id'], $new_app );
		wp_send_json( array(
			'success' => true,
			'app' => 'ready for reauth',
		) );
	}

	function maybe_process_auth_flow() {
		if ( ( isset( $_POST['gflow_add_app'] )
		         || isset( $_POST['gflow_authorize_app'] )
		         || isset( $_GET['oauth_verifier'] ) )
		) {
			$this->process_auth_flow();
		}
	}

	/**
	 * Processes Auth settings, initial run creates unique_id and app
	 * subsequent run processes the authorization
	 *
	 */
	function process_auth_flow() {

		$adding_app = rgpost( 'gflow_add_app' ) === 'Next';
		$authorizing_app = rgpost( 'gflow_authorize_app' ) === 'Authorize App';

		if ( $authorizing_app || isset( $_GET['oauth_verifier'] ) ) {

			$this->current_app_id = sanitize_text_field( rgget( 'app' ) );
			$this->current_app    = $this->get_app( $this->current_app_id );

			if ( $authorizing_app && ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'nonce_authorize_app' ) ) {
				wp_die( 'Failed Security Check - refresh page and try again' );
			}

			if ( isset( $_POST['app_type'] ) ) {
				$process_func = sprintf( 'process_auth_%s', sanitize_text_field( $_POST['app_type'] ) );
			} else {
				$process_func = sprintf( 'process_auth_%s', $this->current_app['app_type'] );
			}
			if ( is_callable( array( $this, $process_func ) ) ) {
				$this->$process_func();
			} else {
				gravity_flow()->log_debug( __METHOD__ . '() - processing function ' . $process_func . ' not callable' );
			}
		} elseif ( $adding_app ) {
			if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'nonce_create_app' ) ) {
				wp_die( 'Failed Security Check - refresh page and try again' );
			}

			$app_name = sanitize_text_field( $_POST['app_name'] );
			$app_type = sanitize_text_field( $_POST['app_type'] );
			$app_api_url = esc_url_raw( $_POST['api_url'] );

			$new_app = array(
				'app_name' => $app_name,
				'api_url' => $app_api_url,
				'app_type' => $app_type,
				'status' => 'Not Verified',
			);

			$app_id = $this->add_app( $new_app );
			wp_safe_redirect( add_query_arg( 'app', esc_js( $app_id ) ) );
		}
	}

	/**
	 * Handles OAuth1 authentication.
	 *
	 * The callback_url in the client constructor must be registered in the WP-Api application callback_url
	 * So for the docs the current web address of the step setting form is taken and used to setup the application and put into
	 * the callback url field.
	 *
	 */
	public function process_auth_wp_oauth1() {

		if ( ! ( isset( $_POST['consumer_key'] )
		         || isset( $_POST['app_name'] )
		         || isset( $_GET['app'] )
		         || isset( $_GET['oauth_verifier'] ) )
		) {
			return;
		}

		require_once( 'class-oauth1-client.php' );
		$current_app_id = wp_unslash( sanitize_text_field( rgget( 'app' ) ) );

		if ( isset( $_POST['consumer_key'] ) || isset( $_POST['app_name'] ) ) {
			$reauth = false;
			$current_app = $this->get_app( $current_app_id );
			if ( $_POST['app_name'] !== $current_app['app_name'] || $_POST['api_url'] !== $current_app['api_url'] ) {
				$current_app['app_name'] = sanitize_text_field( $_POST['app_name'] );
				$current_app['api_url'] = sanitize_text_field( $_POST['api_url'] );
			}
			if ( rgpost( 'consumer_key' ) !== rgar( $current_app, 'consumer_key' ) || rgpost( 'consumer_secret' ) !== rgar( $current_app, 'consumer_secret' ) ) {
				$current_app['consumer_key'] = sanitize_text_field( $_POST['consumer_key'] );
				$current_app['consumer_secret'] = sanitize_text_field( $_POST['consumer_secret'] );
				$reauth = true;
			}
			$this->update_app( $current_app_id, $current_app );
			if ( ! $reauth ) {
				wp_safe_redirect( remove_query_arg( '' ) );
			}
		}
		$this->current_app_id = $current_app_id;
		$this->setup_oauth1_client();
		$this->status_keys = array_keys( $this->get_connection_statuses() );
		if ( ! isset( $_GET['oauth_verifier'] ) ) {
			$this->process_oauth1_outward_leg();
		} else {
			$this->process_oauth1_return_legs();
		}

	}

	/**
	 * Process oauth1 - authorize returning user
	 *
	 */
	function process_oauth1_return_legs() {
		$status = $this->status_keys[1];
		$app_ident = $this->current_app_id;
		if ( empty( $_GET['oauth_verifier'] ) || ! isset( $_GET['oauth_token'] ) || empty($_GET['oauth_token'] ) ) {
			update_option( "gflow_conn_app_status_{$app_ident}_{$status}", 'FAILED' );
		} elseif ( ! get_transient( $app_ident . '_temp_creds_secret_' . get_current_user_id() ) ) {
			update_option( "gflow_conn_app_status_{$app_ident}_{$status}", 'FAILED' );
		} else {
			update_option( "gflow_conn_app_status_{$app_ident}_{$status}", 'SUCCESS' );
			$status = $this->status_keys[2];
			try {
				$this->oauth1_client->config['token'] = $_GET['oauth_token'];
				$this->oauth1_client->config['token_secret'] = get_transient( $app_ident . '_temp_creds_secret_' . get_current_user_id() );
				$access_credentials = $this->oauth1_client->request_access_token( $_GET['oauth_verifier'] );
				update_option( "gflow_conn_app_status_{$app_ident}_{$status}", 'SUCCESS' );
				$this->current_app['access_creds'] = $access_credentials;
				$this->current_app['status'] = 'Verified';
				$this->update_app( $app_ident, $this->current_app )	;
			} catch ( Exception $e ) {
				update_option( "gflow_conn_app_status_{$app_ident}_{$status}", 'FAILED' );
				gravity_flow()->log_debug( __METHOD__ . '() - Exception caught ' . $e->getMessage() );
			}
		}
		$url = remove_query_arg( array( 'oauth_token', 'oauth_verifier', 'wp_scope' ) );
		wp_safe_redirect( $url );
	}

	/**
	 * Process oauth1 - sending user to site for authorization
	 *
	 */
	function process_oauth1_outward_leg() {
		$status = $this->status_keys[0];
		$app_ident = $this->current_app_id;
		$temp_creds = $this->get_temp_creds( $app_ident );

		if ( false === $temp_creds ) {
			update_option( "gflow_conn_app_status_{$app_ident}_{$status}", 'FAILED' );
			wp_safe_redirect( remove_query_arg( '' ) );
			exit;
		} else {
			set_transient( $app_ident . '_temp_creds_secret_' . get_current_user_id(), $temp_creds['oauth_token_secret'], HOUR_IN_SECONDS );
			update_option( "gflow_conn_app_status_{$app_ident}_{$status}", 'SUCCESS' );

			$auth_app_page = esc_url_raw( add_query_arg( $temp_creds, $this->oauth1_client->api_auth_urls['oauth1']['authorize'] ) );
			?><script>
				window.onload = function() {
					window.location = <?php echo json_encode( $auth_app_page ); ?>;
				}
			</script>
			<?php
		}
	}

	/**
	 * Helper to use the consumer key and secret entered to get temporary credentials
	 * and then allow the user to authorize the webhook's connection using those credentials.
	 *
	 * @param string $app_ident the app getting creds for.
	 *
	 * @return string
	 */
	function get_temp_creds( $app_ident ) {
		try {
			$temporary_credentials = $this->oauth1_client->request_token();
			return $temporary_credentials;
		} catch ( Exception $e ) {
			gravity_flow()->log_debug( __METHOD__ . '() - Exception caught ' . $e->getMessage() );
			return false;
		}
		return false;
	}

	/**
	 * Configure and construct oauth1 client.
	 */
	function setup_oauth1_client() {
		try {
			$app = $this->get_app( $this->current_app_id );
			$this->oauth1_client = new Gravity_Flow_Oauth1_Client(
				array(
					'consumer_key' => $app['consumer_key'],
					'consumer_secret' => $app['consumer_secret'],
					'callback_url' => add_query_arg( array( 
						'page' => 'gravityflow_settings',
						'view' => 'connected_apps',
						'app' => $this->current_app_id,
					), esc_url( admin_url( 'admin.php' ) ) ),
				),
				'gravi_flow_' . $app['consumer_key'],
				$app['api_url']
			);

		} catch ( Exception $e ) {
			gravity_flow()->log_debug( __METHOD__ . '() - Exception caught ' . $e->getMessage() );
			$url = remove_query_arg( array( 'oauth_token', 'oauth_verifier', 'wp_scope' ) );
			wp_safe_redirect( $url );

		}
	}

	/**
	 * Instantiate class from outside
	 *
	 * @return object
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Returns an associative array of statuses plus labels.
	 *
	 * @return array
	 */
	function get_connection_statuses() {
		return array(
			'get_temporary_credentials' => __( 'Using Consumer Key and Secret to Get Temporary Credentials', 'gravityflow' ),
			'user_authorize_app' => __( 'Redirecting for user authorization - you may need to login first', 'gravityflow' ),
			'get_access_credentials' => __( 'Using credentials from user authorization to get permanent credentials', 'gravityflow' ),
		);
	}

	/**
	 * Returns an array of connected apps.
	 */
	function get_connected_apps() {
		global $wpdb;

		$table  = $wpdb->options;

		$key = 'gflow_conn_app_config_%';

		$results = $wpdb->get_results( $wpdb->prepare( "
			SELECT *
			FROM {$table}
			WHERE option_name LIKE %s
		", $key ) );

		$apps = array();

		foreach ( $results as $result ) {
			$app = maybe_unserialize( $result->option_value );
			$apps[ $app['app_id'] ] = $app;
		}

		return $apps;
	}

	/**
	 * @param $app_id
	 *
	 * @return array
	 */
	function get_app( $app_id ) {
		return get_option( 'gflow_conn_app_config_' . $app_id, array() );
	}

	function delete_app( $app_id ) {

		if ( empty( $app_id ) || ! is_string( $app_id ) ) {
			return false;
		}

		// Delete the option with the app settings.
		delete_option( 'gflow_conn_app_config_' . $app_id );

		// Delete statuses
		$statuses = $this->get_connection_statuses();
		foreach ( array_keys( $statuses ) as $key ) {
			delete_option( 'gflow_conn_app_status_' . $app_id . $key );
		}
		return true;
	}

	/**
	 * Adds an app.
	 *
	 * @param $app_config
	 *
	 * @return string
	 */
	function add_app( $app_config ) {
		$app_id = uniqid();
		$app_config['app_id'] = $app_id;
		add_option( 'gflow_conn_app_config_' . $app_id, $app_config );
		return $app_id;
	}

	/**
	 * Updates an app.
	 *
	 * @param $app_config
	 *
	 * @return string
	 */
	function update_app( $app_id, $app_config ) {
		$app_config['app_id'] = $app_id;
		update_option( 'gflow_conn_app_config_' . $app_id, $app_config );
		return $app_id;
	}
}

/**
 * Returns the instance of the Gravity_Flow_Connected_Apps class.
 *
 * @return Gravity_Flow_Connected_Apps
 */
function gravityflow_connected_apps() {
	return Gravity_Flow_Connected_Apps::instance();
}

gravityflow_connected_apps();

/**
 * Class Gravity_Flow_Connected_Apps_Table
 */
class Gravity_Flow_Connected_Apps_Table extends WP_List_Table {

	/**
	 * @var array
	 */
	protected $_apps = array();

	/**
	 * Gravity_Flow_Connected_Apps_Table constructor.
	 *
	 * @param array $args
	 */
	function __construct( $args = array() ) {
		$cols = $this->get_columns();
		$this->_apps = gravityflow_connected_apps()->get_connected_apps();
		$this->_column_headers = array(
			$cols,
			array(),
			array(),
		);
		parent::__construct(
			array(
				'singular' => esc_html__( 'App', 'gravityforms' ),
				'plural'   => esc_html__( 'Apps', 'gravityforms' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Prepares items for the table.
	 */
	function prepare_items() {
		$this->items = $this->_apps;
	}

	/**
	 * Returns the columns for the table.
	 *
	 * @return array
	 */
	function get_columns() {
		return array(
			'app_name' => esc_html__( 'App Name', 'gravityflow' ),
			'app_type' => esc_html__( 'Type', 'gravityflow' ),
			'status' => esc_html__( 'Status', 'gravityflow' ),
		);
	}

	/**
	 * Outputs the no items message.
	 */
	function no_items() {
		esc_html_e( 'You don\'t have any connected apps configured.', 'gravityflow' );
	}

	/**
	 * Outputs the App Name.
	 *
	 * @param $item
	 */
	function column_app_name( $item ) {
		echo $item['app_name'];
	}

	/**
	 * Outputs the App Type.
	 *
	 * @param $item
	 */
	function column_app_type( $item ) {
		switch ( $item['app_type'] ) {
			case 'wp_oauth1' :
				esc_html_e( 'WordPress OAuth1', 'gravityflow' );
				break;
			default :
				echo $item['app_type'];
		}
	}

	/**
	 * Outputs the status.
	 *
	 * @param $item
	 */
	function column_status( $item ) {
		echo $item['status'];
	}

	/**
	 * Returns the row actions.
	 *
	 * @param object $item
	 * @param string $column_name
	 * @param string $primary
	 *
	 * @return string
	 */
	function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$edit_url          = esc_url( add_query_arg( array(
			'app' => $item['app_id'],
		), remove_query_arg( 'delete' ) ) );
		$delete_url        = esc_url( add_query_arg( array(
			'app'    => $item['app_id'],
			'delete' => true,
			'_nonce' => wp_create_nonce( 'gflow_delete_app' ),
		) ) );
		$actions           = array();
		$actions['edit']   = '<a href="' . $edit_url . '">' . esc_html__( 'Edit', 'gravityflow' ) . '</a>';
		$actions['delete'] = "<a class='submitdelete' href='" . $delete_url . "' onclick=\"if ( confirm( '" . esc_js( sprintf( __( "You are about to delete this app '%s'\n  'Cancel' to stop, 'OK' to delete." ), $item['app_name'] ) ) . "' ) ) { return true;}return false;\">" . __( 'Delete' ) . "</a>";

		return $this->row_actions( $actions );
	}
}
