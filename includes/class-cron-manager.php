<?php
/**
 * Cron manager.
 *
 * @package DB_Backup_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron manager class.
 */
class DBBP_Cron_Manager {
	/**
	 * Backup engine.
	 *
	 * @var DBBP_Backup_Engine|null
	 */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param DBBP_Backup_Engine|null $engine Backup engine.
	 */
	public function __construct( $engine = null ) {
		$this->engine = $engine;
	}

	/**
	 * Register cron hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
		add_action( 'dbbp_daily_tick', array( $this, 'maybe_run_daily' ) );
		add_action( 'dbbp_monthly_tick', array( $this, 'maybe_run_monthly' ) );
		add_action( 'dbbp_yearly_tick', array( $this, 'maybe_run_yearly' ) );
		add_action( 'init', array( $this, 'maybe_reschedule' ) );
	}

	/**
	 * Activation scheduling.
	 *
	 * @return void
	 */
	public function activate() {
		$this->schedule_events();
	}

	/**
	 * Deactivation cleanup.
	 *
	 * @return void
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'dbbp_daily_tick' );
		wp_clear_scheduled_hook( 'dbbp_monthly_tick' );
		wp_clear_scheduled_hook( 'dbbp_yearly_tick' );
	}

	/**
	 * Add custom schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedules( $schedules ) {
		$schedules['dbbp_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'DB Backup Pro Hourly', 'db-backup-pro' ),
		);
		return $schedules;
	}

	/**
	 * Schedule events.
	 *
	 * @return void
	 */
	public function schedule_events() {
		$settings = get_option( 'dbbp_settings', array() );
		if ( ! empty( $settings['schedule']['use_server_cron'] ) ) {
			$this->deactivate();
			return;
		}

		if ( ! wp_next_scheduled( 'dbbp_daily_tick' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'dbbp_hourly', 'dbbp_daily_tick' );
		}
		if ( ! wp_next_scheduled( 'dbbp_monthly_tick' ) ) {
			wp_schedule_event( time() + ( 2 * MINUTE_IN_SECONDS ), 'dbbp_hourly', 'dbbp_monthly_tick' );
		}
		if ( ! wp_next_scheduled( 'dbbp_yearly_tick' ) ) {
			wp_schedule_event( time() + ( 3 * MINUTE_IN_SECONDS ), 'dbbp_hourly', 'dbbp_yearly_tick' );
		}
	}

	/**
	 * Reschedule when settings change.
	 *
	 * @return void
	 */
	public function maybe_reschedule() {
		if ( get_transient( 'dbbp_reschedule_needed' ) ) {
			$this->deactivate();
			$this->schedule_events();
			delete_transient( 'dbbp_reschedule_needed' );
		}
	}

	/**
	 * Run daily when due.
	 *
	 * @return void
	 */
	public function maybe_run_daily() {
		if ( ! $this->is_due( 'daily' ) ) {
			return;
		}
		if ( $this->engine ) {
			$this->engine->run_backup_for_all( 'daily' );
		}
		$this->mark_run( 'daily' );
	}

	/**
	 * Run monthly when due.
	 *
	 * @return void
	 */
	public function maybe_run_monthly() {
		if ( ! $this->is_due( 'monthly' ) ) {
			return;
		}
		if ( (int) wp_date( 'j' ) !== 1 ) {
			return;
		}
		if ( $this->engine ) {
			$this->engine->run_backup_for_all( 'monthly' );
		}
		$this->mark_run( 'monthly' );
	}

	/**
	 * Run yearly when due.
	 *
	 * @return void
	 */
	public function maybe_run_yearly() {
		if ( ! $this->is_due( 'yearly' ) ) {
			return;
		}
		if ( '01-01' !== wp_date( 'm-d' ) ) {
			return;
		}
		if ( $this->engine ) {
			$this->engine->run_backup_for_all( 'yearly' );
		}
		$this->mark_run( 'yearly' );
	}

	/**
	 * Check if schedule is due.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	private function is_due( $type ) {
		$settings   = get_option( 'dbbp_settings', array() );
		$time_key   = $type . '_time';
		$time_value = $settings['schedule'][ $time_key ] ?? '02:00';
		$parts      = array_map( 'intval', explode( ':', $time_value ) );
		$hour       = $parts[0] ?? 2;
		$minute     = $parts[1] ?? 0;

		$current_hour   = (int) wp_date( 'G' );
		$current_minute = (int) wp_date( 'i' );
		if ( $current_hour !== $hour ) {
			return false;
		}
		if ( abs( $current_minute - $minute ) > 10 ) {
			return false;
		}

		$last = $settings['last_schedule_run'][ $type ] ?? '';
		if ( $last === wp_date( 'Y-m-d' ) ) {
			return false;
		}
		if ( 'yearly' === $type && $last === wp_date( 'Y' ) ) {
			return false;
		}
		if ( 'monthly' === $type && $last === wp_date( 'Y-m' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Mark schedule run.
	 *
	 * @param string $type Type.
	 * @return void
	 */
	private function mark_run( $type ) {
		$settings = get_option( 'dbbp_settings', array() );
		if ( empty( $settings['last_schedule_run'] ) || ! is_array( $settings['last_schedule_run'] ) ) {
			$settings['last_schedule_run'] = array();
		}

		if ( 'daily' === $type ) {
			$settings['last_schedule_run']['daily'] = wp_date( 'Y-m-d' );
		}
		if ( 'monthly' === $type ) {
			$settings['last_schedule_run']['monthly'] = wp_date( 'Y-m' );
		}
		if ( 'yearly' === $type ) {
			$settings['last_schedule_run']['yearly'] = wp_date( 'Y' );
		}

		update_option( 'dbbp_settings', $settings );
	}

	/**
	 * Compute next backup run timestamp.
	 *
	 * @param string $type Type.
	 * @return int
	 */
	public function get_next_run_timestamp( $type ) {
		$settings   = get_option( 'dbbp_settings', array() );
		$time_value = $settings['schedule'][ $type . '_time' ] ?? '02:00';
		$parts      = array_map( 'intval', explode( ':', $time_value ) );
		$hour       = $parts[0] ?? 2;
		$minute     = $parts[1] ?? 0;

		$now = time();
		if ( 'daily' === $type ) {
			$ts = strtotime( wp_date( 'Y-m-d' ) . sprintf( ' %02d:%02d:00', $hour, $minute ) );
			if ( $ts <= $now ) {
				$ts = strtotime( '+1 day', $ts );
			}
			return $ts;
		}

		if ( 'monthly' === $type ) {
			$ts = strtotime( wp_date( 'Y-m-01' ) . sprintf( ' %02d:%02d:00', $hour, $minute ) );
			if ( $ts <= $now ) {
				$ts = strtotime( 'first day of next month ' . sprintf( '%02d:%02d:00', $hour, $minute ) );
			}
			return $ts;
		}

		$year = (int) wp_date( 'Y' );
		$ts   = strtotime( $year . '-01-01 ' . sprintf( '%02d:%02d:00', $hour, $minute ) );
		if ( $ts <= $now ) {
			$ts = strtotime( ( $year + 1 ) . '-01-01 ' . sprintf( '%02d:%02d:00', $hour, $minute ) );
		}
		return $ts;
	}
}
