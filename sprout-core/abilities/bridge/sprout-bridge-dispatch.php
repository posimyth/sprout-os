<?php
/**
 * SproutOS MCP - Ability Execution Bridge
 *
 * Routes an MCP tool-call to the matching WordPress ability,
 * performs permission checks, and wraps the result in a
 * standardised success/error envelope.
 *
 * @package  SproutOS_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gate: capability + ability existence + MCP visibility + ability-level permissions.
 *
 * @param  array<string,mixed>|null $args Incoming request arguments.
 * @return true|WP_Error
 */
function sprout_mcp_gate_ability_run( ?array $args ) {
    if ( ! current_user_can( 'read' ) ) {
        return new WP_Error( 'sprout_no_cap', __( 'You lack the required capability.', 'sprout-os' ) );
    }

    $identifier = trim( (string) ( $args['ability_name'] ?? '' ) );

    if ( $identifier === '' ) {
        return new WP_Error( 'sprout_blank_ability', __( 'ability_name is required.', 'sprout-os' ) );
    }

    $target = wp_get_ability( $identifier );
    if ( ! $target ) {
        return new WP_Error(
            'sprout_unresolved',
            /* translators: %s: ability name */
            sprintf( __( 'Ability "%s" does not exist.', 'sprout-os' ), $identifier )
        );
    }

    $flags = $target->get_meta();
    if ( empty( $flags['mcp']['public'] ) ) {
        return new WP_Error(
            'sprout_hidden',
            /* translators: %s: ability name */
            sprintf( __( '"%s" is not available via MCP.', 'sprout-os' ), $identifier )
        );
    }

    // Let the ability itself decide whether the current user may run it
    // with the supplied payload.
    $payload           = $args['parameters'] ?? null;
    $sanitised_payload = is_array( $payload ) && $payload !== [] ? $payload : null;
    $perm_result       = $target->check_permissions( $sanitised_payload );

    if ( is_wp_error( $perm_result ) ) {
        return $perm_result;
    }

    if ( $perm_result !== true ) {
        return new WP_Error(
            'sprout_forbidden_ability',
            /* translators: %s: ability name */
            sprintf( __( 'Permission denied for "%s".', 'sprout-os' ), $identifier )
        );
    }

    return true;
}

/**
 * Build structured error payload.
 *
 * @param string               $code
 * @param string               $message
 * @param array<string,mixed>  $details
 * @return array<string,mixed>
 */
function sprout_mcp_bridge_error_payload( string $code, string $message, array $details = [] ): array {
    $payload = [
        'code'    => $code,
        'message' => $message,
    ];

    if ( $details !== [] ) {
        $payload['details'] = $details;
    }

    return $payload;
}

/**
 * Dispatch the ability and wrap the outcome.
 *
 * @param  array<string,mixed>|null $args Incoming request arguments.
 * @return array<string,mixed>
 */
function sprout_mcp_dispatch_ability( ?array $args ): array {
    $started_at = microtime( true );
    $identifier        = trim( (string) ( $args['ability_name'] ?? '' ) );
    $payload           = $args['parameters'] ?? null;
    $sanitised_payload = is_array( $payload ) && $payload !== [] ? $payload : null;
    $request_id        = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'sprout_mcp_', true );

    $base = [
        'ok'             => false,
        'bridge'         => 'sprout_mcp_bridge_v2',
        'request_id'     => $request_id,
        'ability'        => $identifier,
        'started_at_gmt' => gmdate( 'c' ),
        'duration_ms'    => 0,
        'telemetry'      => [
            'payload_key_count' => is_array( $sanitised_payload ) ? count( $sanitised_payload ) : 0,
            'payload_keys'      => is_array( $sanitised_payload ) ? array_values( array_map( 'strval', array_keys( $sanitised_payload ) ) ) : [],
        ],
    ];

    $target = wp_get_ability( $identifier );
    if ( ! $target ) {
        $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $base['error']       = sprout_mcp_bridge_error_payload(
            'sprout_unresolved',
            /* translators: %s: ability name */
            sprintf( __( 'Unresolved ability: %s', 'sprout-os' ), $identifier )
        );
        return $base;
    }

    try {
        $outcome = $target->execute( $sanitised_payload );

        if ( is_wp_error( $outcome ) ) {
            $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
            $base['error']       = sprout_mcp_bridge_error_payload(
                $outcome->get_error_code() ?: 'sprout_execution_error',
                $outcome->get_error_message(),
                [ 'data' => $outcome->get_error_data() ]
            );
            return $base;
        }

        $base['ok']          = true;
        $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $base['result']      = $outcome;
        return $base;
    } catch ( \Throwable $fault ) {
        $base['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $base['error']       = sprout_mcp_bridge_error_payload(
            'sprout_exception',
            $fault->getMessage(),
            [ 'exception_class' => get_class( $fault ) ]
        );
        return $base;
    }
}

$sprout_dispatch_ability_config = [
    'label'       => __( 'Sprout Dispatch Tool', 'sprout-os' ),
    'description' => __( 'Executes a target WordPress tool using the Sprout Bridge dispatch contract.', 'sprout-os' ),
    'category'    => 'sprout-bridge',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'ability_name' => [
                'type'        => 'string',
                'description' => 'Fully-qualified ability identifier to dispatch.',
            ],
            'parameters' => [
                'type'                 => 'object',
                'description'          => 'Key-value arguments forwarded to the ability.',
                'additionalProperties' => true,
            ],
        ],
        'required'             => [ 'ability_name', 'parameters' ],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'ok'             => [ 'type' => 'boolean', 'description' => 'Whether the ability completed successfully.' ],
            'bridge'         => [ 'type' => 'string', 'description' => 'Bridge contract version identifier.' ],
            'request_id'     => [ 'type' => 'string', 'description' => 'Unique request identifier for traceability.' ],
            'ability'        => [ 'type' => 'string', 'description' => 'Ability identifier that was executed.' ],
            'started_at_gmt' => [ 'type' => 'string', 'description' => 'UTC start timestamp (ISO-8601).' ],
            'duration_ms'    => [ 'type' => 'integer', 'description' => 'Measured execution duration in milliseconds.' ],
            'result'         => [ 'description' => 'Payload returned by the ability on success.' ],
            'error'          => [
                'type'       => 'object',
                'properties' => [
                    'code'    => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                    'details' => [ 'type' => 'object', 'additionalProperties' => true ],
                ],
                'required' => [ 'code', 'message' ],
            ],
            'telemetry'      => [
                'type'       => 'object',
                'properties' => [
                    'payload_key_count' => [ 'type' => 'integer' ],
                    'payload_keys'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                ],
                'required' => [ 'payload_key_count', 'payload_keys' ],
            ],
        ],
        'required' => [ 'ok', 'bridge', 'request_id', 'ability', 'started_at_gmt', 'duration_ms', 'telemetry' ],
    ],

    'permission_callback' => 'sprout_mcp_gate_ability_run',
    'execute_callback'    => 'sprout_mcp_dispatch_ability',

    'meta' => [
        'mcp'         => [ 'public' => false ],
        'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
    ],
];

$sprout_dispatch_ability_ids = [
    'sprout-bridge/dispatch-tool',
    'mcp-adapter/execute-ability',
];

foreach ( $sprout_dispatch_ability_ids as $sprout_ability_id ) {
    if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $sprout_ability_id ) ) {
        continue;
    }
    wp_register_ability( $sprout_ability_id, $sprout_dispatch_ability_config );
}
