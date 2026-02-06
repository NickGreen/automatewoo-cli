# AutomateWoo CLI

Run AutomateWoo manual workflows from the command line.

This CLI tool bypasses the REST API and JavaScript overhead, processing items directly in PHP.

## Requirements

- WordPress with WP-CLI
- AutomateWoo plugin (active)

## Installation

1. Copy this plugin to `wp-content/plugins/automatewoo-cli/`
2. Activate: `wp plugin activate automatewoo-cli`

## Commands

### List manual workflows

```bash
wp aw-cli list
```

Output formats: `--format=table|json|csv|ids`

### Get workflow info

```bash
wp aw-cli info <workflow_id>
```

Shows trigger, rules, actions, timing, and estimated item count.

### Run a workflow

```bash
wp aw-cli run <workflow_id> [--batch-size=500]
```

This will:
1. Display workflow details (trigger, rules, actions, timing)
2. Show total items to scan
3. Ask for confirmation to start matching
4. Scan items with a progress bar
5. Show match count and ask for confirmation to queue
6. Queue matched items with a progress bar

## Example

```
$ wp aw-cli run 123

═══════════════════════════════════════════════════════════════
WORKFLOW: Update Subscription Pricing (#123)
═══════════════════════════════════════════════════════════════

TRIGGER: Manual - Subscriptions

RULES:
  • Subscription - Status is wc-active
  • Subscription has product: Premium Plan

ACTIONS:
  1. Change Subscription Line Item Pricing

TIMING: Run immediately

───────────────────────────────────────────────────────────────
Total subscriptions to scan: 22,458
───────────────────────────────────────────────────────────────

Do you want to match existing subscriptions against this workflow? [y/n] y

Searching for subscriptions that match the rules...
Scanning: 22458/22458 [============================] 100%

Found 9,847 matching subscriptions.

Run workflow for 9,847 subscriptions? [y/n] y

Adding 9,847 subscriptions to the queue...
Queueing: 9847/9847 [============================] 100%

Success: 9,847 subscriptions were added to the queue.
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--batch-size=N` | 500 | Items to process per batch (for memory management) |

## Memory Management

For large datasets, the CLI automatically:
- Disables PHP time limit
- Raises WordPress memory limit
- Clears object cache every 500 items
- Runs garbage collection periodically

Use `--debug` to see memory usage during processing.

## Troubleshooting

**Command not found**: Ensure AutomateWoo is active. The CLI only registers when AutomateWoo is loaded.

**No workflows listed**: The `list` command only shows manual workflows. Check that your workflows use the "Manual - Subscriptions" or "Manual - Orders" trigger.

**Memory issues**: Try reducing batch size: `--batch-size=100`
