<?php
/**
 * Phase P3: WP Mail SMTP's own Dashboard page (4.10+) fed from OUR email log. Required lazily by
 * Mailroom_Bridge, and only after dashboard_missing() has verified (by reflection, before this file is
 * compiled) that every parent class exists, is extensible, and that every method overridden here still has
 * exactly the signature this file was written against. NOT in the loader list: the parents do not exist yet when
 * mu-plugins are included.
 *
 * Only the plugin's documented subclass seams are used (Dashboard::get_stats, the protected Stats counter/metric
 * methods, widget get_state/render_body, StatCards::get_cards, EmailsOverview::get_stats/get_email_alerts_card).
 * Nothing here touches WP Mail SMTP's options, counters, licence or product state, and no label says "Pro".
 * Every override catches \Throwable and falls back to the parent (the plugin's own Lite behaviour).
 */

defined( 'ABSPATH' ) || exit;

use WPMailSMTP\Admin\Dashboard\Dashboard;
use WPMailSMTP\Admin\Dashboard\Stats;
use WPMailSMTP\Admin\Dashboard\WidgetState;
use WPMailSMTP\Admin\Dashboard\Widgets\EmailLog;
use WPMailSMTP\Admin\Dashboard\Widgets\EmailsOverview;
use WPMailSMTP\Admin\Dashboard\Widgets\EmailSources;
use WPMailSMTP\Admin\Dashboard\Widgets\StatCards;

/** The Dashboard page orchestrator: only the statistics come from our log. */
final class Mailroom_Native_Dashboard extends Dashboard {

	protected function get_stats() {
		try {
			return new Mailroom_Native_Stats();
		} catch ( \Throwable $e ) {
			return parent::get_stats();
		}
	}
}

/**
 * Weekly counters from the log instead of Lite's option counters. The parent derives the series, last-week totals,
 * deltas and the failed-email prompts from get_counters(), so overriding the counters (and the metric list) is
 * enough; it keeps the parent's ISO-week keys and its 12-week window.
 */
final class Mailroom_Native_Stats extends Stats {

	protected function get_sent_counters() {
		try {
			return Mailroom_Dashdata::weekly()['sent'];
		} catch ( \Throwable $e ) {
			return parent::get_sent_counters();
		}
	}

	protected function get_failed_counters() {
		try {
			return Mailroom_Dashdata::weekly()['failed'];
		} catch ( \Throwable $e ) {
			return parent::get_failed_counters();
		}
	}

	/** Adds the `backup` and `queued` metrics (the parent knows only sent and failed). */
	protected function get_counters( $metric ) {
		try {
			$weekly = Mailroom_Dashdata::weekly();
			if ( is_string( $metric ) && isset( $weekly[ $metric ] ) ) {
				return $weekly[ $metric ];
			}
		} catch ( \Throwable $e ) {
			// fall through to the parent
		}
		return parent::get_counters( $metric );
	}

	protected function get_stat_metrics(): array {
		return array_merge( parent::get_stat_metrics(), [ 'backup', 'queued' ] );
	}

	/** Last complete week: the parent's sent and failed, plus the two metrics only our log has. */
	public function get_stat_totals( ?array $range = null ): array {
		$totals = parent::get_stat_totals( $range );
		try {
			$week = $this->get_week_number( 1 );
			foreach ( [ 'backup', 'queued' ] as $metric ) {
				$totals[ $metric ] = (int) ( $this->get_counters( $metric )[ $week ] ?? 0 );
			}
		} catch ( \Throwable $e ) {
			return parent::get_stat_totals( $range );
		}
		return $totals;
	}

	/** No percent change when the log does not reach back over the week it would compare against (retention). */
	public function get_delta( $metric ) {
		try {
			if ( ! Mailroom_Dashdata::covers_week( 2 ) ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			// fall through to the parent
		}
		return parent::get_delta( $metric );
	}

	/** The chart starts at the first week the log has data for, instead of drawing pruned weeks as zero. */
	public function get_series( ?array $range = null ): array {
		$series = parent::get_series( $range );
		try {
			$drop = Mailroom_Dashdata::leading_weeks_without_log();
			if ( $drop > 0 && $drop < count( $series ) ) {
				return array_slice( $series, $drop );
			}
		} catch ( \Throwable $e ) {
			return $series;
		}
		return $series;
	}
}

/**
 * The four stat cards. WP Mail SMTP shows two locked placeholder cards ("Sent" and "Opened", with a made-up blurred
 * figure and an upgrade link); open tracking is something we do not have, so those slots show two figures our log
 * does have: emails delivered through the backup, and emails held by the rate limit.
 */
final class Mailroom_Native_Stat_Cards extends StatCards {

	public function get_cards( array $data ) {
		$cards = parent::get_cards( $data );
		try {
			if ( ! is_array( $cards ) || ! isset( $data['stat_totals'] ) || ! is_array( $data['stat_totals'] ) ) {
				return $cards;
			}
			$replace = [
				'sent'   => [ 'backup', __( 'Via Backup Last Week', 'mailroom' ), 'fa6-solid--route', 'sent' ],
				'opened' => [ 'queued', __( 'Queued Last Week', 'mailroom' ), 'fa6-solid--hourglass', 'opened' ],
			];
			$out     = [];
			foreach ( $cards as $card ) {
				$id = is_array( $card ) ? ( $card['id'] ?? '' ) : '';
				if ( ! empty( $card['locked'] ) && isset( $replace[ $id ] ) && array_key_exists( $replace[ $id ][0], $data['stat_totals'] ) ) {
					[ $metric, $label, $icon, $tone ] = $replace[ $id ];
					$delta                            = $data['stat_deltas'][ $metric ] ?? null;
					$card                             = [
						'id'           => $metric,
						'label'        => $label,
						'value'        => (int) $data['stat_totals'][ $metric ],
						'delta'        => is_numeric( $delta ) ? (float) $delta : null,
						'locked'       => false,
						'icon'         => $icon,
						'tone'         => $tone,
						'teaser_value' => 0,
						'teaser_blur'  => 0.0,
					];
				}
				$out[] = $card;
			}
			return $out;
		} catch ( \Throwable $e ) {
			return $cards;
		}
	}
}

/**
 * Emails overview chart: its has-data check reads its own Stats instance (Lite counters), which would hide the
 * chart while our log has data. The "Set up email alerts" prompt is rewritten because Lite's wording sends the
 * reader to an upgrade for a feature this site already has (alerts live under Settings → Alerts).
 */
final class Mailroom_Native_Emails_Overview extends EmailsOverview {

	protected function get_stats() {
		try {
			return new Mailroom_Native_Stats();
		} catch ( \Throwable $e ) {
			return parent::get_stats();
		}
	}

	protected function get_email_alerts_card( array $data ): array {
		try {
			if ( empty( $data['has_high_failure_rate'] ) || Mailroom_Dashdata::alerts_configured() ) {
				return [];
			}
			$count = (int) ( $data['recent_failed_count'] ?? 0 );
			return [
				'id'    => 'email_alerts',
				'icon'  => 'fa6-solid--bell',
				/* translators: %d: number of failed emails */
				'title' => sprintf( _n( 'Your Site Had %d Failed Email in the Last 5 Weeks', 'Your Site Had %d Failed Emails in the Last 5 Weeks', $count, 'mailroom' ), $count ),
				'text'  => __( 'Get notified by webhook or email when an email fails to send.', 'mailroom' ),
				'cta'   => [
					'label'        => __( 'Set Up Alerts', 'mailroom' ),
					'url'          => Mailroom_Bridge::url( 'alerts' ),
					'target_blank' => false,
				],
			];
		} catch ( \Throwable $e ) {
			return parent::get_email_alerts_card( $data );
		}
	}
}

/** The newest emails, from our log, each row opening our single-email view. */
final class Mailroom_Native_Email_Log extends EmailLog {

	public function get_state(): WidgetState {
		try {
			return new WidgetState( true, Mailroom_Dashdata::has_rows() ? 'data' : 'connect' );
		} catch ( \Throwable $e ) {
			return new WidgetState( false );
		}
	}

	protected function render_body( string $variant, array $data ): string {
		try {
			if ( ! current_user_can( Mailroom::CAP ) ) {
				return '';
			}
			if ( $variant !== 'data' ) {
				return '<p>' . esc_html__( 'No emails logged yet. Once your site sends email, the most recent ones are listed here.', 'mailroom' ) . '</p>';
			}
			$html = (string) wp_mail_smtp_render(
				'dashboard/widgets/email-log',
				[
					'variant'        => 'data',
					'connect_reason' => '',
					'education_url'  => '',
					'connect_url'    => '',
					'test_email_url' => '',
					'rows'           => Mailroom_Dashdata::email_log_rows( Mailroom_Dashdata::LOG_ROWS ),
				],
				true
			);
			return $html . '<p><a href="' . esc_url( Mailroom_Bridge::url( 'log' ) ) . '">' . esc_html__( 'View all emails', 'mailroom' ) . '</a></p>';
		} catch ( \Throwable $e ) {
			return '<p>' . esc_html__( 'The email log could not be displayed.', 'mailroom' ) . '</p>';
		}
	}
}

/**
 * Email Sources groups emails by the plugin that sent them. Our log does not record which plugin or WordPress
 * feature created an email, so this says so instead of showing a made-up breakdown or an upgrade prompt.
 */
final class Mailroom_Native_Email_Sources extends EmailSources {

	public function get_state(): WidgetState {
		return new WidgetState( true, 'data' );
	}

	protected function render_body( string $variant, array $data ): string {
		return '<p>' . esc_html__( 'Not tracked. The email log records when and how each email was delivered, but not which plugin or WordPress feature sent it.', 'mailroom' ) . '</p>'
			. '<p><a href="' . esc_url( Mailroom_Bridge::url( 'log' ) ) . '">' . esc_html__( 'Open the email log', 'mailroom' ) . '</a></p>';
	}
}
