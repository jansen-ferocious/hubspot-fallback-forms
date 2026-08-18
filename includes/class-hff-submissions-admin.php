<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen for viewing, deleting, and exporting stored submissions.
 */
class HFF_Submissions_Admin {

	const MENU_SLUG = 'hubspot-fallback-submissions';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_actions' ) );
		add_action( 'admin_post_hff_export_submissions', array( $this, 'export_csv' ) );
	}

	/**
	 * Add the Submissions page under Settings.
	 */
	public function add_menu() {
		add_options_page(
			__( 'Fallback Submissions', 'hubspot-fallback-forms' ),
			__( 'Fallback Submissions', 'hubspot-fallback-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Base URL for this screen.
	 *
	 * @return string
	 */
	protected function base_url() {
		return admin_url( 'options-general.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Handle delete actions early (before any output) so we can redirect.
	 */
	public function maybe_handle_actions() {
		if ( ! isset( $_REQUEST['page'] ) || self::MENU_SLUG !== $_REQUEST['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( $_REQUEST['action2'] ) : '';
		}

		if ( 'delete' !== $action ) {
			return;
		}

		// Bulk delete.
		if ( ! empty( $_REQUEST['submission'] ) && is_array( $_REQUEST['submission'] ) ) {
			check_admin_referer( 'bulk-submissions' );
			$ids     = array_map( 'intval', wp_unslash( $_REQUEST['submission'] ) );
			$deleted = HFF_Store::delete( $ids );
			$this->redirect_deleted( $deleted );
		}

		// Single delete.
		if ( isset( $_REQUEST['id'] ) ) {
			$id = (int) $_REQUEST['id'];
			check_admin_referer( 'hff_delete_' . $id );
			$deleted = HFF_Store::delete( $id );
			$this->redirect_deleted( $deleted );
		}
	}

	/**
	 * Redirect back to the list with a "deleted" notice.
	 *
	 * @param int $count
	 */
	protected function redirect_deleted( $count ) {
		wp_safe_redirect( add_query_arg( 'hff_deleted', (int) $count, $this->base_url() ) );
		exit;
	}

	/**
	 * Render the page (list or single detail view).
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';

		if ( 'view' === $action && isset( $_REQUEST['id'] ) ) {
			$id = (int) $_REQUEST['id'];
			check_admin_referer( 'hff_view_' . $id );
			$this->render_detail( $id );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the submissions list table.
	 */
	protected function render_list() {
		require_once HFF_DIR . 'includes/class-hff-submissions-table.php';
		$table = new HFF_Submissions_Table();
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'    => 'hff_export_submissions',
						'form_id'   => isset( $_REQUEST['form_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['form_id'] ) ) : '',
						'date_from' => isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '',
						'date_to'   => isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '',
						's'         => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
					)
				),
				admin_url( 'admin-post.php' )
			),
			'hff_export'
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Fallback Submissions', 'hubspot-fallback-forms' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'hubspot-fallback-forms' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=hubspot-fallback-forms' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Settings', 'hubspot-fallback-forms' ); ?></a>
			<hr class="wp-header-end" />

			<?php if ( isset( $_GET['hff_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					$n = (int) $_GET['hff_deleted'];
					/* translators: %d: number of deleted submissions */
					echo esc_html( sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $n, 'hubspot-fallback-forms' ), $n ) );
					?>
				</p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Every fallback form submission is stored here (in addition to being emailed). Export to CSV to re-import leads into HubSpot after an outage.', 'hubspot-fallback-forms' ); ?>
			</p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<?php
				$table->search_box( __( 'Search submissions', 'hubspot-fallback-forms' ), 'hff-submissions' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single submission's full detail.
	 *
	 * @param int $id
	 */
	protected function render_detail( $id ) {
		$row = HFF_Store::get( $id );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Submission detail', 'hubspot-fallback-forms' ); ?></h1>
			<a href="<?php echo esc_url( $this->base_url() ); ?>" class="page-title-action"><?php esc_html_e( '&larr; Back to list', 'hubspot-fallback-forms' ); ?></a>
			<hr class="wp-header-end" />
			<?php if ( ! $row ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Submission not found.', 'hubspot-fallback-forms' ); ?></p></div>
				</div>
				<?php
				return;
			endif;

			$data     = json_decode( $row['data'], true );
			$fields   = ( is_array( $data ) && ! empty( $data['fields'] ) ) ? $data['fields'] : array();
			$consents = ( is_array( $data ) && ! empty( $data['consents'] ) ) ? $data['consents'] : array();
			?>
			<table class="widefat striped" style="max-width:760px;margin-top:12px;">
				<tbody>
					<tr><th style="width:30%;"><?php esc_html_e( 'Date', 'hubspot-fallback-forms' ); ?></th><td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $row['created_at'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Form', 'hubspot-fallback-forms' ); ?></th><td><?php echo esc_html( '' !== $row['form_name'] ? $row['form_name'] : $row['form_id'] ); ?> <code><?php echo esc_html( $row['form_id'] ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Email status', 'hubspot-fallback-forms' ); ?></th><td><?php echo esc_html( ucfirst( $row['email_status'] ) ); ?><?php echo $row['email_error'] ? ' — ' . esc_html( $row['email_error'] ) : ''; ?></td></tr>
					<tr><th><?php esc_html_e( 'Submitted from', 'hubspot-fallback-forms' ); ?></th><td><?php echo $row['page_url'] ? '<a href="' . esc_url( $row['page_url'] ) . '">' . esc_html( $row['page_url'] ) . '</a>' : '&mdash;'; ?></td></tr>
					<tr><th><?php esc_html_e( 'IP address', 'hubspot-fallback-forms' ); ?></th><td><?php echo esc_html( $row['ip'] ? $row['ip'] : '—' ); ?></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Fields', 'hubspot-fallback-forms' ); ?></h2>
			<table class="widefat striped" style="max-width:760px;">
				<tbody>
					<?php foreach ( $fields as $f ) : ?>
						<tr>
							<th style="width:30%;"><?php echo esc_html( $f['label'] ); ?></th>
							<td><?php echo nl2br( esc_html( is_array( $f['value'] ) ? implode( ', ', $f['value'] ) : $f['value'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $consents as $c ) : ?>
						<tr>
							<th><?php esc_html_e( 'Consent', 'hubspot-fallback-forms' ); ?></th>
							<td><?php echo ! empty( $c['checked'] ) ? '&#10003; ' : '&#10007; '; ?><?php echo esc_html( $c['label'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Stream a CSV export of all submissions matching the current filters.
	 */
	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'hubspot-fallback-forms' ) );
		}
		check_admin_referer( 'hff_export' );

		$filters = array(
			'form_id'   => isset( $_GET['form_id'] ) ? sanitize_text_field( wp_unslash( $_GET['form_id'] ) ) : '',
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);

		// Fetch all matching rows (batched to bound memory).
		$all    = array();
		$offset = 0;
		$batch  = 500;
		do {
			$rows = HFF_Store::query(
				array_merge(
					$filters,
					array(
						'orderby'  => 'created_at',
						'order'    => 'ASC',
						'per_page' => $batch,
						'offset'   => $offset,
					)
				)
			);
			$all     = array_merge( $all, $rows );
			$offset += $batch;
		} while ( count( $rows ) === $batch );

		// Build the column set: fixed columns + union of all field/consent labels.
		$field_cols   = array(); // name => label
		$consent_cols = array(); // label => label
		$decoded      = array();
		foreach ( $all as $r ) {
			$d          = json_decode( $r['data'], true );
			$decoded[]  = array( 'row' => $r, 'data' => $d );
			if ( ! empty( $d['fields'] ) ) {
				foreach ( $d['fields'] as $f ) {
					if ( ! isset( $field_cols[ $f['name'] ] ) ) {
						$field_cols[ $f['name'] ] = $f['label'];
					}
				}
			}
			if ( ! empty( $d['consents'] ) ) {
				foreach ( $d['consents'] as $c ) {
					$consent_cols[ $c['label'] ] = $c['label'];
				}
			}
		}

		$filename = 'hubspot-fallback-submissions-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		// Header row.
		$header = array( 'Date', 'Form', 'Form ID', 'Email status', 'Page URL', 'IP' );
		foreach ( $field_cols as $label ) {
			$header[] = $label;
		}
		foreach ( $consent_cols as $label ) {
			$header[] = 'Consent: ' . $label;
		}
		fputcsv( $out, $header );

		// Data rows.
		foreach ( $decoded as $entry ) {
			$r = $entry['row'];
			$d = $entry['data'];

			$values = array();
			if ( ! empty( $d['fields'] ) ) {
				foreach ( $d['fields'] as $f ) {
					$values[ $f['name'] ] = is_array( $f['value'] ) ? implode( ', ', $f['value'] ) : $f['value'];
				}
			}
			$consent_vals = array();
			if ( ! empty( $d['consents'] ) ) {
				foreach ( $d['consents'] as $c ) {
					$consent_vals[ $c['label'] ] = ! empty( $c['checked'] ) ? 'Yes' : 'No';
				}
			}

			$line = array(
				$r['created_at'],
				'' !== $r['form_name'] ? $r['form_name'] : $r['form_id'],
				$r['form_id'],
				$r['email_status'],
				$r['page_url'],
				$r['ip'],
			);
			foreach ( $field_cols as $name => $label ) {
				$line[] = isset( $values[ $name ] ) ? $values[ $name ] : '';
			}
			foreach ( $consent_cols as $label => $unused ) {
				$line[] = isset( $consent_vals[ $label ] ) ? $consent_vals[ $label ] : '';
			}
			fputcsv( $out, $line );
		}

		fclose( $out );
		exit;
	}
}
