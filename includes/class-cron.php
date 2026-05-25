<?php
/**
 * WP Cron handler for scheduled GBP syncs.
 */
defined( 'ABSPATH' ) || exit;

class GBP_Cron {

	private static ?self $instance    = null;
	private const HOOK                = 'gbp_sync_cron_run';
	private const INTERVAL_OPTION     = 'gbp_sync_frequency';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks(): void {
		add_filter( 'cron_schedules',  [ $this, 'add_intervals' ] );
		add_action( self::HOOK,        [ $this, 'run_sync' ] );
		add_action( 'update_option_' . self::INTERVAL_OPTION, [ $this, 'reschedule' ] );
	}

	public function add_intervals( array $schedules ): array {
		$schedules['gbp_15min'] = [ 'interval' => 900,   'display' => 'Every 15 minutes' ];
		$schedules['gbp_30min'] = [ 'interval' => 1800,  'display' => 'Every 30 minutes' ];
		$schedules['gbp_1hr']   = [ 'interval' => 3600,  'display' => 'Every hour' ];
		$schedules['gbp_6hr']   = [ 'interval' => 21600, 'display' => 'Every 6 hours' ];
		$schedules['gbp_12hr']  = [ 'interval' => 43200, 'display' => 'Every 12 hours' ];
		return $schedules;
	}

	public function run_sync(): void {
		$manager = new GBP_Sync_Manager();
		$results = $manager->sync_all();
		error_log( 'GBP Sync cron: synced=' . $results['synced'] . ' created=' . $results['created'] . ' errors=' . count( $results['errors'] ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$interval = get_option( self::INTERVAL_OPTION, 'gbp_1hr' );
			wp_schedule_event( time(), $interval, self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public function reschedule(): void {
		self::unschedule();
		self::schedule();
	}

	public static function get_next_run(): string {
		$ts = wp_next_scheduled( self::HOOK );
		return $ts ? wp_date( 'F j, Y g:i a', $ts ) : 'Not scheduled';
	}
}
