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
	public function run( $args, $assoc_args ) {
		set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$workflow_id = (int) $args[0];
		$batch_size  = (int) ( $assoc_args['batch-size'] ?? self::DEFAULT_BATCH_SIZE );

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
		$data_type_name = $data_type === 'subscription' ? 'subscriptions' : 'orders';

		$this->display_workflow_header( $workflow );
		$this->display_workflow_details( $workflow, $trigger );

		$total_on_site = $this->get_total_items_count( $data_type );

		WP_CLI::log( str_repeat( "\u{2500}", 63 ) );
		WP_CLI::log( WP_CLI::colorize( "%BTotal {$data_type_name} on site:%n {$total_on_site}" ) );
		WP_CLI::log( str_repeat( "\u{2500}", 63 ) );
		WP_CLI::log( '' );

		if ( $total_on_site === 0 ) {
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

		if ( $match_count === 0 ) {
			WP_CLI::warning( "No {$data_type_name} matched the workflow rules." );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::confirm( "Run workflow for {$match_count} {$data_type_name}?" );

		WP_CLI::log( '' );
		WP_CLI::log( "Adding {$match_count} {$data_type_name} to the queue..." );

		$queued_count = $this->queue_items( $workflow, $trigger, $matched_ids );

		WP_CLI::log( '' );
		WP_CLI::success( "{$queued_count} {$data_type_name} were added to the queue." );
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
	public function list( $args, $assoc_args ) {
		$format = $assoc_args['format'] ?? 'table';

		$workflows = get_posts( [
			'post_type'      => 'aw_workflow',
			'post_status'    => [ 'publish', 'aw-disabled' ],
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => 'type',
					'value' => 'manual',
				],
			],
		] );

		if ( empty( $workflows ) ) {
			WP_CLI::warning( 'No manual workflows found.' );
			return;
		}

		$items = [];
		foreach ( $workflows as $post ) {
			$workflow = Factory::get( $post->ID );
			if ( ! $workflow ) {
				continue;
			}

			$trigger   = $workflow->get_trigger();
			$data_type = $trigger instanceof ManualInterface ? $trigger->get_primary_data_type() : 'unknown';

			$status = $post->post_status === 'publish' ? 'Active' : 'Disabled';

			$items[] = [
				'ID'        => $workflow->get_id(),
				'Title'     => $workflow->title,
				'Data Type' => ucfirst( $data_type ),
				'Status'    => $status,
			];
		}

		WP_CLI\Utils\format_items( $format, $items, [ 'ID', 'Title', 'Data Type', 'Status' ] );
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
	public function info( $args, $assoc_args ) {
		$workflow_id = (int) $args[0];
		$workflow    = Factory::get( $workflow_id );

		if ( ! $workflow || ! $workflow->exists ) {
			WP_CLI::error( "Workflow #{$workflow_id} not found." );
		}

		$trigger = $workflow->get_trigger();

		$this->display_workflow_header( $workflow );
		$this->display_workflow_details( $workflow, $trigger );

		if ( 'manual' === $workflow->get_type() && $trigger instanceof ManualInterface ) {
			$data_type      = $trigger->get_primary_data_type();
			$data_type_name = $data_type === 'subscription' ? 'subscriptions' : 'orders';
			$total_on_site  = $this->get_total_items_count( $data_type );

			WP_CLI::log( str_repeat( "\u{2500}", 63 ) );
			WP_CLI::log( WP_CLI::colorize( "%BTotal {$data_type_name} on site:%n {$total_on_site}" ) );
			WP_CLI::log( str_repeat( "\u{2500}", 63 ) );
		}
	}

	/**
	 * Display workflow header.
	 *
	 * @param Workflow $workflow The workflow.
	 */
	private function display_workflow_header( $workflow ) {
		WP_CLI::log( '' );
		WP_CLI::log( str_repeat( "\u{2550}", 63 ) );
		WP_CLI::log( WP_CLI::colorize( "%YWORKFLOW:%n {$workflow->title} (#{$workflow->get_id()})" ) );
		WP_CLI::log( str_repeat( "\u{2550}", 63 ) );
		WP_CLI::log( '' );
	}

	/**
	 * Display workflow details (trigger, rules, actions, timing).
	 *
	 * @param Workflow $workflow The workflow.
	 * @param mixed    $trigger  The trigger.
	 */
	private function display_workflow_details( $workflow, $trigger ) {
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
				$action_num++;
			}
			WP_CLI::log( '' );
		} else {
			WP_CLI::log( WP_CLI::colorize( '%BACTIONS:%n (none)' ) );
			WP_CLI::log( '' );
		}

		$timing_type = $workflow->get_timing_type();
		$timing_display = $this->format_timing_display( $workflow, $timing_type );
		WP_CLI::log( WP_CLI::colorize( "%BTIMING:%n {$timing_display}" ) );
		WP_CLI::log( '' );
	}

	/**
	 * Format a rule for display.
	 *
	 * @param array $rule The rule data.
	 * @return string
	 */
	private function format_rule_display( $rule ) {
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
	private function format_rule_value( $value, $compare ) {
		if ( ! is_array( $value ) ) {
			return (string) $value;
		}

		if ( isset( $value['from'] ) || isset( $value['to'] ) || isset( $value['date'] ) || isset( $value['timeframe'] ) ) {
			return $this->format_date_rule_value( $value, $compare );
		}

		if ( count( $value ) === 2 && isset( $value[0] ) && isset( $value[1] ) && is_string( $value[0] ) && strpos( $value[0], '_' ) === 0 ) {
			return "{$value[0]} = {$value[1]}";
		}

		return implode( ', ', array_filter( $value, function( $v ) {
			return $v !== '' && $v !== null;
		} ) );
	}

	/**
	 * Format a date rule value for display.
	 *
	 * @param array  $value   The date rule value.
	 * @param string $compare The comparison type.
	 * @return string
	 */
	private function format_date_rule_value( $value, $compare ) {
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

		$parts = array_filter( [ $date, $from, $to, $timeframe, $measure ], function( $v ) {
			return $v !== '' && $v !== null;
		} );

		return implode( ' ', $parts );
	}

	/**
	 * Format timing for display.
	 *
	 * @param Workflow $workflow    The workflow.
	 * @param string   $timing_type The timing type.
	 * @return string
	 */
	private function format_timing_display( $workflow, $timing_type ) {
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
	 * @param Workflow $workflow           The workflow.
	 * @param mixed    $trigger            The trigger.
	 * @param mixed    $quick_filter_query The quick filter query.
	 * @param int      $batch_size         Batch size.
	 * @param int      $total_to_scan      Total items to scan.
	 * @return array Array of matched item IDs.
	 */
	private function find_matches( $workflow, $trigger, $quick_filter_query, $batch_size, $total_to_scan ) {
		$matched_ids      = [];
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
						$processed++;
						continue;
					}

					try {
						$data_layer = $trigger->get_data_layer( $item );
						$workflow->setup( $data_layer );

						if ( $workflow->validate_workflow() ) {
							$matched_ids[ $item_id ] = true;
						}

						$workflow->cleanup();
					} catch ( \Exception $e ) {
						WP_CLI::debug( "Error validating item #{$item_id}: " . $e->getMessage() );
					}

					$progress->tick();
					$processed++;
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
	 * @param Workflow $workflow    The workflow.
	 * @param mixed    $trigger     The trigger.
	 * @param array    $matched_ids Array of matched item IDs.
	 * @return int Number of items queued.
	 */
	private function queue_items( $workflow, $trigger, $matched_ids ) {
		$queued_count = 0;
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

				$workflow->cleanup();
				$queued_count++;
			} catch ( \Exception $e ) {
				WP_CLI::debug( "Error queueing item #{$item_id}: " . $e->getMessage() );
			}

			$progress->tick();

			if ( $queued_count % 500 === 0 ) {
				$this->maybe_free_memory( $queued_count );
			}
		}

		$progress->finish();

		return $queued_count;
	}

	/**
	 * Free memory periodically during long operations.
	 *
	 * @param int $processed Number of items processed.
	 */
	private function maybe_free_memory( $processed ) {
		if ( $processed % 500 !== 0 ) {
			return;
		}

		wp_cache_flush();

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		WP_CLI::debug( sprintf(
			'Memory freed at %d items. Current usage: %s',
			$processed,
			size_format( memory_get_usage( true ) )
		) );
	}

	/**
	 * Get total count of items on the site.
	 *
	 * @param string $data_type The data type ('subscription' or 'order').
	 * @return int
	 */
	private function get_total_items_count( $data_type ) {
		global $wpdb;

		if ( $data_type === 'subscription' ) {
			if ( $this->is_using_hpos() ) {
				return (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_subscription'"
				);
			}
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
					'shop_subscription'
				)
			);
		}

		if ( $this->is_using_hpos() ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'"
			);
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				'shop_order'
			)
		);
	}

	/**
	 * Check if WooCommerce is using HPOS (High Performance Order Storage).
	 *
	 * @return bool
	 */
	private function is_using_hpos() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_orders';
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return ! empty( $table_exists );
	}
}
