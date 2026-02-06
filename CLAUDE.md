# AutomateWoo CLI - Investigation Notes

This file contains investigation notes for future Claude sessions working on this plugin.

## Purpose

This plugin provides WP-CLI commands for running AutomateWoo manual workflows without the slow admin UI. It directly calls AutomateWoo's PHP classes to match items and create queue entries, bypassing REST API overhead.

## Key AutomateWoo Classes

### Workflow Loading
- `\AutomateWoo\Workflows\Factory::get($id)` - Load workflow by ID
- `$workflow->get_type()` - Returns 'manual' or 'automatic'
- `$workflow->get_trigger()` - Get trigger instance

### Manual Trigger Interface
- Location: `includes/Triggers/ManualInterface.php`
- Implementations: `OrderManual.php`, `SubscriptionManual.php`
- Key methods:
  - `get_primary_data_type()` - Returns 'order' or 'subscription'
  - `get_data_layer($item)` - Creates Data_Layer from item

### Quick Filter Queries
- `\AutomateWoo\RuleQuickFilters\QueryLoader::load($rule_data, $data_type)` - Creates query
- `$query->get_total_results_count()` - Total items to scan
- `$query->get_results_by_rule_group($group, $batch_size, $offset)` - Paginated results

### Workflow Validation
- `$workflow->setup($data_layer)` - Initialize workflow state
- `$workflow->validate_workflow()` - **Validates ALL rules** (handles AND/OR logic internally)
- `$workflow->cleanup()` - Clean up after validation

### Queue Entry Creation
- Location: `includes/Queued_Event.php`
- Key methods:
  ```php
  $queue = new \AutomateWoo\Queued_Event();
  $queue->set_workflow_id($workflow->get_id());
  $queue->set_date_due($datetime);
  $queue->save();
  $queue->store_data_layer($data_layer);
  ```

### Workflow Details
- `$workflow->get_rule_data()` - Array of rule groups
- `$workflow->get_actions()` - Array of Action objects
- `$workflow->get_timing_type()` - 'immediately', 'delayed', 'scheduled', etc.
- `$workflow->get_queue_date()` - DateTime for delayed execution
- `\AutomateWoo\Rules::get($rule_name)` - Get Rule object with `title` property
- `$action->get_title()` - Get action display title

## Reference Implementation

The admin UI's REST API implementation is in:
`includes/Rest_Api/Controllers/ManualWorkflowRunner.php`

Key methods:
- `get_quick_filter_data()` - Gets counts per rule group
- `find_matches()` - Finds matching items (lines 167-209)
- `add_items_to_queue()` - Creates queue entries (lines 218-260)

## Rule Groups

AutomateWoo supports multiple rule groups:
- Rules within a group have AND relationship
- Groups have OR relationship with each other
- Items can match multiple groups (need deduplication)

## UI Language Reference

From `admin/assets/src/manual-workflow-runner/`:
- "Searching for %s that match the rules" - find-items-step/item-finder.js:97
- "Run workflow for X orders/subscriptions" - find-items-step/item-finder.js:74
- "Adding X matching to the queue" - queue-step/index.js:135
- "X were successfully added to the queue" - queue-step/index.js:102

## Memory Management

For large datasets (22,000+ items):
```php
set_time_limit(0);
wp_raise_memory_limit('admin');

// Every 500 items:
wp_cache_flush();
gc_collect_cycles();
```

## Testing Commands

```bash
# List manual workflows
wp aw-cli list

# Get info about workflow
wp aw-cli info 123

# Run a workflow
wp aw-cli run 123

# Run with larger batch size
wp aw-cli run 123 --batch-size=1000

# Debug mode to see memory usage
wp aw-cli run 123 --debug
```

## Database Tables

- `wp_automatewoo_queue` - Queue entries
- `wp_automatewoo_queue_meta` - Queue metadata (data layer storage)
- Workflows stored as `post_type = 'aw_workflow'`
