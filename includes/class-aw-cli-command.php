<?php
/**
 * WP-CLI command for running AutomateWoo manual workflows.
 *
 * @package AutomateWoo_CLI
 */

namespace AutomateWoo_CLI;

use AutomateWoo\DateTime;
use AutomateWoo\Queued_Event;
use AutomateWoo\RuleQuickFilters\QueryLoader;
use AutomateWoo\Rules;
use AutomateWoo\Triggers\ManualInterface;
use AutomateWoo\Workflow;
use AutomateWoo\Workflows\Factory;
use WP_CLI;
use WP_CLI_Command;

defined( 'ABSPATH' ) || exit;

/**
 * Run AutomateWoo manual workflows from the command line.
 *
 * ## EXAMPLES
 *
 *     # Run manual workflow with ID 123
 *     wp aw-cli run 123
 *
 *     # List all manual workflows
 *     wp aw-cli list
 *
 *     # Get info about a workflow
 *     wp aw-cli info 123
 */
class AW_CLI_Command extends WP_CLI_Command {

	/**
	 * Default batch size for processing.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 500;

	/**
	 * Maximum allowed batch size.
	 *
	 * @var int
	 */
	const MAX_BATCH_SIZE = 10000;

	/**
	 * Interval for memory cleanup operations.
	 *
	 * @var int
	 */
	const MEMORY_CLEANUP_INTERVAL = 500;

	/**
	 * Cached HPOS detection result.
	 *
	 * @var bool|null
	 */
	private ?bool $is_using_hpos_cache = null;

	/**
	 * Run a manual workflow.
	 *
	 * Scans items (orders/subscriptions) matching the workflow rules,
	 * then queues them for execution.
	 *
	 * ## OPTIONS
	 *
	 * <workflow_id>
	 * : The ID of the manual workflow to run.
	 *
	 * [--batch-size=<num>]
	 * : Number of items to process per batch for memory management.
	 * ---
	 * default: 500
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp aw-cli run 123
	 *     wp aw-cli run 123 --batch-size=1000
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Workflow ID is required.' );
		}

		$workflow_id = (int) $args[0];
		if ( $workflow_id < 1 ) {
			WP_CLI::error( 'Workflow ID must be a positive integer.' );
		}

		$batch_size = (int) ( $assoc_args['batch-size'] ?? self::DEFAULT_BATCH_SIZE );
		if ( $batch_size < 1 || $batch_size > self::MAX_BATCH_SIZE ) {
			WP_CLI::error( sprintf( 'Batch size must be between 1 and %d.', self::MAX_BATCH_SIZE ) );
		}

		$workflow = Factory::get( $workflow_id );

		if ( ! $workflow || ! $workflow->exists ) {
			WP_CLI::error( "Workflow #{$workflow_id} not found." );
		}

		if ( 'manual' !== $workflow->get_type() ) {
			WP_CLI::error( "Workflow #{$workflow_id} is not a manual workflow. Only manual workflows can be run with this command." );
		}

		$trigger = $workflow->get_trigger();

		if ( ! $trigger instanceof ManualInterface ) {
			WP_CLI::error( "Workflow #{$workflow_id} does not have a valid manual trigger." );
		}

		$data_type      = $trigger->get_primary_data_type();
		$data_type_name = $this->get_data_type_name( $data_type );

		$this->display_workflow_header( $workflow );
		$this->display_workflow_details( $workflow, $trigger );

		$total_on_site = $this->get_total_items_count( $data_type );

		WP_CLI::log( str_repeat( "\u{2500}", 63 ) );
		WP_CLI::log( WP_CLI::colorize( "%BTotal {$data_type_name} on site:%n {$total_on_site}" ) );
		WP_CLI::log( str_repeat( "\u{2500}", 63 ) );
		WP_CLI::log( '' );

		if ( 0 === $total_on_site ) {
			WP_CLI::warning( "No {$data_type_name} found on this site." );
			return;
		}

		WP_CLI::confirm( "Do you want to match existing {$data_type_name} against this workflow?" );

		try {
			$quick_filter_query = QueryLoader::load( $workflow->get_rule_data(), $data_type );
			$total_to_scan      = $quick_filter_query->get_total_results_count();
		} catch ( \Exception $e ) {
			WP_CLI::error( 'Error loading quick filter query: ' . $e->getMessage() );
		}

		WP_CLI::log( '' );
		WP_CLI::log( "Searching for {$data_type_name} that match the rules..." );

		$matched_ids = $this->find_matches( $workflow, $trigger, $quick_filter_query, $batch_size, $total_to_scan );

		$match_count = count( $matched_ids );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( "%GFound {$match_count} matching {$data_type_name}.%n" ) );

		if ( 0 === $match_count ) {
			WP_CLI::warning( "No {$data_type_name} matched the workflow rules." );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::confirm( "Run workflow for {$match_count} {$data_type_name}?" );

		WP_CLI::log( '' );
		WP_CLI::log( "Adding {$match_count} {$data_type_name} to the queue..." );

		$result = $this->queue_items( $workflow, $trigger, $matched_ids );

		WP_CLI::log( '' );

		if ( $result['failed'] > 0 ) {
			WP_CLI::warning( "{$result['failed']} {$data_type_name} failed to queue." );
		}

		if ( $result['queued'] > 0 ) {
			WP_CLI::success( "{$result['queued']} {$data_type_name} were added to the queue." );
		} else {
			WP_CLI::error( 'No items were queued successfully.' );
		}
	}

	/**
	 * List all manual workflows.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp aw-cli list
	 *     wp aw-cli list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$workflows = get_posts(
			array(
				'post_type'      => 'aw_workflow',
				'post_status'    => array( 'publish', 'aw-disabled' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'type',
						'value' => 'manual',
					),
				),
			)
		);

		if ( empty( $workflows ) ) {
			WP_CLI::warning( 'No manual workflows found.' );
			return;
		}

		$items = array();
		foreach ( $workflows as $post ) {
			$workflow = Factory::get( $post->ID );
			if ( ! $workflow ) {
				continue;
			}

			$trigger   = $workflow->get_trigger();
			$data_type = $trigger instanceof ManualInterface ? $trigger->get_primary_data_type() : 'unknown';

			$status = 'publish' === $post->post_status ? 'Active' : 'Disabled';

			$items[] = array(
				'ID'        => $workflow->get_id(),
				'Title'     => $workflow->title,
				'Data Type' => ucfirst( $data_type ),
				'Status'    => $status,
			);
		}

		WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'Title', 'Data Type', 'Status' ) );
	}

	/**
	 * Get information about a workflow.
	 *
	 * ## OPTIONS
	 *
	 * <workflow_id>
	 * : The ID of the workflow to get info for.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aw-cli info 123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function info( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Workflow ID is required.' );
		}

		$workflow_id = (int) $args[0];
		if ( $workflow_id < 1 ) {
			WP_CLI::error( 'Workflow ID must be a positive integer.' );
		}

		$workflow = Factory::get( $workflow_id );

		if ( ! $workflow || ! $workflow->exists ) {
			WP_CLI::error( "Workflow #{$workflow_id} not found." );
		}

		$trigger = $workflow->get_trigger();

		$this->display_workflow_header( $workflow );
		$this->display_workflow_details( $workflow, $trigger );

		if ( 'manual' === $workflow->get_type() && $trigger instanceof ManualInterface ) {
			$data_type      = $trigger->get_primary_data_type();
			$data_type_name = $this->get_data_type_name( $data_type );
			$total_on_site  = $this->get_total_items_count( $data_type );

			WP_CLI::log( str_repeat( "\u{2500}", 63 ) );
			WP_CLI::log( WP_CLI::colorize( "%BTotal {$data_type_name} on site:%n {$total_on_site}" ) );
			WP_CLI::log( str_repeat( "\u{2500}", 63 ) );
		}
	}

	/**
	 * Get the plural display name for a data type.
	 *
	 * @param string $data_type The data type ('subscription' or 'order').
	 * @return string
	 */
	private function get_data_type_name( string $data_type ): string {
		$names = array(
			'subscription' => 'subscriptions',
			'order'        => 'orders',
		);

		return $names[ $data_type ] ?? $data_type . 's';
	}

	/**
	 * Display workflow header.
	 *
	 * @param Workflow $workflow The workflow.
	 */
	private function display_workflow_header( Workflow $workflow ): void {
		WP_CLI::log( '' );
		WP_CLI::log( str_repeat( "\u{2550}", 63 ) );
		WP_CLI::log( WP_CLI::colorize( "%YWORKFLOW:%n {$workflow->title} (#{$workflow->get_id()})" ) );
		WP_CLI::log( str_repeat( "\u{2550}", 63 ) );
		WP_CLI::log( '' );
	}

	/**
	 * Display workflow details (trigger, rules, actions, timing).
	 *
	 * @param Workflow                            $workflow The workflow.
	 * @param \AutomateWoo\Trigger|null           $trigger  The trigger.
	 */
	private function display_workflow_details( Workflow $workflow, ?\AutomateWoo\Trigger $trigger ): void {
		$trigger_title = $trigger ? $trigger->get_title() : 'Unknown';
		WP_CLI::log( WP_CLI::colorize( "%BTRIGGER:%n {$trigger_title}" ) );
		WP_CLI::log( '' );

		$rule_data = $workflow->get_rule_data();
		if ( ! empty( $rule_data ) ) {
			WP_CLI::log( WP_CLI::colorize( '%BRULES:%n' ) );
			foreach ( $rule_data as $group_index => $rules ) {
				if ( $group_index > 0 ) {
					WP_CLI::log( '  -- OR --' );
				}
				foreach ( $rules as $rule ) {
					$rule_display = $this->format_rule_display( $rule );
					WP_CLI::log( "  \u{2022} {$rule_display}" );
				}
			}
			WP_CLI::log( '' );
		} else {
			WP_CLI::log( WP_CLI::colorize( '%BRULES:%n (none)' ) );
			WP_CLI::log( '' );
		}

		$actions = $workflow->get_actions();
		if ( ! empty( $actions ) ) {
			WP_CLI::log( WP_CLI::colorize( '%BACTIONS:%n' ) );
			$action_num = 1;
			foreach ( $actions as $action ) {
				$action_title = $action->get_title( true );
				WP_CLI::log( '  ' . $action_num . ". {$action_title}" );
				++$action_num;
			}
			WP_CLI::log( '' );
		} else {
			WP_CLI::log( WP_CLI::colorize( '%BACTIONS:%n (none)' ) );
			WP_CLI::log( '' );
		}

		$timing_type    = $workflow->get_timing_type();
		$timing_display = $this->format_timing_display( $workflow, $timing_type );
		WP_CLI::log( WP_CLI::colorize( "%BTIMING:%n {$timing_display}" ) );
		WP_CLI::log( '' );
	}

	/**
	 * Format a rule for display.
	 *
	 * @param array<string, mixed> $rule The rule data.
	 * @return string
	 */
	private function format_rule_display( array $rule ): string {
		$rule_name = $rule['name'] ?? '';
		$compare   = $rule['compare'] ?? '';
		$value     = $rule['value'] ?? '';

		$rule_obj = Rules::get( $rule_name );
		$title    = $rule_obj ? $rule_obj->title : $rule_name;

		$value_display = $this->format_rule_value( $value, $compare );

		if ( empty( $value_display ) ) {
			return "{$title} {$compare}";
		}

		return "{$title} {$compare} {$value_display}";
	}

	/**
	 * Format a rule value for display.
	 *
	 * @param mixed  $value   The rule value.
	 * @param string $compare The comparison type.
	 * @return string
	 */
	private function format_rule_value( mixed $value, string $compare ): string {
		if ( ! is_array( $value ) ) {
			return (string) $value;
		}

		if ( isset( $value['from'] ) || isset( $value['to'] ) || isset( $value['date'] ) || isset( $value['timeframe'] ) ) {
			return $this->format_date_rule_value( $value, $compare );
		}

		if ( 2 === count( $value ) && isset( $value[0] ) && isset( $value[1] ) && is_string( $value[0] ) && 0 === strpos( $value[0], '_' ) ) {
			return "{$value[0]} = {$value[1]}";
		}

		return implode(
			', ',
			array_filter(
				$value,
				function ( $v ) {
					return '' !== $v && null !== $v;
				}
			)
		);
	}

	/**
	 * Format a date rule value for display.
	 *
	 * @param array<string, mixed> $value   The date rule value.
	 * @param string               $compare The comparison type.
	 * @return string
	 */
	private function format_date_rule_value( array $value, string $compare ): string {
		$from      = $value['from'] ?? '';
		$to        = $value['to'] ?? '';
		$date      = $value['date'] ?? '';
		$timeframe = $value['timeframe'] ?? '';
		$measure   = $value['measure'] ?? '';

		switch ( $compare ) {
			case 'is_between':
				if ( $from && $to ) {
					return "{$from} and {$to}";
				}
				break;

			case 'is_in_the_next':
			case 'is_not_in_the_next':
				if ( $timeframe && $measure ) {
					return "{$timeframe} {$measure}";
				}
				break;

			case 'is_before':
			case 'is_after':
			case 'is':
				if ( $date ) {
					return $date;
				}
				break;
		}

		$parts = array_filter(
			array( $date, $from, $to, $timeframe, $measure ),
			function ( $v ) {
				return '' !== $v && null !== $v;
			}
		);

		return implode( ' ', $parts );
	}

	/**
	 * Format timing for display.
	 *
	 * @param Workflow $workflow    The workflow.
	 * @param string   $timing_type The timing type.
	 * @return string
	 */
	private function format_timing_display( Workflow $workflow, string $timing_type ): string {
		switch ( $timing_type ) {
			case 'immediately':
				return 'Run immediately';
			case 'delayed':
				$delay = $workflow->get_timing_delay();
				$unit  = $workflow->get_timing_delay_unit();
				return "Delayed by {$delay} {$unit}";
			case 'scheduled':
				$time = $workflow->get_scheduled_time();
				$day  = $workflow->get_scheduled_day();
				return "Scheduled for {$time}" . ( $day ? " on {$day}" : '' );
			case 'fixed':
				return 'Fixed time';
			case 'datetime':
				return 'Specific date/time';
			default:
				return $timing_type;
		}
	}

	/**
	 * Find items matching the workflow rules.
	 *
	 * @param Workflow                                      $workflow           The workflow.
	 * @param ManualInterface                               $trigger            The trigger.
	 * @param \AutomateWoo\RuleQuickFilters\RuleQuickFilter $quick_filter_query The quick filter query.
	 * @param int                                           $batch_size         Batch size.
	 * @param int                                           $total_to_scan      Total items to scan.
	 * @return array<int> Array of matched item IDs.
	 */
	private function find_matches(
		Workflow $workflow,
		ManualInterface $trigger,
		\AutomateWoo\RuleQuickFilters\RuleQuickFilter $quick_filter_query,
		int $batch_size,
		int $total_to_scan
	): array {
		$matched_ids      = array();
		$processed        = 0;
		$rule_group_count = max( 1, count( $workflow->get_rule_data() ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning', $total_to_scan );

		for ( $group = 1; $group <= $rule_group_count; $group++ ) {
			$offset = 0;

			while ( true ) {
				try {
					$items = $quick_filter_query->get_results_by_rule_group( $group, $batch_size, $offset );
				} catch ( \Exception $e ) {
					WP_CLI::warning( "Error getting results for rule group {$group}: " . $e->getMessage() );
					break;
				}

				if ( empty( $items ) ) {
					break;
				}

				foreach ( $items as $item ) {
					$item_id = $item->get_id();

					if ( isset( $matched_ids[ $item_id ] ) ) {
						$progress->tick();
						++$processed;
						continue;
					}

					try {
						$data_layer = $trigger->get_data_layer( $item );
						$workflow->setup( $data_layer );

						if ( $workflow->validate_workflow() ) {
							$matched_ids[ $item_id ] = true;
						}
					} catch ( \Exception $e ) {
						WP_CLI::debug( "Error validating item #{$item_id}: " . $e->getMessage() );
					} finally {
						$workflow->cleanup();
					}

					$progress->tick();
					++$processed;
				}

				$offset += $batch_size;

				$this->maybe_free_memory( $processed );
			}
		}

		$progress->finish();

		return array_keys( $matched_ids );
	}

	/**
	 * Queue matched items for the workflow.
	 *
	 * @param Workflow        $workflow    The workflow.
	 * @param ManualInterface $trigger     The trigger.
	 * @param array<int>      $matched_ids Array of matched item IDs.
	 * @return array{queued: int, failed: int} Number of items queued and failed.
	 */
	private function queue_items( Workflow $workflow, ManualInterface $trigger, array $matched_ids ): array {
		$queued_count = 0;
		$failed_count = 0;
		$processed    = 0;
		$progress     = \WP_CLI\Utils\make_progress_bar( 'Queueing', count( $matched_ids ) );

		foreach ( $matched_ids as $item_id ) {
			try {
				$data_layer = $trigger->get_data_layer( $item_id );
				$workflow->setup( $data_layer );

				$queue = new Queued_Event();
				$queue->set_workflow_id( $workflow->get_id() );

				if ( 'immediately' === $workflow->get_timing_type() ) {
					$queue->set_date_due( new DateTime() );
				} else {
					$queue->set_date_due( $workflow->get_queue_date() );
				}

				$queue->save();

				if ( $data_layer ) {
					$queue->store_data_layer( $data_layer );
				}

				++$queued_count;
			} catch ( \Exception $e ) {
				++$failed_count;
				WP_CLI::warning( "Failed to queue item #{$item_id}: " . $e->getMessage() );
			} finally {
				$workflow->cleanup();
			}

			$progress->tick();
			++$processed;

			if ( 0 === $processed % self::MEMORY_CLEANUP_INTERVAL ) {
				$this->maybe_free_memory( $processed );
			}
		}

		$progress->finish();

		return array(
			'queued' => $queued_count,
			'failed' => $failed_count,
		);
	}

	/**
	 * Free memory periodically during long operations.
	 *
	 * @param int $processed Number of items processed.
	 */
	private function maybe_free_memory( int $processed ): void {
		if ( 0 !== $processed % self::MEMORY_CLEANUP_INTERVAL ) {
			return;
		}

		wp_cache_flush();

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		WP_CLI::debug(
			sprintf(
				'Memory freed at %d items. Current usage: %s',
				$processed,
				size_format( memory_get_usage( true ) )
			)
		);
	}

	/**
	 * Get total count of items on the site.
	 *
	 * @param string $data_type The data type ('subscription' or 'order').
	 * @return int
	 */
	private function get_total_items_count( string $data_type ): int {
		global $wpdb;

		$order_type = 'subscription' === $data_type ? 'shop_subscription' : 'shop_order';

		if ( $this->is_using_hpos() ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = %s",
					$order_type
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				$order_type
			)
		);
	}

	/**
	 * Check if WooCommerce is using HPOS (High Performance Order Storage).
	 *
	 * Uses WooCommerce's official API to check if custom orders table is enabled,
	 * with a fallback to table existence check for older WooCommerce versions.
	 *
	 * @return bool
	 */
	private function is_using_hpos(): bool {
		if ( null !== $this->is_using_hpos_cache ) {
			return $this->is_using_hpos_cache;
		}

		// Use WooCommerce's official API if available.
		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$this->is_using_hpos_cache = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			return $this->is_using_hpos_cache;
		}

		// Fallback for older WooCommerce versions: check if table exists and is being used.
		global $wpdb;

		$table_name   = $wpdb->prefix . 'wc_orders';
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		// Table existence alone doesn't mean HPOS is enabled, default to false for safety.
		$this->is_using_hpos_cache = false;

		WP_CLI::debug( 'HPOS detection: OrderUtil class not available, defaulting to posts table.' );

		return $this->is_using_hpos_cache;
	}
}
