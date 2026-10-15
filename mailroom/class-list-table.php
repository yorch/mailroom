<?php
/**
 * The Log tab's table. Loaded on demand by Mailroom_Admin (after it has required WP_List_Table),
 * never by the mu-plugin loader: the base class is an admin-only include and must not be pulled into
 * front-end requests.
 *
 * Reads only the columns it shows. `content`/`mime` can be megabytes per row, so `content` is selected as a
 * 0/1 flag and `mime` not at all.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_List_Table extends WP_List_Table {

	/** @var array normalised filters (Mailroom_Admin::filters()) */
	private $filters;

	/** @var int */
	private $per_page;

	/** @var bool whether the detail view (and so View / Resend) is available */
	private $has_detail;

	public function __construct( array $filters, int $per_page = 50, bool $has_detail = true ) {
		$this->filters    = $filters;
		$this->per_page   = max( 1, $per_page );
		$this->has_detail = $has_detail;
		$args             = [ 'singular' => 'email', 'plural' => 'emails', 'ajax' => false ];
		if ( ! get_current_screen() ) {
			$args['screen'] = Mailroom_UI::PAGE; // outside a real admin request (CLI harness)
		}
		parent::__construct( $args );
	}

	public function get_columns() {
		return [
			// Primary column first: WP's mobile CSS only lays out the cells that follow `.column-primary`.
			'subject'  => __( 'Subject', 'mailroom' ),
			'when'     => __( 'When', 'mailroom' ),
			'status'   => __( 'Status', 'mailroom' ),
			'to'       => __( 'To', 'mailroom' ),
			'source'   => __( 'Mailer / source', 'mailroom' ),
			'duration' => __( 'Duration', 'mailroom' ),
		];
	}

	// Fixed columns: no sorting, no hidden-columns screen option, no per-screen header filters.
	protected function get_column_info() {
		return [ $this->get_columns(), [], [], 'subject' ];
	}

	protected function get_default_primary_column_name() {
		return 'subject';
	}

	protected function get_primary_column_name() {
		return 'subject';
	}

	public function prepare_items() {
		global $wpdb;
		$table            = Mailroom::table();
		[ $where, $args ] = Mailroom_Admin::where( $this->filters );
		$offset           = ( $this->get_pagenum() - 1 ) * $this->per_page;

		// Never SELECT * here (see the file header).
		$sql         = "SELECT id, created_at, status, source, mailer, to_email, subject, error, duration_ms, resent_of, ( content IS NOT NULL ) AS content FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$this->items = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $this->per_page, $offset ] ) ) ); // phpcs:ignore
		$total       = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$args ) : "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		$this->set_pagination_args( [ 'total_items' => $total, 'per_page' => $this->per_page ] );
	}

	public function no_items() {
		echo array_filter( $this->filters, 'strlen' ) ? esc_html__( 'No emails match these filters.', 'mailroom' ) : esc_html__( 'No entries yet.', 'mailroom' );
	}

	public function column_when( $r ) {
		return Mailroom_UI::when( (string) $r->created_at );
	}

	public function column_status( $r ) {
		return Mailroom_UI::badge( (string) $r->status );
	}

	public function column_to( $r ) {
		$to = (string) $r->to_email;
		return '<span title="' . esc_attr( $to ) . '">' . esc_html( mb_strimwidth( $to, 0, 60, '…' ) ) . '</span>';
	}

	public function column_subject( $r ) {
		$id      = (int) $r->id;
		$subject = mb_substr( (string) $r->subject, 0, 80 );
		$label   = $subject !== '' ? $subject : __( '(no subject)', 'mailroom' );
		$view    = Mailroom_UI::view_url( $id );
		$out     = $this->has_detail ? '<strong><a class="row-title" href="' . esc_url( $view ) . '">' . esc_html( $label ) . '</a></strong>' : '<strong>' . esc_html( $label ) . '</strong>';
		if ( ! empty( $r->content ) ) {
			$out .= ' <span title="' . esc_attr__( 'Full email stored (resend / EML)', 'mailroom' ) . '" class="dashicons dashicons-email-alt" style="font-size:16px;width:16px;height:16px;color:#2271b1"></span>';
		}
		if ( ! empty( $r->resent_of ) ) {
			$out .= ' <small>(' . esc_html( sprintf( /* translators: %d: log id */ __( 'resend of #%d', 'mailroom' ), (int) $r->resent_of ) ) . ')</small>';
		}
		if ( $r->error ) {
			$out .= '<code class="mailroom-err">' . esc_html( mb_substr( (string) $r->error, 0, 200 ) ) . '</code>';
		}
		if ( $this->has_detail ) {
			$actions = [ 'view' => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'mailroom' ) . '</a>' ];
			if ( ! empty( $r->content ) ) {
				// POST + nonce: a link must never send mail.
				$actions['resend'] = Mailroom_UI::action_form( 'mailroom_resend', 'mailroom_resend_' . $id, __( 'Resend', 'mailroom' ), 'log', [ 'id' => $id ], 'button-link', __( 'Resend this email to its original recipients?', 'mailroom' ) );
			}
			$out .= $this->row_actions( $actions );
		}
		return $out;
	}

	public function column_source( $r ) {
		$parts = array_filter( [ (string) $r->mailer, (string) $r->source ], 'strlen' );
		return '<span class="mailroom-muted">' . esc_html( implode( ' / ', $parts ) ) . '</span>';
	}

	public function column_duration( $r ) {
		return (int) $r->duration_ms > 0 ? esc_html( (int) $r->duration_ms . ' ms' ) : '<span class="mailroom-muted">&mdash;</span>';
	}

	public function column_default( $item, $column_name ) {
		return '';
	}
}
