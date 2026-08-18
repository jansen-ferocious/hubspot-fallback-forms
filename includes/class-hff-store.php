<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists fallback form submissions to a custom database table and provides
 * query/delete helpers for the admin viewer and CSV export.
 */
class HFF_Store {

	const TABLE             = 'hff_submissions';
	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'hff_db_version';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create/upgrade the table if the stored DB version is out of date.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create the submissions table.
	 */
	public static function install() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			form_id varchar(191) NOT NULL DEFAULT '',
			form_name varchar(191) NOT NULL DEFAULT '',
			page_url text NULL,
			ip varchar(100) NOT NULL DEFAULT '',
			email_status varchar(20) NOT NULL DEFAULT '',
			email_error text NULL,
			data longtext NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY created_at (created_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Insert a submission.
	 *
	 * @param array $row Keys: created_at, form_id, form_name, page_url, ip,
	 *                   email_status, email_error, data (array).
	 * @return int|false Insert ID or false on failure.
	 */
	public static function insert( $row ) {
		global $wpdb;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table_name(),
			array(
				'created_at'   => isset( $row['created_at'] ) ? $row['created_at'] : current_time( 'mysql' ),
				'form_id'      => isset( $row['form_id'] ) ? $row['form_id'] : '',
				'form_name'    => isset( $row['form_name'] ) ? $row['form_name'] : '',
				'page_url'     => isset( $row['page_url'] ) ? $row['page_url'] : '',
				'ip'           => isset( $row['ip'] ) ? $row['ip'] : '',
				'email_status' => isset( $row['email_status'] ) ? $row['email_status'] : '',
				'email_error'  => isset( $row['email_error'] ) ? $row['email_error'] : '',
				'data'         => wp_json_encode( isset( $row['data'] ) ? $row['data'] : array() ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Build a WHERE clause + params from filter args.
	 *
	 * @param array $a
	 * @return array [ string $where, array $params ]
	 */
	protected static function build_where( $a ) {
		global $wpdb;
		$clauses = array();
		$params  = array();

		if ( ! empty( $a['form_id'] ) ) {
			$clauses[] = 'form_id = %s';
			$params[]  = $a['form_id'];
		}
		if ( ! empty( $a['date_from'] ) ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $a['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $a['date_to'] ) ) {
			$clauses[] = 'created_at <= %s';
			$params[]  = $a['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $a['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( $a['search'] ) . '%';
			$clauses[] = '(data LIKE %s OR form_name LIKE %s OR page_url LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
		return array( $where, $params );
	}

	/**
	 * Query submissions.
	 *
	 * @param array $args form_id, date_from, date_to, search, orderby, order, per_page, offset.
	 * @return array Rows as associative arrays.
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 20,
				'offset'   => 0,
			)
		);

		list( $where, $params ) = self::build_where( $a );

		$orderby = in_array( $a['orderby'], array( 'created_at', 'form_name', 'id', 'email_status' ), true ) ? $a['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $a['order'] ) ? 'ASC' : 'DESC';
		$table   = self::table_name();

		$params[] = (int) $a['per_page'];
		$params[] = (int) $a['offset'];

		// $table, $orderby, $order are whitelisted above; values are parameterized.
		$sql = $wpdb->prepare( "SELECT * FROM $table $where ORDER BY $orderby $order LIMIT %d OFFSET %d", $params ); // phpcs:ignore

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Count submissions matching filters.
	 *
	 * @param array $args
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::build_where( wp_parse_args( $args, array() ) );
		$table                  = self::table_name();
		$sql                    = "SELECT COUNT(*) FROM $table $where";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/**
	 * Fetch a single submission by ID.
	 *
	 * @param int $id
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Delete submissions by ID(s).
	 *
	 * @param array|int $ids
	 * @return int Rows deleted.
	 */
	public static function delete( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$table  = self::table_name();
		$place  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($place)", $ids ) ); // phpcs:ignore
	}

	/**
	 * Distinct forms that have submissions (for the filter dropdown).
	 *
	 * @return array Rows with form_id, form_name.
	 */
	public static function forms() {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results( "SELECT DISTINCT form_id, form_name FROM $table ORDER BY form_name ASC", ARRAY_A ); // phpcs:ignore
	}
}
