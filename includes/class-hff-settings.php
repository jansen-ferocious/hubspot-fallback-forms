<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page, settings registration, form sync AJAX, and front-end
 * asset enqueuing.
 */
class HFF_Settings {

	const MENU_SLUG = 'hubspot-fallback-forms';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'front_assets' ) );

		// AJAX: sync a single form from HubSpot.
		add_action( 'wp_ajax_hff_sync_form', array( $this, 'ajax_sync_form' ) );
		// AJAX: remove a cached form.
		add_action( 'wp_ajax_hff_remove_form', array( $this, 'ajax_remove_form' ) );
		// AJAX: send a test email for a cached form.
		add_action( 'wp_ajax_hff_test_email', array( $this, 'ajax_test_email' ) );
		// AJAX: render a preview of a cached form.
		add_action( 'wp_ajax_hff_preview_form', array( $this, 'ajax_preview_form' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( HFF_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the settings menu item.
	 */
	public function add_menu() {
		add_options_page(
			__( 'HubSpot Fallback Forms', 'hubspot-fallback-forms' ),
			__( 'HubSpot Fallback', 'hubspot-fallback-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links
	 * @return array
	 */
	public function action_links( $links ) {
		$url  = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'hubspot-fallback-forms' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Register the settings group with a sanitize callback.
	 */
	public function register_settings() {
		register_setting(
			'hff_settings_group',
			HFF_OPT_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$existing = hff_get_settings();
		$out      = array();

		$out['enabled']         = ! empty( $input['enabled'] ) ? 1 : 0;
		$out['sync_styles']     = ! empty( $input['sync_styles'] ) ? 1 : 0;
		$out['recipients']      = isset( $input['recipients'] ) ? $this->sanitize_email_list( $input['recipients'] ) : '';
		$out['portal_id']       = isset( $input['portal_id'] ) ? preg_replace( '/[^0-9]/', '', $input['portal_id'] ) : '';
		$out['region']          = isset( $input['region'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $input['region'] ) ) : 'na1';
		$out['mg_domain']       = isset( $input['mg_domain'] ) ? sanitize_text_field( $input['mg_domain'] ) : '';
		$out['mg_region']       = isset( $input['mg_region'] ) && 'eu' === $input['mg_region'] ? 'eu' : 'us';
		$out['from_name']       = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
		$out['from_email']      = isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '';

		// API key: keep the stored value if the field is submitted empty (so it
		// isn't wiped when the user saves without re-typing it).
		if ( isset( $input['mg_api_key'] ) && '' !== $input['mg_api_key'] ) {
			$out['mg_api_key'] = trim( sanitize_text_field( $input['mg_api_key'] ) );
		} else {
			$out['mg_api_key'] = isset( $existing['mg_api_key'] ) ? $existing['mg_api_key'] : '';
		}

		return $out;
	}

	/**
	 * Normalize a comma-separated list of emails, dropping invalid entries.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function sanitize_email_list( $value ) {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		$valid = array();
		foreach ( $parts as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) ) {
				$valid[] = $email;
			}
		}
		return implode( ', ', array_unique( $valid ) );
	}

	/**
	 * Cache-busting asset version based on file modification time, so browsers
	 * always fetch the latest CSS/JS after an update.
	 *
	 * @param string $rel Path relative to the plugin directory.
	 * @return string
	 */
	protected static function asset_ver( $rel ) {
		$path = HFF_DIR . $rel;
		return file_exists( $path ) ? (string) filemtime( $path ) : HFF_VERSION;
	}

	/**
	 * Enqueue admin assets on our settings page only.
	 *
	 * @param string $hook
	 */
	public function admin_assets( $hook ) {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hff-admin', HFF_URL . 'assets/css/hff.css', array(), self::asset_ver( 'assets/css/hff.css' ) );
		wp_enqueue_script( 'hff-admin', HFF_URL . 'assets/js/hff-admin.js', array( 'jquery' ), self::asset_ver( 'assets/js/hff-admin.js' ), true );
		wp_localize_script(
			'hff-admin',
			'HFFAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'hff_admin' ),
				'syncing'    => __( 'Syncing…', 'hubspot-fallback-forms' ),
				'sending'    => __( 'Sending…', 'hubspot-fallback-forms' ),
				'loading'    => __( 'Loading…', 'hubspot-fallback-forms' ),
				'confirmDel' => __( 'Remove this cached form?', 'hubspot-fallback-forms' ),
			)
		);
	}

	/**
	 * Enqueue front-end assets (only when the plugin is enabled).
	 */
	public function front_assets() {
		$settings = hff_get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		wp_enqueue_style( 'hff-front', HFF_URL . 'assets/css/hff.css', array(), self::asset_ver( 'assets/css/hff.css' ) );
		wp_enqueue_script( 'hff-front', HFF_URL . 'assets/js/hff.js', array(), self::asset_ver( 'assets/js/hff.js' ), true );
		wp_localize_script(
			'hff-front',
			'HFFData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * AJAX handler: fetch a form from HubSpot and cache it.
	 */
	public function ajax_sync_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hubspot-fallback-forms' ) ), 403 );
		}
		check_ajax_referer( 'hff_admin', 'nonce' );

		$form_id  = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		$settings = hff_get_settings();

		// Allow a per-request portal ID override; otherwise use the global setting.
		$portal_id = isset( $_POST['portal_id'] ) && '' !== $_POST['portal_id']
			? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['portal_id'] ) )
			: $settings['portal_id'];
		$region    = ! empty( $settings['region'] ) ? $settings['region'] : 'na1';

		if ( '' === $portal_id ) {
			wp_send_json_error( array( 'message' => __( 'Add your HubSpot Portal ID and save before syncing.', 'hubspot-fallback-forms' ) ), 400 );
		}

		$result = HFF_Sync::fetch_form( $portal_id, $form_id, $region );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$forms             = hff_get_forms();
		$forms[ $form_id ] = $result;
		update_option( HFF_OPT_FORMS, $forms );

		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: 1: form name, 2: field count */
					__( 'Synced "%1$s" — %2$d fields cached.', 'hubspot-fallback-forms' ),
					$result['name'] ? $result['name'] : $form_id,
					count( $result['fields'] )
				),
				'row'        => $this->form_row_html( $form_id, $result ),
				'field_count' => count( $result['fields'] ),
			)
		);
	}

	/**
	 * AJAX handler: remove a cached form.
	 */
	public function ajax_remove_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hubspot-fallback-forms' ) ), 403 );
		}
		check_ajax_referer( 'hff_admin', 'nonce' );

		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		$forms   = hff_get_forms();
		if ( isset( $forms[ $form_id ] ) ) {
			unset( $forms[ $form_id ] );
			update_option( HFF_OPT_FORMS, $forms );
		}
		wp_send_json_success( array( 'message' => __( 'Removed.', 'hubspot-fallback-forms' ) ) );
	}

	/**
	 * AJAX handler: send a test email populated with dummy answers.
	 */
	public function ajax_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hubspot-fallback-forms' ) ), 403 );
		}
		check_ajax_referer( 'hff_admin', 'nonce' );

		$settings = hff_get_settings();

		$recipients = array_filter( array_map( 'trim', explode( ',', (string) $settings['recipients'] ) ) );
		if ( empty( $recipients ) ) {
			wp_send_json_error( array( 'message' => __( 'Add at least one recipient email and save before sending a test.', 'hubspot-fallback-forms' ) ), 400 );
		}

		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		$forms   = hff_get_forms();
		$def     = isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : array();

		$dummy    = HFF_Submission::dummy_submission( $def );
		$subject  = '[TEST] Hubspot Fallback Submission | ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body     = HFF_Submission::build_email_body( $dummy['fields'], $dummy['consents'], $form_id, $def, home_url( '/' ) );

		$sent = HFF_Mailer::send( $recipients, $subject, $body );

		if ( is_wp_error( $sent ) ) {
			wp_send_json_error( array( 'message' => $sent->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: comma-separated recipient list */
					__( 'Test email sent to %s.', 'hubspot-fallback-forms' ),
					implode( ', ', $recipients )
				),
			)
		);
	}

	/**
	 * AJAX handler: return the rendered HTML preview of a cached form.
	 */
	public function ajax_preview_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hubspot-fallback-forms' ) ), 403 );
		}
		check_ajax_referer( 'hff_admin', 'nonce' );

		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		$forms   = hff_get_forms();

		if ( empty( $forms[ $form_id ] ) || empty( $forms[ $form_id ]['fields'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This form is not synced yet.', 'hubspot-fallback-forms' ) ), 404 );
		}

		wp_send_json_success( array( 'html' => HFF_Renderer::render( $form_id, $forms[ $form_id ] ) ) );
	}

	/**
	 * Render a single cached-form table row.
	 *
	 * @param string $form_id
	 * @param array  $def
	 * @return string
	 */
	protected function form_row_html( $form_id, $def ) {
		$name   = ! empty( $def['name'] ) ? $def['name'] : '—';
		$count  = ! empty( $def['fields'] ) ? count( $def['fields'] ) : 0;
		$synced = ! empty( $def['synced_at'] ) ? wp_date( 'Y-m-d H:i', $def['synced_at'] ) : '—';

		ob_start();
		?>
		<tr data-form-id="<?php echo esc_attr( $form_id ); ?>">
			<td><code><?php echo esc_html( $form_id ); ?></code></td>
			<td><?php echo esc_html( $name ); ?></td>
			<td><?php echo esc_html( $count ); ?></td>
			<td><?php echo esc_html( $synced ); ?></td>
			<td>
				<button type="button" class="button button-small hff-preview" data-form-id="<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Preview', 'hubspot-fallback-forms' ); ?></button>
				<button type="button" class="button button-small hff-resync" data-form-id="<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Re-sync', 'hubspot-fallback-forms' ); ?></button>
				<button type="button" class="button button-small hff-test" data-form-id="<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Send test', 'hubspot-fallback-forms' ); ?></button>
				<button type="button" class="button button-small hff-remove" data-form-id="<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Remove', 'hubspot-fallback-forms' ); ?></button>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = hff_get_settings();
		$forms = hff_get_forms();
		?>
		<div class="wrap hff-wrap">
			<h1><?php esc_html_e( 'HubSpot Fallback Forms', 'hubspot-fallback-forms' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'When enabled, embedded HubSpot forms are replaced site-wide with self-hosted HTML forms that email submissions through the Mailgun API. Use this as a safety net when HubSpot form embeds are unavailable.', 'hubspot-fallback-forms' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'hff_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'General', 'hubspot-fallback-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Fallback mode', 'hubspot-fallback-forms' ); ?></th>
						<td>
							<label class="hff-toggle">
								<input type="checkbox" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> />
								<?php esc_html_e( 'Replace HubSpot embeds with fallback forms (turn on when HubSpot is down)', 'hubspot-fallback-forms' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off = real HubSpot embeds show normally. On = fallback forms are shown everywhere and submissions are emailed via the Mailgun API.', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Form styling', 'hubspot-fallback-forms' ); ?></th>
						<td>
							<label class="hff-toggle">
								<input type="checkbox" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[sync_styles]" value="1" <?php checked( $s['sync_styles'], 1 ); ?> />
								<?php esc_html_e( 'Match the HubSpot form styling', 'hubspot-fallback-forms' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Outputs HubSpot\'s CSS class names (so your theme\'s existing form styles apply automatically) and applies each form\'s synced theme colors, fonts, and button style. Turn off to use only the plugin\'s plain default styling.', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hff_recipients"><?php esc_html_e( 'Recipient emails', 'hubspot-fallback-forms' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="hff_recipients" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[recipients]" value="<?php echo esc_attr( $s['recipients'] ); ?>" placeholder="sales@example.com, info@example.com" />
							<p class="description"><?php esc_html_e( 'Comma-separated. All submissions are sent here.', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email subject', 'hubspot-fallback-forms' ); ?></th>
						<td>
							<code>Hubspot Fallback Submission | <?php echo esc_html( get_bloginfo( 'name' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'The site title is appended automatically.', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'HubSpot', 'hubspot-fallback-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hff_portal_id"><?php esc_html_e( 'Portal ID', 'hubspot-fallback-forms' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="hff_portal_id" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[portal_id]" value="<?php echo esc_attr( $s['portal_id'] ); ?>" placeholder="1234567" />
							<p class="description">
								<?php esc_html_e( 'Your HubSpot portal (hub) ID — the "portalId" in your embed code. No API token is required; form fields are read from HubSpot\'s public form endpoint. Save this before syncing forms below.', 'hubspot-fallback-forms' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hff_region"><?php esc_html_e( 'Region', 'hubspot-fallback-forms' ); ?></label></th>
						<td>
							<input type="text" class="small-text" id="hff_region" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[region]" value="<?php echo esc_attr( $s['region'] ); ?>" placeholder="na1" />
							<p class="description"><?php esc_html_e( 'The "region" in your embed code (e.g. na1, eu1). Leave as na1 if unsure.', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Mailgun API', 'hubspot-fallback-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hff_mg_key"><?php esc_html_e( 'API key', 'hubspot-fallback-forms' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="hff_mg_key" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[mg_api_key]" value="" autocomplete="new-password" placeholder="<?php echo ! empty( $s['mg_api_key'] ) ? esc_attr__( '•••••••• (leave blank to keep current)', 'hubspot-fallback-forms' ) : 'key-…'; ?>" />
							<p class="description"><?php esc_html_e( 'Your Mailgun private API key (Mailgun → Settings → API keys). Leave blank to keep the currently saved key.', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hff_mg_domain"><?php esc_html_e( 'Sending domain', 'hubspot-fallback-forms' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="hff_mg_domain" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[mg_domain]" value="<?php echo esc_attr( $s['mg_domain'] ); ?>" placeholder="mg.example.com" />
							<p class="description"><?php esc_html_e( 'The verified Mailgun domain messages are sent through (e.g. mg.example.com).', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hff_mg_region"><?php esc_html_e( 'API region', 'hubspot-fallback-forms' ); ?></label></th>
						<td>
							<select id="hff_mg_region" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[mg_region]">
								<option value="us" <?php selected( $s['mg_region'], 'us' ); ?>><?php esc_html_e( 'US (api.mailgun.net)', 'hubspot-fallback-forms' ); ?></option>
								<option value="eu" <?php selected( $s['mg_region'], 'eu' ); ?>><?php esc_html_e( 'EU (api.eu.mailgun.net)', 'hubspot-fallback-forms' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Match the region your Mailgun account/domain is hosted in.', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hff_from_name"><?php esc_html_e( 'From name', 'hubspot-fallback-forms' ); ?></label></th>
						<td><input type="text" class="regular-text" id="hff_from_name" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hff_from_email"><?php esc_html_e( 'From email', 'hubspot-fallback-forms' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="hff_from_email" name="<?php echo esc_attr( HFF_OPT_SETTINGS ); ?>[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="noreply@mg.example.com" />
							<p class="description"><?php esc_html_e( 'Must be on your Mailgun sending domain. Defaults to postmaster@ your domain if left blank.', 'hubspot-fallback-forms' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'hubspot-fallback-forms' ) ); ?>
			</form>

			<hr />

			<h2 class="title"><?php esc_html_e( 'Cached forms', 'hubspot-fallback-forms' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Add each HubSpot form ID used across your site, then Sync to pull and cache its fields, consent boxes, and disclaimers. The cached copy is what the fallback renders — so sync while HubSpot is up.', 'hubspot-fallback-forms' ); ?>
			</p>

			<div class="hff-sync-add">
				<input type="text" id="hff_sync_form_id" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
				<button type="button" class="button button-primary" id="hff_sync_btn"><?php esc_html_e( 'Sync form', 'hubspot-fallback-forms' ); ?></button>
				<span id="hff_sync_status" class="hff-sync-status" aria-live="polite"></span>
			</div>

			<table class="widefat striped hff-forms-table" style="margin-top:12px;max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Form ID', 'hubspot-fallback-forms' ); ?></th>
						<th><?php esc_html_e( 'Name', 'hubspot-fallback-forms' ); ?></th>
						<th><?php esc_html_e( 'Fields', 'hubspot-fallback-forms' ); ?></th>
						<th><?php esc_html_e( 'Last synced', 'hubspot-fallback-forms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'hubspot-fallback-forms' ); ?></th>
					</tr>
				</thead>
				<tbody id="hff_forms_tbody">
					<?php if ( empty( $forms ) ) : ?>
						<tr class="hff-empty-row"><td colspan="5"><?php esc_html_e( 'No forms cached yet.', 'hubspot-fallback-forms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $forms as $fid => $def ) : ?>
							<?php echo $this->form_row_html( $fid, $def ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div id="hff_preview_wrap" class="hff-preview-wrap" style="display:none;">
				<h2 class="title">
					<?php esc_html_e( 'Preview', 'hubspot-fallback-forms' ); ?>
					<button type="button" class="button button-small" id="hff_preview_close"><?php esc_html_e( 'Close', 'hubspot-fallback-forms' ); ?></button>
				</h2>
				<p class="description"><?php esc_html_e( 'This is how the fallback form will render on the front end. Submissions are disabled in this preview.', 'hubspot-fallback-forms' ); ?></p>
				<div id="hff_preview" class="hff-preview-box"></div>
			</div>
		</div>
		<?php
	}
}
