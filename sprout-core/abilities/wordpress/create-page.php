<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('sprout/create-page', [
    'label' => __('Create Page', 'sprout-os'),
    'description' => __('Creates a WordPress page with title/content and optional status.', 'sprout-os'),
    'category' => 'sprout-content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => [
                'type' => 'string',
                'description' => 'Page title',
                'minLength' => 1,
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Page content (HTML allowed)',
                'default' => '',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Post status. Allowed: draft, publish',
                'enum' => ['draft', 'publish'],
                'default' => 'draft',
            ],
        ],
        'required' => ['title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'page_id' => ['type' => 'integer'],
            'edit_url' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'sprout_mcp_create_page_ability',
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
function sprout_mcp_create_page_ability(array $input)
{
    $title = isset($input['title']) ? trim(sanitize_text_field((string) $input['title'])) : '';
    if ($title === '') {
        return new WP_Error('invalid_title', __('Title is required.', 'sprout-os'));
    }

    $content = isset($input['content']) ? wp_kses_post((string) $input['content']) : '';
    $status = isset($input['status']) ? sanitize_key((string) $input['status']) : 'draft';
    if (!in_array($status, ['draft', 'publish'], true)) {
        $status = 'draft';
    }

    $page_id = wp_insert_post([
        'post_type' => 'page',
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => $status,
    ], true);

    if (is_wp_error($page_id)) {
        return $page_id;
    }

    return [
        'page_id' => (int) $page_id,
        'edit_url' => (string) get_edit_post_link((int) $page_id, 'raw'),
        'permalink' => (string) get_permalink((int) $page_id),
    ];
}
