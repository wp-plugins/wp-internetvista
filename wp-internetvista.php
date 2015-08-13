<?php
/*
Plugin Name: InternetVista
Plugin URI: http://wordpress.org/plugins/wp-internetvista/
Description: Uptime is money - This plugin allows you to view directly from your WordPress dashboard the performance of your website (uptime and response time).
Version: 1.0
Author: InternetVista <support@internetvista.com>
Author URI: http://www.internetvista.com
*/

/**
 * The instantiated version of this plugin's class
 */
$GLOBALS['wp_internetvista'] = new wp_internetvista;

class wp_internetvista {
	/** This plugin's identifier */
	const ID = 'wp-internetvista';
	const PREFIX = 'wp_internetvista';

	/** Our option name for storing the plugin's settings */
	const OPTION_NAME = 'wp_internetvista_options';

	/** This plugin's name */
	const NAME = 'InternetVista';

	/** This plugin's version */
	const VERSION = '1.0.1';

	/** REST api endpoint */
	const REST_ENDPOINT = 'https://www.internetvista.com/restapi';

	/** Date format used to display/parse date */
	const DATE_FORMAT = 'd/m/Y';

	const REGISTER_URL = 'https://www.internetVista.com/register.htm?nid=121';

	private $_api = false;

	/** Has the internationalization text domain been loaded? */
	protected $loaded_textdomain = false;

	/** The WP privilege level required to use the admin interface. */
	protected $capability_required;

	/** Array of option field, for option form */
	protected $fields;

	/** Array of section, for option form */
	protected $sections;

	private $_statistics = false;

	/** ALL the available interval types. */
	private $_interval_types;

	/**
	 * This plugin's options, options from the database are merged on top of the default options.
	 * @see wp_internetvista::set_options() to obtain the saved settings
	 */
	private $options = array();

	/** This plugin's default options */
	private $options_default = array(
		'login' => null,
		'password' => null,
		'id_app' => null,
		'interval_type' => 'last-30-days',
		'interval_date_start' => null,
		'interval_date_end' => null,
		'interval_date_diff' => null
	);

	private $_tabs;

	private $_tab_current;

	/**
	 * Declares the WordPress action and filter callbacks
	 * @uses wp_internetvista::initialize() to set the object's properties
	 */
	public function __construct() {
		$this->initialize();

		if (is_admin()) {
			// Interval type value
			$this->_interval_types = array(
				'yesterday'		=> __('Yesterday', self::ID),
				'this-week' 	=> __('This week', self::ID),
				'last-7-days' 	=> __('The last 7 days', self::ID),
				'last-week' 	=> __('Last week', self::ID),
				'last-30-days'	=> __('Last 30 days', self::ID),
				'last-month' 	=> __('Last month', self::ID),
				'this-year' 	=> __('This year', self::ID),
				'last-365-days' => __('The last 365 days', self::ID),
				'last-year' 	=> __('Last year', self::ID),
				'date-interval' => __('Between two dates', self::ID)
				//'all'			=> __('Since the beginning', self::ID)
			);

			// Set the field and section to be used in the page_settings form
			$this->set_sections();
			$this->set_fields();

			// Translation already in WP combined with plugin's name.
			$this->text_settings = self::NAME . ' ' . __('Settings');

			// Load translation text domain
			$this->load_plugin_textdomain();

			// Configure properties depending on multi-site
			if (is_multisite()) {
				$admin_menu = 'network_admin_menu';
				$this->capability_required = 'manage_network_options';
				$plugin_action_links = 'network_admin_plugin_action_links_wp-internetvista/wp-internetvista.php';
			} else {
				$admin_menu = 'admin_menu';
				$this->capability_required = 'manage_options';
				$plugin_action_links = 'plugin_action_links_wp-internetvista/wp-internetvista.php';
			}

			// Bind action handling

			// Admin page rendering
			add_action('admin_init', 	array(&$this, 'admin_init'));
			add_action('admin_notices', array(&$this, 'admin_notices'));

			// Menu and link to setting page
			add_action($admin_menu, 		 array(&$this, 'admin_menu'), 10, 2);
			add_filter($plugin_action_links, array(&$this, 'plugin_action_links'));

			// Dashboard widget
			add_action('wp_dashboard_setup', array(&$this, 'admin_dashboard_widget'));

			// Plugin activation/deactivation
			register_activation_hook(__FILE__, 	 array(&$this, 'activate'));
			register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));

			add_action( 'admin_enqueue_scripts', array(&$this, 'admin_register_scripts') );

			// uninstall is never called
			//register_uninstall_hook(__FILE__, array(&$admin, 'uninstall'));

			// Register action for ajax call of the dashboard widget
			add_action( 'wp_ajax_my_action', array(&$this, 'admin_ajax_callback') );
		}
	}

	function admin_register_scripts( $hook ) {
		// Enqueue the Javascript and CSS
		wp_register_script( 'internetvista-javascript', plugins_url( self::ID . '/assets/js/internetvista.js' ) );
		wp_enqueue_script ( 'internetvista-javascript', false, array( 'jquery' ) );

		wp_enqueue_style( 'prefix-font-awesome', 	'//netdna.bootstrapcdn.com/font-awesome/4.0.3/css/font-awesome.min.css', array(), '4.0.3' );
		wp_enqueue_style( 'internetvista-style',  	plugins_url( self::ID . '/assets/css/internetvista.css' ), false );
	}

	protected function initialize() {
		$this->_tabs = array(
			'settings' => __('Settings', self::ID),
			'charts' => __('Charts', self::ID)
		);

		$this->set_options();

		// Get current tab (default: charts)
		$tab_default = 'charts';

		$this->_tab_current = isset($_GET['tab']) ? $_GET['tab'] : $tab_default;
		$this->_tab_current = ! empty($this->_tab_current) ? $this->_tab_current : $tab_default;
		$this->_tab_current = ! in_array($this->_tab_current, array_keys($this->_tabs)) ? $tab_default : $this->_tab_current;
	}

	/**
	 * Sanitizes output via htmlspecialchars() using UTF-8 encoding
	 *
	 * Makes this program's native text and translated/localized strings
	 * safe for displaying in browsers.
	 *
	 * @param $in string the string to sanitize
	 * @return string the sanitized string
	 */
	protected function hsc_utf8($in) {
		return htmlspecialchars($in, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Replaces all whitespace characters with one space
	 * @param $in string the string to clean
	 * @return string the cleaned string
	 */
	protected function sanitize_whitespace($in) {
		return preg_replace('/\s+/u', ' ', $in);
	}

	/**
	 * A centralized way to load the plugin's textdomain for internationalization
	 */
	protected function load_plugin_textdomain() {
		if (! $this->loaded_textdomain) {
			$this->loaded_textdomain = load_plugin_textdomain(self::ID, false, dirname(plugin_basename(__FILE__)) . '/languages/');
		}
	}

	/**
	 * Replaces the default option values with those stored in the database
	 * @uses login_security_solution::$options to hold the data
	 */
	protected function set_options() {
		if (is_multisite()) {
			switch_to_blog(1);
			$options = get_option(self::OPTION_NAME);
			restore_current_blog();
		} else {
			$options = get_option(self::OPTION_NAME);
		}

		if (! is_array($options)) {
			$options = array();
		}

		$this->options = array_merge($this->options_default, $options);
	}


	/**
	 * Handle the validation error messages. If there is only one message return it as is
	 * otherwise build and return an unorderd list of message.
	 * @param $fieldErrors array containing at least one error message
	 * @param $message string a facultative message that will prepend the error message/list
	 * @return string and html message
	 */
	protected function handleValidationException($fieldErrors, $message=null) {
		$html = '';

		if (! empty($fieldErrors)) {
			if (count($fieldErrors) > 1) {
				$html = '<ul>';
				foreach ($fieldErrors as $error) {
					$html .= '<li>'. $error->message .'</li>';
				}
				$html .= '</ul>';
			} else {
				$html = $fieldErrors[0]->message;
			}
		}

		return $message == null ? $html : '<b>'. $this->hsc_utf8($message) . ':</b> ' . $html;
	}

	protected function getAPI($login=null, $password=null){
		if ($login == null || $password == null) {
			$login = $this->options['login'];
			$password = $this->options['password'];
		}

		if (! $this->_api) {
			require_once(__DIR__ . '/lib/RestClient.class.php');
			$this->_api = new RestClient(array(
				'base_url' => wp_internetvista::REST_ENDPOINT,
				'username' => $login,
				'password' => $password,
				//'format' => 'xml',
				//'decoders' => array('xml' => "my_xml_decoder")
				//'decoders' => array('xml' => create_function('$a', "return new SimpleXMLElement(\$a);"))
			));
		}
		return $this->_api;
	}

	public function activate() {
		if (! current_user_can( 'activate_plugins' )){
			return;
		}

		$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
		check_admin_referer( "activate-plugin_{$plugin}" );

		/* Save this plugin's options to the database. */
		if (is_multisite()) {
			switch_to_blog(1);
		}
		update_option(self::OPTION_NAME, $this->options);
		if (is_multisite()) {
			restore_current_blog();
		}
	}

	public function deactivate() {
		if (! current_user_can( 'activate_plugins' )){
			return;
		}
		$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
		check_admin_referer( "deactivate-plugin_{$plugin}" );

		// Don't do anything on the deactivation
	}

	/** @deprecated never called even when hooked. */
	public function uninstall() {
		if (! current_user_can( 'activate_plugins' )){
			return;
		}
		check_admin_referer( 'bulk-plugins' );

		// Important: Check if the file is the one that was registered during the uninstall hook.
		if ( __FILE__ != WP_UNINSTALL_PLUGIN )
			return;

		// Don't keep the options
		if (is_multisite()) {
			switch_to_blog(1);
		}

		delete_option(self::OPTION_NAME);

		if (is_multisite()) {
			restore_current_blog();
		}
	}

	/**
	 * Build the sections for the registration form.
	 * @uses wp_internetvista_admin::$sections to hold the data
	 */
	protected function set_sections() {
		$this->sections = array(
			'' => array(
				'callback' => 'section_register'	// Callback to render the section
			)
		);
	}

	/**
	 * Build the fields for the registration form.
	 * @uses wp_internetvista::$fields to hold the data
	 */
	protected function set_fields() {
		$this->fields = array(
			'login' => array(
				'section' => '',
				'label' => __("Login", self::ID),
				'text' => __("The email address allowing you to connect to your internetVista account", self::ID),
				'type' => 'string',
				'required' => true
			),
			'password' => array(
				'section' => '',
				'label' => __("Password", self::ID),
				'text' => __("The password allowing you to log into your internetVista account", self::ID),
				'type' => 'password',
				'required' => true
			),
			'id_app' => array(
				'section' => 'application',
				'label' => __("Application ID", self::ID),
				'type' => 'string',
				'required' => false
			),
			'interval_type' => array(
				'section' => 'application',
				'label' => __("Interval type", self::ID),
				'type' => 'string',
				'required' => false
			),
			'interval_date_start' => array(
				'section' => 'application',
				'label' => __("Interval start date", self::ID),
				'type' => 'text_date_timestamp',
				'required' => false
			),
			'interval_date_end' => array(
				'section' => 'application',
				'label' => __("Interval end date", self::ID),
				'type' => 'text_date_timestamp',
				'required' => false
			),
			'interval_date_diff' => array(
				'section' => 'application',
				'label' => __("Interval date difference", self::ID),
				'type' => 'int',
				'required' => false
			)
		);
	}

	/**
	 * A filter to add a "Settings" link in this plugin's description
	 *
	 * NOTE: This method is automatically called by WordPress for each
	 * plugin being displayed on WordPress' Plugins admin page.
	 *
	 * @param array $links the links generated thus far
	 * @return array
	 */
	public function plugin_action_links($links) {
		$links[] = '<a href="admin.php?page=' . self::ID . '">' . __('Settings') . '</a>';
		return $links;
	}

	/**
	 * Declares a menu item and callback for this plugin's settings page
	 * NOTE: This method is automatically called by WordPress when any admin page is rendered
	 */
	public function admin_menu() {
		$page_hook  = add_menu_page(
			$this->text_settings,
			self::NAME,
			$this->capability_required,
			self::ID,
			array(&$this, 'page_settings'),
			plugins_url( self::ID . '/assets/iv-27x27.png' )
		);

		add_action( 'load-' . $page_hook , array(&$this, 'admin_obstart'));
	}

	/**
	 * Declares the callbacks for rendering and validating this plugin's
	 * settings sections and fields
	 *
	 * NOTE: This method is automatically called by WordPress when any admin page is rendered
	 */
	public function admin_init() {
		register_setting(
			self::OPTION_NAME,
			self::OPTION_NAME,
			array(&$this, 'validate')
		);

		// Dynamically declares each section using the info in $sections.
		foreach ($this->sections as $id => $section) {
			add_settings_section(
				self::ID . '-' . $id,					// wp-internetvista-register
				ucfirst($id), 							// titre
				array(&$this, $section['callback']),	// rendering callback
				self::ID								// related page (wp-internetvista)
			);
		}

		// Dynamically declares each field using the info in $fields.
		foreach ($this->fields as $id => $field) {
			add_settings_field(
				$id,									// field id
				$this->hsc_utf8($field['label']),		// titre
				array(&$this, $id),
				self::ID,								// related page (wp-internetvista)
				self::ID . '-' . $field['section']		// related section, wp-internetvista-register
			);
		}
	}

	public function admin_obstart() {
		// Check that we have already an id_app
		$id_app = $this->options['id_app'];

		// If not, redirect to the settings tab instead
		if (empty($id_app) && $this->_tab_current != 'settings') {
			$url = add_query_arg(
				array(
					'page' => 'wp-internetvista',
					'tab' => 'settings'
				),
				admin_url( 'admin.php' )
			);

			wp_redirect( $url, 302 );
			exit(0);
		}
	}

	public function admin_ajax_callback(){
		wp_die(); // this is required to terminate immediately and return a proper response
	}

	public function admin_notices() {
		settings_errors( self::OPTION_NAME );
	}

	public function admin_dashboard_widget() {
		// We can only add ONE widget
		//wp_add_dashboard_widget(self::ID, __('InternetVista 1', self::ID), array(&$this, 'admin_dashboard_widget_render_responsetime'));
		//wp_add_dashboard_widget(self::ID, __('InternetVista 2', self::ID), array(&$this, 'admin_dashboard_widget_render_responsetime_detailed'));
		//wp_add_dashboard_widget(self::ID, __('InternetVista 3', self::ID), array(&$this, 'admin_dashboard_widget_render_uptime'));

		wp_add_dashboard_widget(self::ID, __('InternetVista', self::ID), array(&$this, 'admin_dashboard_widget_render'));
	}

	private function get_application_statistics($startDate, $endDate, $aggregate=true, $aggregate_type='ALL') {
		$args = array(
			'startDate' => $startDate,
			'endDate' => $endDate,
			'aggregate' => $aggregate ? 'true' : 'false'
		);

		if ($aggregate == true) {
			$args['aggregatePeriod'] = $aggregate_type;
		}

		$this->_statistics = $this->getAPI()->get('/applications/'.$this->options['id_app'].'/statistics/', $args);

		return $this->_statistics;
	}

	/**
	 * Render general settings page.
	 */
	public function admin_settings_general_render(){
		$data = $this->getAPI()->get('/applications');

		if ($data->info->http_code != 200) {
			// Unable to get applications
			add_settings_error(self::OPTION_NAME,
				$this->hsc_utf8(self::OPTION_NAME),
				__('Unable to get application list', self::ID));
		} else {
			$xml = new SimpleXMLElement($data->response);

			?>
			<div id="internetvista-settings-tab">
				<form id="ivista_choose_application" method="post">
					<?php wp_nonce_field('ivista_create_nonce_application', 'ivista_choose_application'); ?>
					<input type="hidden" name="choose_application" value="1"/>

					<h2><?php _e('Settings of the internetVista monitoring module', self::ID); ?></h2>

					<p><?php _e('Select in the list below the application that you want to monitor.', self::ID); ?></p>

					<table class="widefat">
						<thead>
						<tr>
							<th></th>
							<th><?php _e('Alias', self::ID); ?></th>
							<th><?php _e('Label', self::ID); ?></th>
						</tr>
						</thead>
						<tbody>
						<?php
							$j = 0;
							foreach ($xml->application as $i => $application) {
								if ($application->active == 'true') {
									$option_selected = (empty($this->options['id_app']) && $j == 0) || ($this->options['id_app'] == $application->id);

									?>
										<tr>
											<td><input type="radio" name="id_app" value="<?php echo $application->id; ?>"  <?php echo ($option_selected) ?  'checked="checked"' : ''; ?> /></td>
											<td><?php echo $application->alias; ?></td>
											<td><?php echo $application->label; ?></td>
										</tr>
									<?php

									$j++;
								}
							}
						?>
						</tbody>
					</table>
				</form>
				<form id="ivista_reset" method="post">
					<?php wp_nonce_field('ivista_create_nonce_application', 'ivista_reset'); ?>
					<input type="hidden" name="reset_options" value="1"/>
				</form>

				<div class="submit-wrapper">
					<input type="button" onclick="document.getElementById('ivista_reset').submit();"
						   class="button button-secondary button-submit"
						   value="<?php _e('Change account', self::ID); ?>"/>
					<input type="button" onclick="document.getElementById('ivista_choose_application').submit();"
						   class="button button-primary button-submit"
						   value="<?php _e('Select an application', self::ID); ?>"/>
				</div>
			</div>
			<script type="text/javascript">
				(function($){
					$(function(){
						// Prevent the "You don't have enough permission" crap
						$_wp_ref = $('input[name="_wp_http_referer"]');
						$_wp_ref.val($_wp_ref.val().replace('&settings-updated=true', ''));
					});
				})(jQuery);
			</script>
		<?php
		}
	}

	/**
	 * Render charts for settings page.
	 */
	public function admin_settings_widget_render(){
		if ($this->options['id_app'] != null) {
			?>
			<div id="internetvista-chart-tab">
			<h2><?php _e('Performance of your application', self::ID); ?></h2>


				<div id="internetvista-chart-wrapper">
					<?php
						// Render charts
						$graph_uptime_rendered = $this->admin_dashboard_widget_render_uptime();
						$graph_rt_rendered = $this->admin_dashboard_widget_render_responsetime();

						if (! $graph_uptime_rendered && ! $graph_rt_rendered) {
							echo '<div class="internetvista-no-graph">'. __('No data to plot', self::ID) .'</div>';
						}
					?>
				</div>

				<br />
				<form id="ivista_choose_interval" method="post">
					<?php  wp_nonce_field('ivista_create_nonce_interval','ivista_choose_interval'); ?>
					<input type="hidden" name="choose_interval" value="1" />
					<label class="internetvista-interval" for=""><?php _e('Period to inspect', self::ID); ?> :</label>
					<select class="internetvista-interval" name="interval_type">
						<?php
							foreach ($this->_interval_types as $key => $label) {
								$checked = $key == $this->options['interval_type'] ? 'selected' : '';
								?><option value="<?php echo $key; ?>" <?php echo $checked; ?>><?php echo $label; ?></option><?php
							}
						?>
					</select>
					<?php
						// We need to reformat the date in order to be used in an html5 input date type (RFC3339 is lame)
						// The input will automatically reformat the input in the user's local (browser side).
						$input_date_start = DateTime::createFromFormat(wp_internetvista::DATE_FORMAT, $this->options['interval_date_start']);
						$input_date_end = DateTime::createFromFormat(wp_internetvista::DATE_FORMAT, $this->options['interval_date_end']);

						$input_date_start = $input_date_start->format('Y-m-d');
						$input_date_end = $input_date_end->format('Y-m-d');
					?>
					<input type="date" id="internetvista-responsetime-start" class="internetvista-datepicker" name="interval_date_start" value="<?php echo $input_date_start; ?>" />
					<input type="date" id="internetvista-responsetime-end" class="internetvista-datepicker" name="interval_date_end" value="<?php echo $input_date_end; ?>" />
					<input type="submit" class="button button-primary button-submit" />
				</form>
				<script type="text/javascript">
					(function($){
						$('select.internetvista-interval').change(function(e){
							var $this = $(this);
							$('#internetvista-responsetime-start')
								.add('#internetvista-responsetime-end').css({
									display: $this.val() == 'date-interval' ? 'block' : 'none'
								});
						}).trigger('change');
					})(jQuery);
				</script>
			</div>
		<?php
		}
	}

	public function admin_dashboard_widget_render() {

		// Render both widgets
		if (   $this->admin_dashboard_widget_render_uptime()
			&& $this->admin_dashboard_widget_render_responsetime()) {

			// Display the date range as a reminder.
			?>
				<span id="internetvista-date-interval">
					<?php

					if ($this->options['interval_type'] == 'date-interval') {
						_e('Statistics between', self::ID);
						echo ' '.$this->options['interval_date_start'].' and '.$this->options['interval_date_end'];
					} else {
						_e($this->_interval_types[$this->options['interval_type']], self::ID);
					}

					?>
				</span>
			<?php
		} else {
			// Unable to render the widget (no data or no authentication)

			if (empty($this->options['id_app'])){
				echo '<div class="internetvista-no-graph">' . __('You need to configure the plugin first', self::ID) . '</div>';
			} else {
				echo '<div class="internetvista-no-graph">' . __('No data to plot', self::ID) . '</div>';
			}
		}

		// Add the configuration gear
		?>
			<script type="text/javascript">
				(function ($) {
					$('#wp-internetvista').find('.handlediv').after('<a href="admin.php?page=<?php echo self::ID; ?>&tab=charts" class="handlediv-internetvista" title="<?php _e('Settings', self::ID); ?>"><br></a>');
				})(jQuery);
			</script>
		<?php
	}

	private function get_date_interval($interval_type=false, $startDate=false, $endDate=false){
		if (! $interval_type) {
			$interval_type = $this->options['interval_type'];
		}

		switch ($interval_type) {
			case 'date-interval':
				if ($startDate && $endDate) {
					$startDate = new DateTime(date('Y-m-d', strtotime($startDate)));
					$endDate = new DateTime(date('Y-m-d', strtotime($endDate)));
				} else {
					$startDate = new DateTime();
					$endDate = new DateTime();
				}
				break;
			case 'yesterday':
				$startDate = new DateTime();
				$endDate = new DateTime();

				$startDate = $startDate->modify('-1 day');
				$endDate = $endDate->modify('-1 day');
				break;

			case 'last-7-days':
			case 'last-30-days':
			case 'last-365-days':
				// Handle last-xxx-days all together.
				$days = 0;
				if ($interval_type == 'last-7-days') {
					$days = 7;
				} else {
					if ($interval_type == 'last-30-days') {
						$days = 30;
					} else {
						if ($interval_type == 'last-365-days') {
							$days = 365;
						}
					}
				}

				$startDate = new DateTime();
				$startDate = $startDate->modify('-' . $days . ' day');
				$endDate = new DateTime();
				break;

			case 'last-week':
				$startDate = date('Y-m-d', strtotime("last week"));
				$endDate = date('Y-m-d', strtotime("+6 day", strtotime("last week")));

				$startDate = new DateTime($startDate);
				$endDate = new DateTime($endDate);
				break;
			case 'last-month':
				$startDate = new DateTime(date('Y-m-d', strtotime("first day of previous month")));
				$endDate = new DateTime(date('Y-m-d', strtotime("last day of previous month")));
				break;
			case 'last-year':
				$year = new DateTime();
				$year = $year->format('Y');
				$year = ((int)$year) - 1;
				$startDate = new DateTime($year . '-01-01');
				$endDate = new DateTime($year . '-12-31');
				break;

			case 'this-week':
				$week = new DateTime();

				// Get the first day of this week
				if ($week->format('w') != 1) {
					$startDate = new DateTime(date('Y-m-d', strtotime('last monday')));
				} else {
					$startDate = $week;
				}

				$endDate = new DateTime();
				break;
			case 'this-year':
				$year = new DateTime();
				$year = $year->format('Y');
				$startDate = new DateTime($year . '-01-01');
				$endDate = new DateTime($year . '-12-31');
				break;

			case 'all':
			default:
				$startDate = false;
				$endDate = false;
				break;
		}

		$results = false;

		if ($startDate && $endDate) {
			$diff = (int)$endDate->diff($startDate)->days;
			$startDate = $startDate->format(self::DATE_FORMAT);
			$endDate = $endDate->format(self::DATE_FORMAT);

			$results = array(
				'start' => $startDate,
				'end'   => $endDate,
				'diff'  => $diff		// TODO FIXME Why is this value never properly stored ???
			);
		}

		return $results;
	}

	public function admin_dashboard_widget_render_responsetime() {
		$graph_rendered = false;

		$interval_date_start = $this->options['interval_date_start'];
		$interval_date_end   = $this->options['interval_date_end'];
		//$interval_date_diff  = $this->options['interval_date_diff'];

		// TODO FIXME For whatever reason, Wordpress refuse to properly handle 'interval_date_diff' option so recompute it here . . . .
		$interval_date_diff = 0;
		if ($interval_date_start && $interval_date_end) {
			$interval_date_diff_fix_start = DateTime::createFromFormat (self::DATE_FORMAT, $interval_date_start);
			$interval_date_diff_fix_end   = DateTime::createFromFormat (self::DATE_FORMAT, $interval_date_end);
			$interval_date_diff = $interval_date_diff_fix_end->diff($interval_date_diff_fix_start)->days;
		}

		// If the time spent is over a month, aggregate by month
		$aggregate = $interval_date_diff > 31;

		$data = $this->get_application_statistics($interval_date_start, $interval_date_end, $aggregate, 'MONTH');

		if ($data->info->http_code == 200) {
			// Parse the results
			$stats = new SimpleXMLElement($data->response);
			$stats_count = count($stats->statistic);

			if ($stats_count > 0) {
				?>
				<div id="internetvista-responsetime"></div>
				<script type="text/javascript">
					(function($){
						$('#internetvista-responsetime').highcharts({
								chart: {
									zoomType: 'x'
								},
								title: {
									text: '<?php _e('Average response time'); ?>'
								},
								xAxis: {
									type: 'datetime',
									minRange: <?php echo $interval_date_diff; ?> * 24 * 3600000 // 30 days
								},
								yAxis: {
								min: 0,
									title: {
									text: '<?php _e('Time in milliseconds', self::ID); ?>'
								}
							},
							plotOptions: {
								column: {
									depth: 20
								}
							},
							series: [{
								name: '<?php _e('Response time', self::ID); ?>',
								pointInterval: 24 * 3600 * 1000,
								data: [
									<?php
										for ($i=0; $i<$stats_count; $i++){
											$statsDate = DateTime::createFromFormat (self::DATE_FORMAT, (string) $stats->statistic[$i]->startDate);
											echo '[ Date.UTC('. $statsDate->format('Y, m-1, d') .'), '. ((int) $stats->statistic[$i]->averageResponseTime) .' ]';
											echo ($i+1 != $stats_count) ? ',' : '';
											echo PHP_EOL;
										}
									?>
								]
							}],
								legend: {
								enabled: false
							},
							credits: {
								enabled: false
							}
						});
					})(jQuery);
				</script>
				<?php

				$graph_rendered = true;
			}
		}

		return $graph_rendered;
	}

	public function admin_dashboard_widget_render_uptime() {
		$graph_rendered = false;

		//$interval_type = $this->options['interval_type'];
		$interval_date_start = $this->options['interval_date_start'];
		$interval_date_end   = $this->options['interval_date_end'];
		$interval_date_diff  = $this->options['interval_date_diff'];
		$data = $this->get_application_statistics($interval_date_start, $interval_date_end);

		if ($data->info->http_code == 200) {
			$stats = new SimpleXMLElement($data->response);
			$stats_count = count($stats->statistic);

			if ($stats_count > 0) {
				$uptimePercentage = 0.0;

				foreach ($stats->statistic as $stat) {
					$uptimePercentage += (float)$stat->uptimePercentage;
				}

				$uptimePercentage = number_format($uptimePercentage / count($stats->statistic), 2);

				?>
				<div id="internetvista-uptime"></div>
				<script type="text/javascript">
					(function($){
						$('#internetvista-uptime').highcharts({
							title: {
								text: '<?php _e('Average up time'); ?>'
							},
							chart: {
								type: 'solidgauge'
							},
							series: [{
								name: 'Uptime',
								data: [ <?php echo $uptimePercentage; ?> ],
								dataLabels: {
									format: '<div class="internetvista-uptime-legend"><span style="color:' +
									((Highcharts.theme && Highcharts.theme.contrastTextColor) || 'black') + '">{y}%</span>'
									+ '<br /><span><?php _e('Uptime percentage', self::ID); ?></span></div>'
								}
							}],
							pane: {
								center: ['50%', '85%'],
								size: '170%',
								startAngle: -90,
								endAngle: 90,
								background: {
									backgroundColor: (Highcharts.theme && Highcharts.theme.background2) || '#EEE',
									innerRadius: '60%',
									outerRadius: '100%',
									shape: 'arc'
								}
							},
							yAxis: {
								min: 0,
								max: 100,
								title: null,
								stops: [
									[0.7, '#DF5353'], // red
									[0.8, '#DDDF0D'], // yellow
									[0.9, '#55BF3B']  // green
								],
								lineWidth: 0,
								minorTickInterval: null,
								tickPixelInterval: 400,
								tickWidth: 0,
								labels: {
									y: 16
								}
							},
							plotOptions: {
								solidgauge: {
									dataLabels: {
										y: 5,
										borderWidth: 0,
										useHTML: true
									}
								}
							},
							tooltip: {
								enabled: false
							},
							credits: {
								enabled: false
							}
						}); //.prepend();
					})(jQuery);
				</script>
				<?php

				$graph_rendered = true;
			}
		}

		return $graph_rendered;
	}

	/**
	 * The callback for rendering the settings page
	 */
	public function page_settings() {
		// Handle settings reset
		if (isset($_POST['reset_options']) && $_POST['reset_options'] == 1) {
			$this->page_settings_reset();
			// The default view will be used
		}

		if ($this->options['login'] == null || $this->options['password'] == null) {
			// Render login page
			$this->page_settings_login();
		} else {
			if (isset($_POST['choose_application']) && $_POST['choose_application'] == 1) {
				// Process the application selection
				$this->page_settings_choose_application();
			} else if (isset($_POST['choose_interval']) && $_POST['choose_interval'] == 1) {
				// Process the interval selection
				$this->page_settings_choose_interval();
			}


			// Render the tabbed view
			$this->page_settings_render_tabs();
		}
	}

	/**
	 * Reset options
	 */
	private function page_settings_reset(){
		if (is_multisite()) {
			switch_to_blog(1);
		}

		// Clear all options
		foreach (array_keys($this->options) as $key){
			$this->options[$key] = null;
		}

		update_option(self::OPTION_NAME, $this->options);

		// Reload options>
		$this->set_options();

		if (is_multisite()) {
			restore_current_blog();
		}
	}

	/**
	 * Render login page.
	 */
	private function page_settings_login(){
		?>
		<div class="wrap internetvista-wrap">
			<div id="icon-wp-internetvista" class="icon32"><br /></div>
			<h2><?php _e('InternetVista monitoring', self::ID); ?></h2>
			<em><?php _e('Monitoring of your website performance', self::ID); ?></em>

			<form method="POST" action="options.php" id="internetvista-authenticate">
				<?php
					settings_fields(self::OPTION_NAME);
					do_settings_sections(self::ID);
				?>

				<div class="submit-wrapper">
					<a class="button button-secondary internetvista-register" href="<?php echo self::REGISTER_URL; ?>"><?php _e('Register', self::ID); ?></a>
					<?php echo submit_button($this->hsc_utf8(__("Connexion", self::ID))); ?>
				</div>
			</form>
		</div>
	<?php
	}

	/**
	 * Select a time interval for rendering charts.
	 */
	private function page_settings_choose_interval(){
		if (! wp_verify_nonce($_POST['ivista_choose_interval'], 'ivista_create_nonce_interval')
			|| empty($_POST['_wp_http_referer'])
			|| (isset($_SERVER['HTTP_REFERER']) && !strstr($_SERVER['HTTP_REFERER'], $_POST['_wp_http_referer']) )){
			wp_die("You do not have sufficient permissions to access this page.");
		}

		// Get the posted values
		$interval_type =  array_key_exists('interval_type', $_POST) ? $_POST['interval_type'] : false;

		if ($interval_type == 'date-interval') {
			$startDate = array_key_exists('interval_date_start', $_POST) ? $_POST['interval_date_start'] : false;
			$endDate   = array_key_exists('interval_date_end', $_POST)   ? $_POST['interval_date_end']   : false;

			$interval_date = $this->get_date_interval($interval_type, $startDate, $endDate);
		} else {
			$interval_date = $this->get_date_interval($interval_type);
		}

		// Process update
		if ($interval_type != 'all' && ! $interval_date) {
			// error
		} else {
			// Update options
			$this->options['interval_type'] = $interval_type;

			if ($interval_type == 'all') {
				$this->options['interval_date_start'] = null;
				$this->options['interval_date_end'] = null;
				$this->options['interval_date_diff'] = null;
			} else {
				$this->options['interval_date_start'] = $interval_date['start'];
				$this->options['interval_date_end']   = $interval_date['end'];
				$this->options['interval_date_diff']  = $interval_date['diff'];
			}

			if (is_multisite()) {
				switch_to_blog(1);
			}

			update_option(self::OPTION_NAME, $this->options);

			if (is_multisite()) {
				restore_current_blog();
			}
		}
	}

	private function page_settings_choose_application(){
		if (! wp_verify_nonce($_POST['ivista_choose_application'], 'ivista_create_nonce_application')
			|| empty($_POST['_wp_http_referer'])
			|| (isset($_SERVER['HTTP_REFERER']) && !strstr($_SERVER['HTTP_REFERER'], $_POST['_wp_http_referer']) )){
			wp_die("You do not have sufficient permissions to access this page.");
		}

		// User has made is selection
		$id_app =  array_key_exists('id_app', $_POST) ? $_POST['id_app'] : false;

		if ($id_app && ! empty($id_app)) {
			// Check if this application actually exists
			$data = $this->getAPI()->get('/applications');

			if ($data->info->http_code != 200) {
				// Unable to get applications
				add_settings_error(self::OPTION_NAME,
					$this->hsc_utf8(self::OPTION_NAME),
					__('Unable to get application list', self::ID));
			} else {
				$found = false;
				$applications = new SimpleXMLElement($data->response);

				for ($i=0, $len=count($applications->application); $i < $len && ! $found; $i++) {
					$found = $applications->application[$i]->id == $id_app;
				}

				if (! $found) {
					// Selected app does not exists
					add_settings_error(self::OPTION_NAME,
						$this->hsc_utf8(self::OPTION_NAME),
						__('Selected application does not exist', self::ID));

				} else {
					$this->options['id_app'] = $id_app;

					// Set default interval
					$this->options['interval_type'] = $this->options_default['interval_type'];

					// Get default values related to this interval
					$interval_date = $this->get_date_interval();
					$this->options['interval_date_start'] = $interval_date['start'];
					$this->options['interval_date_end']   = $interval_date['end'];
					$this->options['interval_date_diff']  = $interval_date['diff'];

					if (is_multisite()) {
						switch_to_blog(1);
					}

					update_option(self::OPTION_NAME, $this->options);

					if (is_multisite()) {
						restore_current_blog();
					}

					// We can't redirect to the chart tab here ...
					$this->_tab_current = 'charts';
				}
			}
		} else {
			add_settings_error(self::OPTION_NAME,
				$this->hsc_utf8(self::OPTION_NAME),
				__('Please, select an application in the list', self::ID));
		}
	}

	private function page_settings_render_tabs() {
		?>
			<div id="icon-themes" class="icon32"><br /></div>
			<h2 class="nav-tab-wrapper">
				<?php
				foreach( $this->_tabs as $tab => $name ){
					$class = ( $tab == $this->_tab_current) ? ' nav-tab-active' : '';
					echo "<a class='nav-tab$class' href='?page=wp-internetvista&tab=$tab'>$name</a>";
				}
				?>
			</h2>
		<?php

		if ($this->_tab_current == 'settings') {
			$this->admin_settings_general_render();
		} else {
			$this->admin_settings_widget_render();
		}
	}

	/**
	 * The callback for rendering the "Register" section description
	 * @return void
	 */
	public function section_register() {
		?>
			<p><?php _e('Welcome in the settings of you intervetVista plugin', self::ID); ?></p>
			<p>
				<?php _e('InternetVista allow you to know at any time the performance of you web site.', self::ID); ?>
				<?php _e('As soon as an anomaly appears, you are notified directly by email, sms or notification.', self::ID); ?>
				<?php _e('This module allow you to display most important monitoring metrics within your dashboard: <b>uptime</b> and <b>average response time</b>.', self::ID); ?>
			</p>
			<p><?php echo sprintf(__('In order to use this module you will simply need to freely <a href="%s" title="Register now for free !">register on internetVista</a> and fulfill the following form with you internetVista\'s email and password.', self::ID), self::REGISTER_URL); ?></p>

			<h2><?php _e("Connect with you internetVista login", self::ID) ?></h2>
		<?php
	}

	/**
	 * The callback for rendering the fields.
	 *
	 * @uses wp_internetvista::input_int()  		for rendering text input boxes for numbers
	 * @uses wp_internetvista::input_radio()  	for rendering radio buttons
	 * @uses wp_internetvista::input_string()	for rendering input fields
	 * @uses wp_internetvista::input_email()		for rendering email fields
	 */
	public function __call($name, $params) {
		if (! empty($this->fields[$name]['type'])) {
			switch ($this->fields[$name]['type']) {
				case 'bool': 		$this->input_radio($name); 		break;
				case 'int': 		$this->input_int($name); 		break;
				case 'string': 		$this->input_string($name); 	break;
				case 'password': 	$this->input_password($name); 	break;
			}
		}
	}

	/**
	 * Renders the radio button inputs
	 */
	protected function input_radio($name) {
		echo $this->hsc_utf8($this->fields[$name]['text']) . '<br />';
		echo '<input type="radio" value="0" name="' . $this->hsc_utf8(self::OPTION_NAME) . '[' . $this->hsc_utf8($name) . ']"'
			. ($this->options[$name] ? '' : ' checked="checked"') . ' /> ';
		echo $this->hsc_utf8($this->fields[$name]['bool0']);
		echo '<br/>';
		echo '<input type="radio" value="1" name="' . $this->hsc_utf8(self::OPTION_NAME) . '[' . $this->hsc_utf8($name) . ']"'
			. ($this->options[$name] ? ' checked="checked"' : '') . ' /> ';
		echo $this->hsc_utf8($this->fields[$name]['bool1']);
	}

	/**
	 * Renders the text input boxes for editing integers
	 */
	protected function input_int($name) {
		echo '<input type="text" size="3" name="' . $this->hsc_utf8(self::OPTION_NAME) . '[' . $this->hsc_utf8($name) . ']"'
			. ' value="' . $this->hsc_utf8($this->options[$name]) . '" /> ';
		echo $this->hsc_utf8($this->fields[$name]['text']);

		if (array_key_exists($name, $this->options_default) && ! empty($this->options_default[$name])) {
			echo $this->hsc_utf8(' ' . __('Default:', self::ID) . ' ' . $this->options_default[$name] . '.');
		}
	}

	/**
	 * Renders the text input boxes for editing strings
	 */
	protected function input_string($name) {
		echo '<input type="text" name="' . $this->hsc_utf8(self::OPTION_NAME) . '[' . $this->hsc_utf8($name) . ']"'
			. ' value="' . $this->hsc_utf8($this->options[$name]) . '" style="width: 35em;" /> '
			. '<br />' . $this->hsc_utf8($this->fields[$name]['text']);

		if (array_key_exists($name, $this->options_default) && ! empty($this->options_default[$name])) {
			echo $this->hsc_utf8(' ' . __('Default:', self::ID) . ' ' . $this->options_default[$name] . '.');
		}
	}

	/**
	 * Renders the password input boxes
	 */
	protected function input_password($name) {
		echo '<input type="password" name="' . $this->hsc_utf8(self::OPTION_NAME) . '[' . $this->hsc_utf8($name) . ']"'
			. ' value="' . $this->hsc_utf8($this->options[$name]) . '" style="width: 35em;" /> '
			. '<br />' . $this->hsc_utf8($this->fields[$name]['text']);

		if (array_key_exists($name, $this->options_default) && ! empty($this->options_default[$name])) {
			echo $this->hsc_utf8(' ' . __('Default:', self::ID) . ' ' . $this->options_default[$name] . '.');
		}
	}

	/**
	 * Validates the user input
	 *
	 * NOTE: WordPress saves the data even if this method says there are errors.
	 * So this method sets any inappropriate data to the default values.
	 *
	 * @param array $in  the input submitted by the form
	 * @return array  the sanitized data to be saved
	 */
	public function validate($in) {
		$out = $this->options_default;
		if (! is_array($in)) {
			add_settings_error(self::OPTION_NAME,
				$this->hsc_utf8(self::OPTION_NAME),
				'Input must be an array.');
			return $out;
		}

		$gt_format = __("must be >= '%s',", self::ID);
		$default = __("so we used the default value instead.", self::ID);

		// Dynamically validate each field using the info in $fields.
		foreach ($this->fields as $name => $field) {
			if (!array_key_exists($name, $in)) {
				continue;
			}

			if (!is_scalar($in[$name])) {
				// Not translating this since only hackers will see it.
				add_settings_error(self::OPTION_NAME,
					$this->hsc_utf8($name),
					$this->hsc_utf8("'" . $field['label']) . "' was not a scalar, $default");
				continue;
			}

			switch ($field['type']) {
				case 'bool':
					if ($in[$name] != 0 && $in[$name] != 1) {
						add_settings_error(self::OPTION_NAME,
							$this->hsc_utf8($name),
							$this->hsc_utf8("'" . $field['label'] . "' must be '0' or '1', $default"));
						continue 2;
					}
					break;
				case 'int':
					if (!ctype_digit($in[$name])) {
						add_settings_error(self::OPTION_NAME,
							$this->hsc_utf8($name),
							$this->hsc_utf8("'" . $field['label'] . "' "
								. __("must be an integer,", self::ID)
								. ' ' . $default));
						continue 2;
					}
					if (array_key_exists('greater_than', $field)
						&& $in[$name] < $field['greater_than']) {
						add_settings_error(self::OPTION_NAME,
							$this->hsc_utf8($name),
							$this->hsc_utf8("'" . $field['label'] . "' "
								. sprintf($gt_format, $field['greater_than'])
								. ' ' . $default));
						continue 2;
					}
					break;
				case 'password':
					// Nothing to validate
					break;
			}

			if (array_key_exists('required', $field)) {
				if (empty($in[$name])) {
					add_settings_error(self::OPTION_NAME,
						$this->hsc_utf8($name),
						$this->hsc_utf8("'" . $field['label'] . "' is mandatory"));
					continue 1;
				}
			}

			$out[$name] = $in[$name];
		}

		if (count(get_settings_errors()) == 0) {
			// Force the use of the temporary login/password
			$api = $this->getAPI($out['login'], $out['password']);
			// Process one query to check the credential
			$data = $api->get('/applications');

			if($data->info->http_code != 200) {
				if (empty($data->info->error)) {
					$response = new SimpleXMLElement($data->response);

					if ($response->code != null && $response->message != null) {
						add_settings_error(self::OPTION_NAME,
							$this->hsc_utf8($response->code),
							$this->hsc_utf8($response->message));
					} else {
						add_settings_error(self::OPTION_NAME,
							'',
							$this->hsc_utf8(__('Unexpected exception occurred', self::ID)));
					}
				} else {
					add_settings_error(self::OPTION_NAME,
						$this->hsc_utf8(__('Unable to authenticate', self::ID)),
						$this->hsc_utf8($data->error));
				}

				// Unset value if error occurred
				unset($out['login']);
				unset($out['password']);
			}
		}

		return $out;
	}
}
