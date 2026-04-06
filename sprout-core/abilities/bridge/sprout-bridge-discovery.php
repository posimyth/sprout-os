<?php
/**
 * SproutOS MCP - Ability Discovery Endpoint
 *
 * Provides MCP clients with a catalogue of all publicly available
 * WordPress abilities so they can build tool lists dynamically.
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
 * Build a normalized metadata block for a public ability.
 *
 * @param WP_Ability $ability
 * @return array<string,mixed>
 */
function sprout_mcp_build_public_ability_descriptor( WP_Ability $ability ): array {
    $meta        = $ability->get_meta();
    $annotations = (array) ( $meta['annotations'] ?? [] );

    return [
        'name'        => $ability->get_name(),
        'label'       => $ability->get_label(),
        'description' => $ability->get_description(),
        'category'    => (string) ( $meta['category'] ?? '' ),
        'readonly'    => (bool) ( $annotations['readonly'] ?? false ),
        'destructive' => (bool) ( $annotations['destructive'] ?? false ),
        'idempotent'  => (bool) ( $annotations['idempotent'] ?? false ),
        'tags'        => array_values( array_filter( array_map( 'strval', (array) ( $annotations['tags'] ?? [] ) ) ) ),
    ];
}

/**
 * Collect every registered ability that opts into MCP public exposure.
 *
 * @return array<string,mixed>
 */
function sprout_mcp_collect_public_abilities(): array {
    $catalogue      = [];
    $discovered_at  = gmdate( 'c' );
    $bridge_version = 'sprout_mcp_bridge_v2';

    foreach ( wp_get_abilities() as $registered ) {
        if ( ! ( $registered instanceof WP_Ability ) ) {
            continue;
        }

        $annotations = $registered->get_meta();
        $is_public   = ! empty( $annotations['mcp']['public'] );
        $mcp_kind    = $annotations['mcp']['type'] ?? 'tool';

        if ( ! $is_public || $mcp_kind !== 'tool' ) {
            continue;
        }

        $catalogue[] = sprout_mcp_build_public_ability_descriptor( $registered );
    }

    usort(
        $catalogue,
        static function ( array $left, array $right ): int {
            return strcmp( (string) $left['name'], (string) $right['name'] );
        }
    );

    return [
        'ok'               => true,
        'bridge'           => $bridge_version,
        'generated_at_gmt' => $discovered_at,
        'ability_count'    => count( $catalogue ),
        'abilities'        => $catalogue,
    ];
}

$sprout_discovery_ability_config = [
    'label'       => __( 'Sprout Discover Tools', 'sprout-os' ),
    'description' => __( 'Returns a Sprout Bridge catalogue of publicly exposed WordPress tools with capability metadata.', 'sprout-os' ),
    'category'    => 'sprout-bridge',

    'input_schema' => [
        'type'                 => 'object',
        'properties'           => new \stdClass(),
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'ok'               => [ 'type' => 'boolean', 'description' => 'Always true when discovery succeeds.' ],
            'bridge'           => [ 'type' => 'string', 'description' => 'Bridge contract version identifier.' ],
            'generated_at_gmt' => [ 'type' => 'string', 'description' => 'UTC timestamp when the response was generated (ISO-8601).' ],
            'ability_count'    => [ 'type' => 'integer', 'description' => 'Number of public MCP abilities exposed in this response.' ],
            'abilities' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => [ 'type' => 'string', 'description' => 'Fully-qualified ability identifier.' ],
                        'label'       => [ 'type' => 'string', 'description' => 'Human-readable title.' ],
                        'description' => [ 'type' => 'string', 'description' => 'What the ability does.' ],
                        'category'    => [ 'type' => 'string', 'description' => 'Ability category slug, when available.' ],
                        'readonly'    => [ 'type' => 'boolean', 'description' => 'Whether the ability is annotated as read-only.' ],
                        'destructive' => [ 'type' => 'boolean', 'description' => 'Whether the ability is annotated as destructive.' ],
                        'idempotent'  => [ 'type' => 'boolean', 'description' => 'Whether repeated calls are expected to produce stable side effects.' ],
                        'tags'        => [
                            'type'        => 'array',
                            'description' => 'Optional semantic tags provided by ability annotations.',
                            'items'       => [ 'type' => 'string' ],
                        ],
                    ],
                    'required' => [ 'name', 'label', 'description', 'category', 'readonly', 'destructive', 'idempotent', 'tags' ],
                ],
            ],
        ],
        'required' => [ 'ok', 'bridge', 'generated_at_gmt', 'ability_count', 'abilities' ],
    ],

    'permission_callback' => static fn (): bool => current_user_can( 'read' ),
    'execute_callback'    => 'sprout_mcp_collect_public_abilities',

    'meta' => [
        'mcp'         => [ 'public' => false ],
        'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
    ],
];

$sprout_discovery_ability_ids = [
    'sprout-bridge/discover-tools',
    'mcp-adapter/discover-abilities',
];

foreach ( $sprout_discovery_ability_ids as $sprout_ability_id ) {
    if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $sprout_ability_id ) ) {
        continue;
    }
    wp_register_ability( $sprout_ability_id, $sprout_discovery_ability_config );
}
