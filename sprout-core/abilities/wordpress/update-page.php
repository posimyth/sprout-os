<?php

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('sprout/update-page', [
    'label' => __('Update Page', 'sprout-os'),
    'description' => __('Updates an existing WordPress page by page_id.', 'sprout-os'),
    'category' => 'sprout-content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'page_id' => [
                'type' => 'integer',
                'description' => 'Existing page ID',
                'minimum' => 1,
            ],
            'title' => [
                'type' => 'string',
                'description' => 'New page title',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'New page content (HTML allowed)',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Post status. Allowed: draft, publish, private',
                'enum' => ['draft', 'publish', 'private'],
            ],
        ],
        'required' => ['page_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'page_id' => ['type' => 'integer'],
            'edit_url' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
            'updated_fields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
        ],
    ],
    'execute_callback' => 'sprout_mcp_update_page_ability',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function sprout_mcp_update_page_ability(array $input)
{
    $page_id = isset($input['page_id']) ? (int) $input['page_id'] : 0;
    if ($page_id <= 0) {
        return new WP_Error('invalid_page_id', __('Valid page_id is required.', 'sprout-os'));
    }

    $post = get_post($page_id);
    if (!$post || $post->post_type !== 'page') {
        return new WP_Error('page_not_found', __('Page not found.', 'sprout-os'));
    }

    $update = [
        'ID' => $page_id,
    ];
    $updated_fields = [];

    if (array_key_exists('title', $input)) {
        $update['post_title'] = sanitize_text_field((string) $input['title']);
        $updated_fields[] = 'title';
    }

    if (array_key_exists('content', $input)) {
        $update['post_content'] = wp_kses_post((string) $input['content']);
        $updated_fields[] = 'content';
    }

    if (array_key_exists('status', $input)) {
        $status = sanitize_key((string) $input['status']);
        if (!in_array($status, ['draft', 'publish', 'private'], true)) {
            return new WP_Error('invalid_status', __('Invalid status. Use draft, publish, or private.', 'sprout-os'));
        }
        $update['post_status'] = $status;
        $updated_fields[] = 'status';
    }

    if ($updated_fields === []) {
        return new WP_Error('no_fields_to_update', __('Provide at least one field to update: title, content, or status.', 'sprout-os'));
    }

    $result = wp_update_post($update, true);
    if (is_wp_error($result)) {
        return $result;
    }

    return [
        'page_id' => $page_id,
        'edit_url' => (string) get_edit_post_link($page_id, 'raw'),
        'permalink' => (string) get_permalink($page_id),
        'updated_fields' => $updated_fields,
    ];
}
