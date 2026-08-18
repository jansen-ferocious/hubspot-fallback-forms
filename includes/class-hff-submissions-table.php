<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin list table for stored fallback submissions.
 */
class HFF_Submissions_Table extends WP_List_Table {

	/**
	 * Base admin URL for this screen.
	 *
	 * @var string
	 */
	protected $base_url;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			)
		);
		$this->base_url = admin_url( 'options-general.php?page=hubspot-fallback-submissions' );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'created_at'   => __( 'Date', 'hubspot-fallback-forms' ),
			'form_name'    => __( 'Form', 'hubspot-fallback-forms' ),
			'summary'      => __( 'Submission', 'hubspot-fallback-forms' ),
			'email_status' => __( 'Email', 'hubspot-fallback-forms' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'form_name'  => array( 'form_name', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'hubspot-fallback-forms' ) );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="submission[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Date column with row actions.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_created_at( $item ) {
		$view_url   = wp_nonce_url( add_query_arg( array( 'action' => 'view', 'id' => (int) $item['id'] ), $this->base_url ), 'hff_view_' . (int) $item['id'] );
		$delete_url = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'id' => (int) $item['id'] ), $this->base_url ), 'hff_delete_' . (int) $item['id'] );

		$actions = array(
			'view'   => '<a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'hubspot-fallback-forms' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this submission?', 'hubspot-fallback-forms' ) ) . '\');">' . esc_html__( 'Delete', 'hubspot-fallback-forms' ) . '</a>',
		);

		$date = mysql2date( 'Y-m-d H:i', $item['created_at'] );
		return '<strong><a href="' . esc_url( $view_url ) . '">' . esc_html( $date ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Form column.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_form_name( $item ) {
		$name = '' !== $item['form_name'] ? $item['form_name'] : $item['form_id'];
		return esc_html( $name );
	}

	/**
	 * Email-status column.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_email_status( $item ) {
		if ( 'sent' === $item['email_status'] ) {
			return '<span style="color:#15803d;">&#10003; ' . esc_html__( 'Sent', 'hubspot-fallback-forms' ) . '</span>';
		}
		if ( 'failed' === $item['email_status'] ) {
			$err = $item['email_error'] ? ' title="' . esc_attr( $item['email_error'] ) . '"' : '';
			return '<span style="color:#b91c1c;"' . $err . '>&#10007; ' . esc_html__( 'Failed', 'hubspot-fallback-forms' ) . '</span>';
		}
		return esc_html( $item['email_status'] );
	}

	/**
	 * Summary column: a couple of recognizable field values.
	 *
	 * @param array $item
	 * @return string
	 */
	public function column_summary( $item ) {
		$data   = json_decode( $item['data'], true );
		$fields = ( is_array( $data ) && ! empty( $data['fields'] ) ) ? $data['fields'] : array();

		$bits = array();
		foreach ( $fields as $f ) {
			if ( empty( $f['value'] ) ) {
				continue;
			}
			$bits[] = '<strong>' . esc_html( $f['label'] ) . ':</strong> ' . esc_html( wp_html_excerpt( $f['value'], 60, '…' ) );
			if ( count( $bits ) >= 3 ) {
				break;
			}
		}
		return $bits ? implode( '<br />', $bits ) : '&mdash;';
	}

	/**
	 * Fallback column renderer.
	 *
	 * @param array  $item
	 * @param string $column_name
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Form + date filters above the table.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$form_id   = isset( $_REQUEST['form_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['form_id'] ) ) : '';
		$date_from = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '';
		$date_to   = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '';
		$forms     = HFF_Store::forms();
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="hff-filter-form"><?php esc_html_e( 'Filter by form', 'hubspot-fallback-forms' ); ?></label>
			<select name="form_id" id="hff-filter-form">
				<option value=""><?php esc_html_e( 'All forms', 'hubspot-fallback-forms' ); ?></option>
				<?php foreach ( $forms as $f ) : ?>
					<option value="<?php echo esc_attr( $f['form_id'] ); ?>" <?php selected( $form_id, $f['form_id'] ); ?>>
						<?php echo esc_html( '' !== $f['form_name'] ? $f['form_name'] : $f['form_id'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" aria-label="<?php esc_attr_e( 'From date', 'hubspot-fallback-forms' ); ?>" />
			<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" aria-label="<?php esc_attr_e( 'To date', 'hubspot-fallback-forms' ); ?>" />
			<?php submit_button( __( 'Filter', 'hubspot-fallback-forms' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Query + pagination.
	 */
	public function prepare_items() {
		$per_page = 20;
		$paged    = max( 1, (int) $this->get_pagenum() );

		$args = array(
			'form_id'   => isset( $_REQUEST['form_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['form_id'] ) ) : '',
			'date_from' => isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '',
			'date_to'   => isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '',
			'search'    => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'orderby'   => isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'created_at',
			'order'     => isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc',
		);

		$total = HFF_Store::count( $args );

		$args['per_page'] = $per_page;
		$args['offset']   = ( $paged - 1 ) * $per_page;

		$this->items = HFF_Store::query( $args );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}
}
