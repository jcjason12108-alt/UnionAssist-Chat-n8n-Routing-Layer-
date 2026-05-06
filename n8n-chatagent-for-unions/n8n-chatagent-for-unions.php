<?php
/*
Plugin Name: n8n ChatAgent for Unions
Description: Branded AI chat widget for labor unions featuring webhook integrations and multi-agent support.
Version: 1.0.14
Author: Jason Cox
Plugin URI: https://github.com/jcjason12108-alt
Text Domain: n8n-chatagent-for-unions
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
License: GPL v2 or later
*/

if (!defined('ABSPATH')) exit;

// Define constants
define('N8N_UNION_AI_LIVE_CHAT_VERSION', '1.0.14');
define('N8N_UNION_AI_LIVE_CHAT_DIR', plugin_dir_path(__FILE__));
define('N8N_UNION_AI_LIVE_CHAT_URL', plugin_dir_url(__FILE__));
define('N8N_UNION_CHAT_ATTACHMENT_PROMPT_TOKEN', '[ATTACHMENT_READY]');

/**
 * Capability helpers so deployments can loosen privileges if needed.
 */
function n8n_union_chat_manage_capability() {
    return apply_filters('n8n_union_chat_manage_capability', 'manage_options');
}

function n8n_union_chat_current_user_can_manage() {
    return current_user_can(n8n_union_chat_manage_capability());
}

function n8n_union_chat_require_manage_capability() {
    if (!n8n_union_chat_current_user_can_manage()) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
}

function n8n_union_chat_get_current_agent_profile() {
    $current_user = wp_get_current_user();
    $agents = get_option('n8n_union_chat_agents', []);
    foreach ($agents as $agent) {
        if ($agent['email'] === $current_user->user_email && $agent['active']) {
            return $agent;
        }
    }
    return [
        'id' => (int) $current_user->ID,
        'name' => $current_user->display_name ?: $current_user->user_login,
        'email' => $current_user->user_email,
    ];
}

/**
 * Try to determine the IAM local identifier even if a dedicated option was never set.
 */
function n8n_union_chat_get_local_identifier() {
    $configured = get_option('n8n_union_chat_local_identifier', '');
    if (!empty($configured)) {
        return sanitize_text_field($configured);
    }

    $site_name = get_bloginfo('name');
    if ($site_name && preg_match('/\b(\d{2,5})\b/', $site_name, $matches)) {
        return $matches[1];
    }

    $site_url = home_url();
    if ($site_url && preg_match('/(\d{2,5})/', $site_url, $matches)) {
        return $matches[1];
    }

    return apply_filters('n8n_union_chat_local_identifier', '');
}

/**
 * Build an HMAC signed payload describing the logged-in user.
 */
function n8n_union_chat_build_session_payload($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }

    if (!$user || !$user->ID) {
        return null;
    }

    $ttl = (int) get_option('n8n_chatbot_token_ttl', 600);
    $ttl = $ttl > 0 ? $ttl : 600;
    $now = time();
    $exp = $now + max(60, $ttl);
    $roles = array_map('sanitize_text_field', (array) $user->roles);

    $payload = [
        'id'    => (int) $user->ID,
        'name'  => sanitize_text_field($user->display_name ?: $user->user_login),
        'email' => sanitize_email($user->user_email),
        'role'  => implode(',', $roles),
        'iat'   => $now,
        'exp'   => $exp,
    ];

    $json = wp_json_encode($payload);
    if (!$json) {
        return null;
    }

    $sig = hash_hmac('sha256', $json, wp_salt('auth'));

    return base64_encode($json . '.' . $sig);
}

/**
 * Frontend-friendly context describing the visitor (auth status, name, union token).
 */
function n8n_union_chat_get_union_user_context() {
    $user = wp_get_current_user();
    $is_authenticated = ($user && $user->ID);
    $roles = $is_authenticated ? array_map('sanitize_text_field', (array) $user->roles) : [];

    $context = [
        'authenticated' => (bool) $is_authenticated,
        'role' => $is_authenticated ? implode(', ', $roles) : 'Guest',
        'name' => $is_authenticated ? sanitize_text_field($user->display_name) : '',
        'email' => $is_authenticated ? sanitize_email($user->user_email) : '',
        'username' => $is_authenticated ? sanitize_text_field($user->user_login) : '',
        'token' => $is_authenticated ? n8n_union_chat_build_session_payload($user) : null,
        'local' => n8n_union_chat_get_local_identifier(),
    ];

    return apply_filters('n8n_union_chat_union_user_context', $context, $user);
}

/**
 * Default visitor metadata captured server-side as a safety net.
 */
function n8n_union_chat_build_default_visitor_meta() {
    $meta = [
        'ip' => n8n_union_get_visitor_ip(),
        'userAgent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown')),
        'referrer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '',
        'siteUrl' => home_url(),
        'timestamp' => time(),
    ];

    return apply_filters('n8n_union_chat_default_visitor_meta', $meta);
}

function n8n_union_chat_allowed_header_subtitle_tags() {
    $allowed = [
        'a' => [
            'href' => [],
            'target' => [],
            'rel' => [],
            'title' => [],
        ],
        'br' => [],
        'strong' => [],
        'em' => [],
        'span' => [
            'class' => [],
        ],
    ];

    return apply_filters('n8n_union_chat_header_subtitle_allowed_tags', $allowed);
}

function n8n_union_chat_option_is_enabled($value, $default = false) {
    if ($value === null) {
        return (bool) $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    if (is_numeric($value)) {
        return (int) $value === 1;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '1' || $normalized === 'true' || $normalized === 'yes' || $normalized === 'on') {
            return true;
        }
        if ($normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === '') {
            return false;
        }
    }
    return (bool) $value;
}

function n8n_union_chat_normalize_page_rule($rule) {
    $rule = is_string($rule) ? trim($rule) : '';
    if ($rule === '') {
        return '';
    }

    $parsed_path = wp_parse_url($rule, PHP_URL_PATH);
    if (is_string($parsed_path) && $parsed_path !== '') {
        $rule = $parsed_path;
    }

    $rule = trim($rule);
    if ($rule === '' || $rule === '/') {
        return '/';
    }

    return strtolower(trim($rule, " \t\n\r\0\x0B/"));
}

function n8n_union_chat_sanitize_hidden_pages($value) {
    $value = is_string($value) ? wp_unslash($value) : '';
    $lines = preg_split('/\r\n|\r|\n/', $value);
    $rules = [];

    foreach ($lines as $line) {
        $rule = n8n_union_chat_normalize_page_rule(sanitize_text_field($line));
        if ($rule !== '') {
            $rules[] = $rule;
        }
    }

    return implode("\n", array_values(array_unique($rules)));
}

function n8n_union_chat_sanitize_hidden_page_ids($value) {
    if (!is_array($value)) {
        return [];
    }

    $ids = array_map('absint', wp_unslash($value));
    $ids = array_filter($ids);

    return array_values(array_unique($ids));
}

function n8n_union_chat_default_routes() {
    return [
        [
            'value' => 'website',
            'label' => 'Website Help',
        ],
        [
            'value' => 'contract_policies',
            'label' => 'Contract & Policies',
        ],
    ];
}

function n8n_union_chat_sanitize_routes($value) {
    $value = is_string($value) ? wp_unslash($value) : '';
    $lines = preg_split('/\r\n|\r|\n/', $value);
    $routes = [];
    $used_values = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $line, 2));
        $label = sanitize_text_field($parts[0] ?? '');
        $route_value = sanitize_key($parts[1] ?? sanitize_title($label));

        if ($label === '' || $route_value === '' || isset($used_values[$route_value])) {
            continue;
        }

        $routes[] = [
            'value' => $route_value,
            'label' => $label,
        ];
        $used_values[$route_value] = true;
    }

    return !empty($routes) ? $routes : n8n_union_chat_default_routes();
}

function n8n_union_chat_get_routes() {
    $routes = get_option('n8n_union_chat_routes', null);
    if (is_array($routes) && !empty($routes)) {
        return n8n_union_chat_sanitize_routes(n8n_union_chat_routes_to_text($routes));
    }

    $legacy_website_value = sanitize_key(get_option('n8n_union_chat_route_website_value', 'website'));
    $legacy_website_label = sanitize_text_field(get_option('n8n_union_chat_route_website_label', 'Website Help'));
    $legacy_contract_value = sanitize_key(get_option('n8n_union_chat_route_contract_value', 'contract_policies'));
    $legacy_contract_label = sanitize_text_field(get_option('n8n_union_chat_route_contract_label', 'Contract & Policies'));

    return n8n_union_chat_sanitize_routes(
        $legacy_website_label . '|' . $legacy_website_value . "\n" .
        $legacy_contract_label . '|' . $legacy_contract_value
    );
}

function n8n_union_chat_routes_to_text($routes) {
    $lines = [];
    foreach ((array) $routes as $route) {
        if (!is_array($route)) {
            continue;
        }
        $label = sanitize_text_field($route['label'] ?? '');
        $route_value = sanitize_key($route['value'] ?? '');
        if ($label !== '' && $route_value !== '') {
            $lines[] = $label . '|' . $route_value;
        }
    }
    return implode("\n", $lines);
}

function n8n_union_chat_is_hidden_on_current_page() {
    $hidden_page_ids = array_map('absint', (array) get_option('n8n_union_chat_hidden_page_ids', []));
    if (!empty($hidden_page_ids) && is_page($hidden_page_ids)) {
        return true;
    }

    $hidden_pages = get_option('n8n_union_chat_hidden_pages', '');
    $rules = preg_split('/\r\n|\r|\n/', (string) $hidden_pages);
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $current_path = n8n_union_chat_normalize_page_rule($request_uri);
    $current_slug = ($current_path === '/') ? '/' : basename($current_path);

    foreach ($rules as $rule) {
        $rule = n8n_union_chat_normalize_page_rule($rule);
        if ($rule === '') {
            continue;
        }

        if ($rule === $current_path || $rule === $current_slug) {
            return true;
        }
    }

    return false;
}

function n8n_union_chat_create_visitor_token($visitor_id) {
    $user_id = get_current_user_id();
    return hash_hmac('sha256', $visitor_id . '|' . $user_id, wp_salt('nonce'));
}

function n8n_union_chat_get_or_create_visitor_id() {
    $cookie_name = 'n8n_union_chat_vid';
    $visitor_id = '';

    if (!empty($_COOKIE[$cookie_name])) {
        $visitor_id = sanitize_key(wp_unslash($_COOKIE[$cookie_name]));
    }

    if ($visitor_id === '') {
        $visitor_id = 'visitor_' . wp_generate_uuid4();
    }

    if (!headers_sent()) {
        $cookie_options = [
            'expires' => time() + MONTH_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
            $cookie_options['domain'] = COOKIE_DOMAIN;
        }
        setcookie($cookie_name, $visitor_id, $cookie_options);
    }

    return $visitor_id;
}

function n8n_union_chat_require_visitor_token($visitor_id) {
    if (n8n_union_chat_current_user_can_manage()) {
        return;
    }

    $token = isset($_POST['visitor_token']) ? sanitize_text_field(wp_unslash($_POST['visitor_token'])) : '';
    $expected = n8n_union_chat_create_visitor_token($visitor_id);

    if ($token === '' || !hash_equals($expected, $token)) {
        wp_send_json_error(['message' => 'Invalid visitor session'], 403);
    }
}

/**
 * Validate and sanitize attachments before forwarding them to n8n.
 */
function n8n_union_chat_prepare_attachments($attachments) {
    if (!is_array($attachments) || empty($attachments)) {
        return [];
    }

    $max_attachments = (int) apply_filters('n8n_union_chat_max_attachments', get_option('n8n_union_chat_max_attachments', 3));
    $max_attachments = $max_attachments > 0 ? $max_attachments : 3;

    $max_attachment_mb = (int) apply_filters('n8n_union_chat_max_attachment_mb', get_option('n8n_union_chat_max_attachment_mb', 5));
    $max_attachment_mb = $max_attachment_mb > 0 ? $max_attachment_mb : 5;
    $bytes_per_mb = defined('MB_IN_BYTES') ? MB_IN_BYTES : (1024 * 1024);
    $max_attachment_bytes = $max_attachment_mb * $bytes_per_mb;

    $allowed_extensions = apply_filters('n8n_union_chat_allowed_attachment_types', ['pdf', 'png', 'jpg', 'jpeg']);
    $allowed_extensions = array_map('strtolower', (array) $allowed_extensions);

    $sanitized = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }

        if ($max_attachments && count($sanitized) >= $max_attachments) {
            break;
        }

        $name = isset($attachment['name']) ? sanitize_file_name(wp_unslash($attachment['name'])) : '';
        if ($name === '') {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions, true)) {
            continue;
        }

        $size = isset($attachment['size']) ? (int) $attachment['size'] : 0;
        if ($size <= 0 || $size > $max_attachment_bytes) {
            continue;
        }

        $data = isset($attachment['data']) ? trim((string) $attachment['data']) : '';
        if ($data === '' || false === base64_decode($data, true)) {
            continue;
        }

        $type = isset($attachment['type']) ? sanitize_text_field($attachment['type']) : '';
        if ($type === '') {
            if ($ext === 'pdf') {
                $type = 'application/pdf';
            } else {
                $type = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
            }
        }

        $sanitized[] = [
            'name' => $name,
            'type' => $type,
            'size' => $size,
            'data' => $data,
        ];
    }

    return $sanitized;
}

// Main plugin initialization - delay everything until WordPress is fully loaded
function n8n_union_chat_initialize() {
    // WordPress automatically loads translations for plugins hosted on WordPress.org
    // No need for load_plugin_textdomain() since WordPress 4.6
    
    // Initialize frontend assets and AJAX handlers (always available)
    n8n_union_chat_init_frontend();
    n8n_union_chat_init_ajax();
}
add_action('wp_loaded', 'n8n_union_chat_initialize');


// Admin menu - register late to avoid early loading issues
add_action('admin_menu', function() {
    add_menu_page(
        'n8n ChatAgent for Unions',
        'n8n ChatAgent for Unions',
        'manage_options',
        'n8n-chatagent-for-unions',
        'n8n_union_chat_settings_page',
        'dashicons-format-chat',
        2
    );
    
    add_submenu_page(
        'n8n-chatagent-for-unions',
        'Settings',
        'Settings',
        'manage_options',
        'n8n-chatagent-for-unions',
        'n8n_union_chat_settings_page'
    );
    
    add_submenu_page(
        'n8n-chatagent-for-unions',
        'Colors & Styling',
        'Colors & Styling',
        'manage_options',
        'n8n-chatagent-for-unions-colors',
        'n8n_union_chat_colors_page'
    );
});

// Include admin pages
require_once plugin_dir_path(__FILE__) . 'admin/colors.php';

// Output custom CSS with color variables using proper WordPress enqueue - high priority
add_action('wp_enqueue_scripts', function() {
    $button_bg = get_option('n8n_union_button_bg_color', '#C8102E');
    $button_hover = get_option('n8n_union_button_hover_color', '#00205B');
    $chat_bubble_user = get_option('n8n_union_chat_bubble_user_color', '#0033A0');
    $header_gradient_start = get_option('n8n_union_header_gradient_start_color', '#0033A0');
    $header_gradient_end = get_option('n8n_union_header_gradient_end_color', '#C8102E');
    $chat_width = max(280, intval(get_option('n8n_union_chat_width', 370)));
    $chat_height_vh = max(30, intval(get_option('n8n_union_chat_height_vh', 60)));
    $chat_height_max = max(400, intval(get_option('n8n_union_chat_height_max', 720)));
    $launcher_size = max(40, intval(get_option('n8n_union_launcher_size', 64)));
    
    $custom_css = '
    /* n8n ChatAgent for Unions - Custom Colors & Layout */
    :root {
        --n8n-union-button-bg: ' . esc_attr($button_bg) . ';
        --n8n-union-button-hover: ' . esc_attr($button_hover) . ';
        --n8n-union-chat-bubble-user: ' . esc_attr($chat_bubble_user) . ';
        --n8n-union-light-font-color: #ffffff;
        --n8n-union-primary-color: ' . esc_attr($button_bg) . ';
        --n8n-union-primary-color-darker: ' . esc_attr($button_hover) . ';
        --n8n-union-header-gradient-start: ' . esc_attr($header_gradient_start) . ';
        --n8n-union-header-gradient-end: ' . esc_attr($header_gradient_end) . ';
        --n8n-union-chat-width: ' . esc_attr($chat_width) . 'px;
        --n8n-union-chat-height-vh: ' . esc_attr($chat_height_vh) . 'vh;
        --n8n-union-chat-height-max: ' . esc_attr($chat_height_max) . 'px;
        --n8n-union-launcher-size: ' . esc_attr($launcher_size) . 'px;
    }
    
    #n8n-union-chat-icon {
        background: ' . esc_attr($button_bg) . ' !important;
        border-color: ' . esc_attr($button_bg) . ' !important;
    }
    
    #n8n-union-chat-icon::after {
        background: ' . esc_attr($button_bg) . ' !important;
    }
    
    #n8n-union-chat-icon-container:hover #n8n-union-chat-icon::after {
        background: ' . esc_attr($button_hover) . ' !important;
    }
    
    #n8n-union-send-btn {
        background: ' . esc_attr($button_bg) . ' !important;
        background-color: ' . esc_attr($button_bg) . ' !important;
        border-color: ' . esc_attr($button_bg) . ' !important;
    }
    
    #n8n-union-send-btn:hover:not(:disabled) {
        background: ' . esc_attr($button_hover) . ' !important;
        background-color: ' . esc_attr($button_hover) . ' !important;
        border-color: ' . esc_attr($button_hover) . ' !important;
    }
    
    .n8n-union-user-message .text {
        background: ' . esc_attr($chat_bubble_user) . ' !important;
        background-color: ' . esc_attr($chat_bubble_user) . ' !important;
        border-color: ' . esc_attr($button_bg) . ' !important;
    }
    
    .quick-action-btn {
        border-color: ' . esc_attr($button_bg) . ' !important;
    }
    
    .quick-action-btn:hover {
        border-color: ' . esc_attr($button_hover) . ' !important;
    }
    
    #n8n-union-start-new-chat-btn {
        background: ' . esc_attr($button_bg) . ' !important;
        background-color: ' . esc_attr($button_bg) . ' !important;
    }
    
    #n8n-union-start-new-chat-btn:hover {
        background: ' . esc_attr($button_hover) . ' !important;
        background-color: ' . esc_attr($button_hover) . ' !important;
    }
    
    .speak-to-team-btn {
        background: ' . esc_attr($button_bg) . ' !important;
        background-color: ' . esc_attr($button_bg) . ' !important;
    }
    
    .speak-to-team-btn:hover {
        background: ' . esc_attr($button_hover) . ' !important;
        background-color: ' . esc_attr($button_hover) . ' !important;
    }
    
    .n8n-union-bot-message .text a {
        color: ' . esc_attr($button_bg) . ' !important;
        border-bottom-color: ' . esc_attr($button_bg) . ' !important;
    }
    
    #n8n-union-chat-input input:focus {
        border-color: ' . esc_attr($button_bg) . ' !important;
    }
    
    [style*="#F47F1F"], [style*="#ff9b26"], [style*="#ee4f27"], [style*="#C8102E"], [style*="#0033A0"] {
        background: ' . esc_attr($button_bg) . ' !important;
        background-color: ' . esc_attr($button_bg) . ' !important;
        border-color: ' . esc_attr($button_bg) . ' !important;
    }
    ';
    
    wp_add_inline_style('n8n-chatagent-for-unions-style', $custom_css);
}, 20);



// Enqueue admin assets for media library
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'n8n-chatagent-for-unions') !== false) {
        wp_enqueue_media();
        wp_enqueue_script('n8n-union-ai-admin-js', N8N_UNION_AI_LIVE_CHAT_URL . 'assets/admin.js', ['jquery'], N8N_UNION_AI_LIVE_CHAT_VERSION, true);
        wp_enqueue_style('n8n-union-ai-admin-css', N8N_UNION_AI_LIVE_CHAT_URL . 'assets/admin.css', [], N8N_UNION_AI_LIVE_CHAT_VERSION);
        
        // Localize script with nonces for AJAX calls
        wp_localize_script('n8n-union-ai-admin-js', 'n8nUnionAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('n8n_union_chat_admin'),
        ]);
    }
});

// Frontend initialization function
function n8n_union_chat_init_frontend() {
    // Enqueue frontend assets - delay option calls until needed
    add_action('wp_enqueue_scripts', function() {
        $visibility = get_option('n8n_union_chat_visibility', 'everyone');
        $allowed_visibilities = ['everyone', 'logged_in'];
        if (!in_array($visibility, $allowed_visibilities, true)) {
            $visibility = 'everyone';
        }
        if ($visibility === 'logged_in' && !is_user_logged_in()) {
            return;
        }
        if (n8n_union_chat_is_hidden_on_current_page()) {
            return;
        }
        // Debug: Log that we're enqueuing the assets

        
        wp_enqueue_style('n8n-chatagent-for-unions-style', N8N_UNION_AI_LIVE_CHAT_URL . 'assets/chat-widget.css', [], N8N_UNION_AI_LIVE_CHAT_VERSION);
        wp_enqueue_script('n8n-chatagent-for-unions-script', N8N_UNION_AI_LIVE_CHAT_URL . 'assets/chat-widget.js', ['jquery'], N8N_UNION_AI_LIVE_CHAT_VERSION, true);
        
        // Get bot icon settings (premium check)
        $bot_icon_type = get_option('n8n_union_chat_bot_icon_type', 'default');
        $bot_icon_url = '';
        $bot_icon_svg = '';
        $launcher_icon_type = get_option('n8n_union_chat_launcher_icon_type', 'default');
        $launcher_icon_url = '';
        $launcher_icon_svg = '';
        
        if ($bot_icon_type === 'custom') {
            $bot_icon_url = get_option('n8n_union_chat_bot_icon_custom', '');
        } elseif ($bot_icon_type !== 'default') {
            $bot_icon_svg = n8n_union_get_predefined_icon($bot_icon_type);
        }

        if ($launcher_icon_type === 'custom') {
            $launcher_icon_url = get_option('n8n_union_chat_launcher_icon_custom', '');
        } elseif ($launcher_icon_type !== 'default') {
            $launcher_icon_svg = n8n_union_get_predefined_icon($launcher_icon_type);
        }
        
        $webhook_url = get_option('n8n_union_chat_webhook', '');

        $max_attachments = (int) apply_filters('n8n_union_chat_max_attachments', get_option('n8n_union_chat_max_attachments', 3));
        $max_attachments = $max_attachments >= 0 ? $max_attachments : 3;

        $max_attachment_mb = (int) apply_filters('n8n_union_chat_max_attachment_mb', get_option('n8n_union_chat_max_attachment_mb', 5));
        $max_attachment_mb = $max_attachment_mb > 0 ? $max_attachment_mb : 5;

        $allowed_attachment_types = apply_filters('n8n_union_chat_allowed_attachment_types', ['pdf', 'png', 'jpg', 'jpeg']);
        $allowed_attachment_types = array_values(array_unique(array_map('strtolower', (array) $allowed_attachment_types)));
        $attachments_enabled_option = 1;
        $attachment_mode = get_option('n8n_union_chat_attachment_mode', 'always');
        $allowed_attachment_modes = ['always', 'agent', 'never'];
        if (!in_array($attachment_mode, $allowed_attachment_modes, true)) {
            $attachment_mode = 'always';
        }
        $attachment_prompt_token_option = get_option('n8n_union_chat_attachment_prompt_token', N8N_UNION_CHAT_ATTACHMENT_PROMPT_TOKEN);
        $attachment_prompt_token_option = is_string($attachment_prompt_token_option) ? trim($attachment_prompt_token_option) : '';
        $attachment_prompt_token_option = $attachment_prompt_token_option !== '' ? $attachment_prompt_token_option : N8N_UNION_CHAT_ATTACHMENT_PROMPT_TOKEN;
        $attachment_prompt_token = apply_filters('n8n_union_chat_attachment_prompt_token', $attachment_prompt_token_option);
        $ratings_enabled_frontend = n8n_union_chat_option_is_enabled(get_option('n8n_union_chat_enable_ratings', null), false);
        $rating_label_option_frontend = get_option('n8n_union_chat_rating_label', null);
        if ($rating_label_option_frontend === null) {
            $rating_label_frontend = 'Helpful?';
        } else {
            $rating_label_frontend = is_string($rating_label_option_frontend) ? $rating_label_option_frontend : '';
        }
        $rating_up_text_frontend = get_option('n8n_union_chat_rating_up_text', '👍');
        $rating_up_text_frontend = is_string($rating_up_text_frontend) && $rating_up_text_frontend !== '' ? $rating_up_text_frontend : '👍';
        $rating_down_text_frontend = get_option('n8n_union_chat_rating_down_text', '👎');
        $rating_down_text_frontend = is_string($rating_down_text_frontend) && $rating_down_text_frontend !== '' ? $rating_down_text_frontend : '👎';
        
        // Debug: Log the localization data
        
        
        $header_subtitle_option = get_option('n8n_union_chat_header_subtitle', 'We\'re here to help');
        $header_subtitle_processed = do_shortcode($header_subtitle_option);
        $header_subtitle_html = wp_kses($header_subtitle_processed, n8n_union_chat_allowed_header_subtitle_tags());
        $default_agent_id = sanitize_text_field(get_option('n8n_union_chat_default_agent_id', ''));

        $visitor_id = n8n_union_chat_get_or_create_visitor_id();

        // Prepare configuration array
        $allowed_disclaimer_tags = [
            'a' => [
                'href' => [],
                'target' => [],
                'rel' => [],
                'title' => [],
            ],
            'strong' => [],
            'em' => [],
            'br' => [],
            'span' => [
                'class' => [],
            ],
        ];

        $disclaimer_option = get_option('n8n_union_chat_disclaimer_text', 'AI Agent answers instantly\nFor best results, provide as much detail as possible');
        $disclaimer_processed = do_shortcode($disclaimer_option);
        $disclaimer_html = wp_kses($disclaimer_processed, $allowed_disclaimer_tags);
        $disclaimer_link_text_conf = get_option('n8n_union_chat_disclaimer_link_text', '');
        $disclaimer_link_url_conf = get_option('n8n_union_chat_disclaimer_link_url', '');
        if ($disclaimer_link_text_conf && $disclaimer_link_url_conf) {
            $auto_link = sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($disclaimer_link_url_conf), esc_html($disclaimer_link_text_conf));
            if (strpos($disclaimer_html, $auto_link) === false) {
                $disclaimer_html .= "\n" . $auto_link;
            }
        }

        $config = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => esc_attr(wp_create_nonce('n8n_union_chat_frontend')),
            'visitor_id' => $visitor_id,
            'visitor_token' => n8n_union_chat_create_visitor_token($visitor_id),
            'ai_name' => get_option('n8n_union_chat_ai_name', 'AI Assistant'),
            'header_title' => get_option('n8n_union_chat_header_title', 'Live Chat'),
            'header_subtitle' => $header_subtitle_html,
            'disclaimer_text' => $disclaimer_html,
            'show_disclaimer' => (bool) get_option('n8n_union_chat_show_disclaimer', 1),
            'webhookConfigured' => !empty($webhook_url),
            'quick_actions' => get_option('n8n_union_chat_quick_actions', ''),
            'greeting_message' => get_option('n8n_union_chat_greeting_message', 'Hi, how can we help?'),
            'show_greeting' => (bool) get_option('n8n_union_chat_show_greeting', 1),
            'bot_icon_type' => $bot_icon_type,
            'bot_icon_url' => $bot_icon_url,
            'bot_icon_svg' => $bot_icon_svg,
            'launcher_icon_type' => $launcher_icon_type,
            'launcher_icon_url' => $launcher_icon_url,
            'launcher_icon_svg' => $launcher_icon_svg,
            'custom_welcome_enabled' => (bool) get_option('n8n_union_chat_custom_welcome', 0),
            'custom_welcome_text' => get_option('n8n_union_chat_custom_welcome_text', ''),
            'union_user' => n8n_union_chat_get_union_user_context(),
            'site_local' => n8n_union_chat_get_local_identifier(),
            'attachments_enabled' => (int) $attachments_enabled_option,
            'max_attachments' => $max_attachments,
            'max_attachment_mb' => $max_attachment_mb,
            'allowed_attachment_types' => $allowed_attachment_types,
            'default_agent_id' => $default_agent_id,
            'attachments_mode' => $attachment_mode,
            'attachment_prompt_token' => $attachment_prompt_token,
            'ratings_enabled' => $ratings_enabled_frontend,
            'rating_label' => $rating_label_frontend,
            'rating_up_text' => $rating_up_text_frontend,
            'rating_down_text' => $rating_down_text_frontend,
            'route_selector_enabled' => (bool) get_option('n8n_union_chat_route_selector_enabled', 1),
            'route_prompt' => sanitize_text_field(get_option('n8n_union_chat_route_prompt', 'What can we help with?')),
            'routes' => n8n_union_chat_get_routes(),
        ];
        
        // Allow pro plugin to modify configuration
        $config = apply_filters('n8n_union_chat_config', $config);
        
        
        wp_localize_script('n8n-chatagent-for-unions-script', 'N8nUnionChat', $config);
    });
}

// Initialize AJAX handlers for visitors and admins
function n8n_union_chat_init_ajax() {
    // AJAX handlers for chat functionality - using proper plugin prefix
    add_action('wp_ajax_n8n_union_active_visitors', 'n8n_union_chat_get_visitors');
    add_action('wp_ajax_n8n_union_initiate_chat', 'n8n_union_chat_initiate_chat');
    add_action('wp_ajax_nopriv_n8n_union_track_visitor', 'n8n_union_chat_track_visitor');
    add_action('wp_ajax_n8n_union_track_visitor', 'n8n_union_chat_track_visitor');
    add_action('wp_ajax_n8n_union_agent_message', 'n8n_union_chat_send_agent_message');
    add_action('wp_ajax_n8n_union_chat_messages', 'n8n_union_chat_get_chat_messages');
    add_action('wp_ajax_nopriv_n8n_union_store_visitor_message', 'n8n_union_chat_store_visitor_message');
    add_action('wp_ajax_n8n_union_store_visitor_message', 'n8n_union_chat_store_visitor_message');
    add_action('wp_ajax_nopriv_n8n_union_store_bot_message', 'n8n_union_chat_store_bot_message');
    add_action('wp_ajax_n8n_union_store_bot_message', 'n8n_union_chat_store_bot_message');
    add_action('wp_ajax_nopriv_n8n_union_visitor_chat_status', 'n8n_union_chat_update_visitor_chat_status');
    add_action('wp_ajax_n8n_union_visitor_chat_status', 'n8n_union_chat_update_visitor_chat_status');
    add_action('wp_ajax_nopriv_n8n_union_visitor_status', 'n8n_union_chat_get_visitor_chat_status');
    add_action('wp_ajax_n8n_union_visitor_status', 'n8n_union_chat_get_visitor_chat_status');
    add_action('wp_ajax_nopriv_n8n_union_messages', 'n8n_union_chat_get_chat_messages');
    add_action('wp_ajax_n8n_union_debug_chat_history', 'n8n_union_chat_debug_chat_history');
    add_action('wp_ajax_nopriv_n8n_union_agent_availability', 'n8n_union_chat_check_agent_availability');
    add_action('wp_ajax_n8n_union_agent_availability', 'n8n_union_chat_check_agent_availability');
    add_action('wp_ajax_nopriv_n8n_union_proxy_message', 'n8n_union_chat_proxy_message');
    add_action('wp_ajax_n8n_union_proxy_message', 'n8n_union_chat_proxy_message');
    add_action('wp_ajax_nopriv_n8n_union_request_team_chat', 'n8n_union_chat_request_team_chat');
    add_action('wp_ajax_n8n_union_request_team_chat', 'n8n_union_chat_request_team_chat');
    add_action('wp_ajax_n8n_union_pending_requests', 'n8n_union_chat_get_pending_requests');
    add_action('wp_ajax_n8n_union_accept_chat_request', 'n8n_union_chat_accept_chat_request');
    add_action('wp_ajax_n8n_union_decline_chat_request', 'n8n_union_chat_decline_chat_request');
    add_action('wp_ajax_n8n_union_end_chat', 'n8n_union_chat_end_chat');
    add_action('wp_ajax_n8n_union_transfer_to_ai', 'n8n_union_chat_transfer_to_ai');
    add_action('wp_ajax_nopriv_n8n_union_end_visitor_chat', 'n8n_union_chat_end_visitor_chat');
    add_action('wp_ajax_nopriv_n8n_union_visitor_status_check', 'n8n_union_chat_check_visitor_status');
    add_action('wp_ajax_n8n_union_live_chat_visitors', 'n8n_union_chat_get_visitors');
    add_action('wp_ajax_nopriv_n8n_union_visitor_status_check', 'n8n_union_chat_check_visitor_status');
    add_action('wp_ajax_n8n_union_debug_ip_detection', 'n8n_union_chat_debug_ip_detection');
}

// Initialize AJAX handlers for dismissing notices
// Settings page callback
function n8n_union_chat_settings_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'n8n-chatagent-for-unions'));
    }
    
    if (isset($_POST['n8n_union_chat_webhook'])) {
        check_admin_referer('n8n_union_chat_save_settings');
        
        // Save settings with proper validation and unslashing
        if (isset($_POST['n8n_union_chat_webhook'])) {
            $webhook_value = esc_url_raw(wp_unslash($_POST['n8n_union_chat_webhook']));
            $webhook_parts = wp_parse_url($webhook_value);
            if (!$webhook_parts || empty($webhook_parts['scheme']) || strtolower($webhook_parts['scheme']) !== 'https') {
                $webhook_value = '';
            }
            update_option('n8n_union_chat_webhook', $webhook_value);
        }
        if (isset($_POST['n8n_union_chat_ai_name'])) {
            update_option('n8n_union_chat_ai_name', sanitize_text_field(wp_unslash($_POST['n8n_union_chat_ai_name'])));
        }
        if (isset($_POST['n8n_union_chat_default_agent_id'])) {
            update_option('n8n_union_chat_default_agent_id', sanitize_text_field(wp_unslash($_POST['n8n_union_chat_default_agent_id'])));
        }
        update_option('n8n_union_chat_route_selector_enabled', isset($_POST['n8n_union_chat_route_selector_enabled']) ? 1 : 0);
        if (isset($_POST['n8n_union_chat_route_prompt'])) {
            $route_prompt = sanitize_text_field(wp_unslash($_POST['n8n_union_chat_route_prompt']));
            update_option('n8n_union_chat_route_prompt', $route_prompt !== '' ? $route_prompt : 'What can we help with?');
        }
        if (isset($_POST['n8n_union_chat_routes_text'])) {
            update_option('n8n_union_chat_routes', n8n_union_chat_sanitize_routes($_POST['n8n_union_chat_routes_text']));
        }
        $visibility_value = 'everyone';
        if (isset($_POST['n8n_union_chat_visibility'])) {
            $visibility_value = sanitize_text_field(wp_unslash($_POST['n8n_union_chat_visibility']));
        }
        $allowed_visibility_values = ['everyone', 'logged_in'];
        if (!in_array($visibility_value, $allowed_visibility_values, true)) {
            $visibility_value = 'everyone';
        }
        update_option('n8n_union_chat_visibility', $visibility_value);
        $hidden_pages_value = isset($_POST['n8n_union_chat_hidden_pages'])
            ? n8n_union_chat_sanitize_hidden_pages($_POST['n8n_union_chat_hidden_pages'])
            : '';
        update_option('n8n_union_chat_hidden_pages', $hidden_pages_value);
        $hidden_page_ids_value = isset($_POST['n8n_union_chat_hidden_page_ids'])
            ? n8n_union_chat_sanitize_hidden_page_ids($_POST['n8n_union_chat_hidden_page_ids'])
            : [];
        update_option('n8n_union_chat_hidden_page_ids', $hidden_page_ids_value);
        if (isset($_POST['n8n_union_chat_header_title'])) {
            update_option('n8n_union_chat_header_title', sanitize_text_field(wp_unslash($_POST['n8n_union_chat_header_title'])));
        }
        if (isset($_POST['n8n_union_chat_header_subtitle'])) {
            $subtitle_raw = wp_kses_decode_entities(wp_unslash($_POST['n8n_union_chat_header_subtitle']));
            $existing_link_text = get_option('n8n_union_chat_header_link_text', '');
            $existing_link_url = get_option('n8n_union_chat_header_link_url', '');
            if ($existing_link_text && $existing_link_url) {
                $existing_link_html = sprintf(' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($existing_link_url), esc_html($existing_link_text));
                $subtitle_raw = str_replace($existing_link_html, '', $subtitle_raw);
            }
            $subtitle_clean = wp_kses($subtitle_raw, n8n_union_chat_allowed_header_subtitle_tags());
            $header_link_text_value = '';
            $header_link_url_value = '';
            if (!empty($_POST['n8n_union_chat_header_link_text'])) {
                $header_link_text_value = sanitize_text_field(wp_unslash($_POST['n8n_union_chat_header_link_text']));
            }
            if (!empty($_POST['n8n_union_chat_header_link_url'])) {
                $header_link_url_value = esc_url_raw(wp_unslash($_POST['n8n_union_chat_header_link_url']));
            }
            if ($header_link_text_value && $header_link_url_value) {
                $auto_link = sprintf(' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($header_link_url_value), esc_html($header_link_text_value));
                $subtitle_clean .= $auto_link;
            }
            update_option('n8n_union_chat_header_link_text', $header_link_text_value);
            update_option('n8n_union_chat_header_link_url', $header_link_url_value);
            update_option('n8n_union_chat_header_subtitle', $subtitle_clean);
        }
        if (isset($_POST['n8n_union_chat_token_ttl'])) {
            $ttl = max(60, min(86400, absint($_POST['n8n_union_chat_token_ttl'])));
            update_option('n8n_chatbot_token_ttl', $ttl);
        }
        if (isset($_POST['n8n_union_chat_webhook_timeout'])) {
            $timeout = max(20, min(600, absint($_POST['n8n_union_chat_webhook_timeout'])));
            update_option('n8n_union_chat_webhook_timeout', $timeout);
        }
        if (isset($_POST['n8n_union_chat_disclaimer_text'])) {
            $disclaimer_raw = wp_kses_decode_entities(wp_unslash($_POST['n8n_union_chat_disclaimer_text']));
            $existing_disclaimer_link_text = get_option('n8n_union_chat_disclaimer_link_text', '');
            $existing_disclaimer_link_url = get_option('n8n_union_chat_disclaimer_link_url', '');
            if ($existing_disclaimer_link_text && $existing_disclaimer_link_url) {
                $existing_disclaimer_link_html = sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($existing_disclaimer_link_url), esc_html($existing_disclaimer_link_text));
                $existing_disclaimer_link_html = wp_kses_decode_entities($existing_disclaimer_link_html);
                $link_variants = [
                    $existing_disclaimer_link_html,
                    "\n" . $existing_disclaimer_link_html,
                    "\r\n" . $existing_disclaimer_link_html,
                    "<br>" . $existing_disclaimer_link_html,
                    "<br />" . $existing_disclaimer_link_html,
                ];
                $disclaimer_raw = str_replace($link_variants, '', $disclaimer_raw);
                $disclaimer_raw = trim($disclaimer_raw);
            }
            $allowed_tags = [
                'a' => [
                    'href' => [],
                    'target' => [],
                    'rel' => [],
                    'title' => [],
                ],
                'strong' => [],
                'em' => [],
                'br' => [],
                'span' => [
                    'class' => [],
                ],
            ];
            $disclaimer_link_text_value = '';
            $disclaimer_link_url_value = '';
            if (!empty($_POST['n8n_union_chat_disclaimer_link_text'])) {
                $disclaimer_link_text_value = sanitize_text_field(wp_unslash($_POST['n8n_union_chat_disclaimer_link_text']));
            }
            if (!empty($_POST['n8n_union_chat_disclaimer_link_url'])) {
                $disclaimer_link_url_value = esc_url_raw(wp_unslash($_POST['n8n_union_chat_disclaimer_link_url']));
            }
            update_option('n8n_union_chat_disclaimer_link_text', $disclaimer_link_text_value);
            update_option('n8n_union_chat_disclaimer_link_url', $disclaimer_link_url_value);
            $disclaimer_html = wp_kses($disclaimer_raw, $allowed_tags);
            $disclaimer_html = trim($disclaimer_html);
            update_option('n8n_union_chat_disclaimer_text', $disclaimer_html);
        }
        // Quick actions currently load from saved defaults or custom filters
        if (isset($_POST['n8n_union_chat_greeting_message'])) {
            update_option('n8n_union_chat_greeting_message', sanitize_text_field(wp_unslash($_POST['n8n_union_chat_greeting_message'])));
        }
        if (isset($_POST['n8n_union_chat_bot_icon_type'])) {
            update_option('n8n_union_chat_bot_icon_type', sanitize_text_field(wp_unslash($_POST['n8n_union_chat_bot_icon_type'])));
        }
        if (isset($_POST['n8n_union_chat_bot_icon_custom'])) {
            update_option('n8n_union_chat_bot_icon_custom', esc_url_raw(wp_unslash($_POST['n8n_union_chat_bot_icon_custom'])));
        }
        if (isset($_POST['n8n_union_chat_launcher_icon_type'])) {
            update_option('n8n_union_chat_launcher_icon_type', sanitize_text_field(wp_unslash($_POST['n8n_union_chat_launcher_icon_type'])));
        }
        if (isset($_POST['n8n_union_chat_launcher_icon_custom'])) {
            update_option('n8n_union_chat_launcher_icon_custom', esc_url_raw(wp_unslash($_POST['n8n_union_chat_launcher_icon_custom'])));
        }
        if (isset($_POST['n8n_union_chat_custom_welcome'])) {
            update_option('n8n_union_chat_custom_welcome', 1);
        } else {
            update_option('n8n_union_chat_custom_welcome', 0);
        }
        if (isset($_POST['n8n_union_chat_custom_welcome_text'])) {
            update_option('n8n_union_chat_custom_welcome_text', sanitize_textarea_field(wp_unslash($_POST['n8n_union_chat_custom_welcome_text'])));
        }
        if (isset($_POST['n8n_union_chat_show_disclaimer'])) {
            update_option('n8n_union_chat_show_disclaimer', 1);
        } else {
            update_option('n8n_union_chat_show_disclaimer', 0);
        }
        if (isset($_POST['n8n_union_chat_show_greeting'])) {
            update_option('n8n_union_chat_show_greeting', 1);
        } else {
            update_option('n8n_union_chat_show_greeting', 0);
        }
        $attachment_mode_value = 'always';
        if (isset($_POST['n8n_union_chat_attachment_mode'])) {
            $attachment_mode_value = sanitize_text_field(wp_unslash($_POST['n8n_union_chat_attachment_mode']));
        }
        $allowed_attachment_modes = ['always', 'agent', 'never'];
        if (!in_array($attachment_mode_value, $allowed_attachment_modes, true)) {
            $attachment_mode_value = 'always';
        }
        update_option('n8n_union_chat_attachment_mode', $attachment_mode_value);
        $attachment_prompt_value = null;
        if (isset($_POST['n8n_union_chat_attachment_prompt_token'])) {
            $attachment_prompt_value = sanitize_text_field(wp_unslash($_POST['n8n_union_chat_attachment_prompt_token']));
        }
        $attachment_prompt_value = is_string($attachment_prompt_value) ? trim($attachment_prompt_value) : '';
        if ($attachment_prompt_value === '') {
            $attachment_prompt_value = N8N_UNION_CHAT_ATTACHMENT_PROMPT_TOKEN;
        }
        update_option('n8n_union_chat_attachment_prompt_token', $attachment_prompt_value);
        if (isset($_POST['n8n_union_chat_enable_ratings'])) {
            update_option('n8n_union_chat_enable_ratings', 1);
        } else {
            update_option('n8n_union_chat_enable_ratings', 0);
        }
        $rating_label_value = isset($_POST['n8n_union_chat_rating_label'])
            ? sanitize_text_field(wp_unslash($_POST['n8n_union_chat_rating_label']))
            : '';
        update_option('n8n_union_chat_rating_label', $rating_label_value);
        $rating_up_value = isset($_POST['n8n_union_chat_rating_up_text']) ? sanitize_text_field(wp_unslash($_POST['n8n_union_chat_rating_up_text'])) : '';
        if ($rating_up_value === '') {
            $rating_up_value = '👍';
        }
        update_option('n8n_union_chat_rating_up_text', $rating_up_value);
        $rating_down_value = isset($_POST['n8n_union_chat_rating_down_text']) ? sanitize_text_field(wp_unslash($_POST['n8n_union_chat_rating_down_text'])) : '';
        if ($rating_down_value === '') {
            $rating_down_value = '👎';
        }
        update_option('n8n_union_chat_rating_down_text', $rating_down_value);
        if (isset($_POST['n8n_union_enable_ip_lookup'])) {
            update_option('n8n_union_enable_ip_lookup', 1);
        } else {
            update_option('n8n_union_enable_ip_lookup', 0);
        }
        

        

        
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    

    $webhook = esc_attr(get_option('n8n_union_chat_webhook', ''));
    $webhook_timeout = absint(get_option('n8n_union_chat_webhook_timeout', 120));
    $token_ttl = absint(get_option('n8n_chatbot_token_ttl', 600));
    if ($token_ttl < 60) {
        $token_ttl = 600;
    }
    $ai_name = esc_attr(get_option('n8n_union_chat_ai_name', 'AI Assistant'));
    $default_agent_id_option = sanitize_text_field(get_option('n8n_union_chat_default_agent_id', ''));
    $default_agent_id = $default_agent_id_option !== '' ? $default_agent_id_option : sanitize_title($ai_name ?: 'union-agent');
    $route_selector_enabled = (bool) get_option('n8n_union_chat_route_selector_enabled', 1);
    $route_prompt = sanitize_text_field(get_option('n8n_union_chat_route_prompt', 'What can we help with?'));
    $routes_text = esc_textarea(n8n_union_chat_routes_to_text(n8n_union_chat_get_routes()));
    $header_title = esc_attr(get_option('n8n_union_chat_header_title', 'Live Chat'));
    $header_subtitle = get_option('n8n_union_chat_header_subtitle', 'We\'re here to help');
    $header_link_text = get_option('n8n_union_chat_header_link_text', '');
    $header_link_url = get_option('n8n_union_chat_header_link_url', '');
    $disclaimer_option = get_option('n8n_union_chat_disclaimer_text', 'AI Agent answers instantly\nFor best results, provide as much detail as possible');
    $disclaimer_text = esc_textarea(wp_kses_decode_entities(wp_unslash($disclaimer_option)));
    $disclaimer_link_text = get_option('n8n_union_chat_disclaimer_link_text', '');
    $disclaimer_link_url = get_option('n8n_union_chat_disclaimer_link_url', '');
    $show_disclaimer = (bool) get_option('n8n_union_chat_show_disclaimer', 1);
    $quick_actions = esc_textarea(wp_unslash(get_option('n8n_union_chat_quick_actions', 'Order update\nBook Meeting')));
    $greeting_message = esc_attr(get_option('n8n_union_chat_greeting_message', 'Hi, how can we help?'));
    $show_greeting = (bool) get_option('n8n_union_chat_show_greeting', 1);
    $launcher_icon_type = esc_attr(get_option('n8n_union_chat_launcher_icon_type', 'default'));
    $launcher_icon_custom = esc_attr(get_option('n8n_union_chat_launcher_icon_custom', ''));
    $custom_welcome_enabled = (bool) get_option('n8n_union_chat_custom_welcome', 0);
    $custom_welcome_text = esc_textarea(wp_unslash(get_option('n8n_union_chat_custom_welcome_text', '')));
    $bot_icon_type = esc_attr(get_option('n8n_union_chat_bot_icon_type', 'default'));
    $bot_icon_custom = esc_attr(get_option('n8n_union_chat_bot_icon_custom', ''));
    $enable_ip_lookup = (bool) get_option('n8n_union_enable_ip_lookup', 0);
    $attachment_mode = get_option('n8n_union_chat_attachment_mode', 'always');
    $allowed_attachment_modes = ['always', 'agent', 'never'];
    if (!in_array($attachment_mode, $allowed_attachment_modes, true)) {
        $attachment_mode = 'always';
    }
    $attachment_prompt_token_admin = get_option('n8n_union_chat_attachment_prompt_token', N8N_UNION_CHAT_ATTACHMENT_PROMPT_TOKEN);
    $attachment_prompt_token_admin = is_string($attachment_prompt_token_admin) ? trim($attachment_prompt_token_admin) : '';
    if ($attachment_prompt_token_admin === '') {
        $attachment_prompt_token_admin = N8N_UNION_CHAT_ATTACHMENT_PROMPT_TOKEN;
    }
    $ratings_enabled = n8n_union_chat_option_is_enabled(get_option('n8n_union_chat_enable_ratings', null), false);
    $rating_label_option = get_option('n8n_union_chat_rating_label', null);
    if ($rating_label_option === null) {
        $rating_label = 'Helpful?';
    } else {
        $rating_label = sanitize_text_field($rating_label_option);
    }
    $rating_up_text = get_option('n8n_union_chat_rating_up_text', '👍');
    $rating_down_text = get_option('n8n_union_chat_rating_down_text', '👎');
    $chat_visibility = get_option('n8n_union_chat_visibility', 'everyone');
    $allowed_visibility_values = ['everyone', 'logged_in'];
    if (!in_array($chat_visibility, $allowed_visibility_values, true)) {
        $chat_visibility = 'everyone';
    }
    $hidden_pages = get_option('n8n_union_chat_hidden_pages', '');
    $hidden_page_ids = array_map('absint', (array) get_option('n8n_union_chat_hidden_page_ids', []));
    $site_pages = get_pages([
        'sort_column' => 'post_title',
        'sort_order' => 'ASC',
        'post_status' => ['publish', 'private'],
    ]);
    
    // Additional unused settings removed for the simplified release
    
    // Get plugin data for version info
    if (!function_exists('get_plugin_data')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    $plugin_data = get_plugin_data(__FILE__);
    
    ?>
    <div class="wrap">
        <h1>n8n ChatAgent for Unions Settings</h1>
        
        <!-- Plugin Version Info -->
        <div class="n8n_union-plugin-info">
            <h3>Plugin Information</h3>
            <p><strong>Version:</strong> <?php echo esc_html(N8N_UNION_AI_LIVE_CHAT_VERSION); ?> | <strong>Author:</strong> <?php echo esc_html($plugin_data['Author']); ?></p>
        </div>
        
        <form method="post">
            <?php wp_nonce_field('n8n_union_chat_save_settings'); ?>
            

            
            <!-- Main Settings Section -->
        <h2>Chat Settings</h2>
        <div class="n8n_union-settings-grid">
            <section class="n8n_union-settings-card">
                <h3>Automation &amp; Routing</h3>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_webhook">Webhook Endpoint</label>
                    <input type="url" id="n8n_union_chat_webhook" name="n8n_union_chat_webhook" value="<?php echo esc_attr($webhook); ?>" class="regular-text" required>
                    <p class="description">The webhook URL for your AI chat service.</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_webhook_timeout">Webhook Timeout (seconds)</label>
                    <input type="number" id="n8n_union_chat_webhook_timeout" name="n8n_union_chat_webhook_timeout" min="20" max="600" value="<?php echo esc_attr($webhook_timeout); ?>" class="small-text">
                    <p class="description">How long WordPress waits for the automation to respond before showing an error. Default: 120 seconds.</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_default_agent_id">Default Agent Identifier</label>
                    <input type="text" id="n8n_union_chat_default_agent_id" name="n8n_union_chat_default_agent_id" value="<?php echo esc_attr($default_agent_id); ?>" class="regular-text" placeholder="union-ai-agent">
                    <p class="description">Optional ID sent as <code>agentId</code> in every payload when a human agent is not attached. Useful for routing inside n8n.</p>
                </div>
                <div class="n8n_union-field n8n_union-field-checkbox">
                    <label for="n8n_union_chat_route_selector_enabled">
                        <input type="checkbox" id="n8n_union_chat_route_selector_enabled" name="n8n_union_chat_route_selector_enabled" value="1" <?php checked($route_selector_enabled, true); ?>>
                        Show starter questions inside the chat
                    </label>
                    <p class="description">When a visitor clicks a starter question, the text is sent as their first message and the matching route is sent to n8n as <code>route</code>, <code>routeLabel</code>, and <code>metadata.selectedRoute</code>.</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_route_prompt">Starter Question Prompt</label>
                    <input type="text" id="n8n_union_chat_route_prompt" name="n8n_union_chat_route_prompt" value="<?php echo esc_attr($route_prompt); ?>" class="regular-text" placeholder="What can we help with?">
                    <p class="description">Short text displayed above the starter questions in the chat window.</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_routes_text">Starter Questions</label>
                    <textarea id="n8n_union_chat_routes_text" name="n8n_union_chat_routes_text" rows="5" class="large-text" placeholder="Website Help|website&#10;Contract Questions|contract_questions&#10;Carrier Policies|carrier_policies"><?php echo $routes_text; ?></textarea>
                    <p class="description">Enter one starter per line as <code>Question text|route_key</code>. The question text is what visitors click and send. The route key is what n8n should use in Switch/IF nodes.</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_token_ttl">Union Token TTL (seconds)</label>
                    <input type="number" id="n8n_union_chat_token_ttl" name="n8n_union_chat_token_ttl" min="60" max="86400" value="<?php echo esc_attr($token_ttl); ?>" class="small-text">
                    <p class="description">Controls how long signed union tokens remain valid. Longer values reduce re-authentication but keep tokens active longer. Default: 600 seconds.</p>
                </div>
                <div class="n8n_union-field n8n_union-field-checkbox">
                    <label for="n8n_union_enable_ip_lookup">
                        <input type="checkbox" id="n8n_union_enable_ip_lookup" name="n8n_union_enable_ip_lookup" value="1" <?php checked($enable_ip_lookup, true); ?>>
                        Enrich visitor data with city/country lookups (uses ip-api.com)
                    </label>
                    <p class="description">When enabled the plugin sends visitor IPs to ip-api.com to approximate location. Leave unchecked to avoid sending IPs to third parties.</p>
                </div>
            </section>

            <section class="n8n_union-settings-card">
                <h3>Assistant Identity &amp; Visibility</h3>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_ai_name">AI Assistant Name</label>
                    <input type="text" id="n8n_union_chat_ai_name" name="n8n_union_chat_ai_name" value="<?php echo esc_attr($ai_name); ?>" class="regular-text" placeholder="AI Assistant">
                    <p class="description">The name displayed for your AI assistant in the chat widget. Default: "AI Assistant"</p>
                </div>
                <div class="n8n_union-field">
                    <span class="n8n_union-field-label">Visibility</span>
                    <fieldset class="n8n_union-radio-group">
                        <label>
                            <input type="radio" name="n8n_union_chat_visibility" value="everyone" <?php checked($chat_visibility, 'everyone'); ?>>
                            Show to everyone (default)
                        </label>
                        <label>
                            <input type="radio" name="n8n_union_chat_visibility" value="logged_in" <?php checked($chat_visibility, 'logged_in'); ?>>
                            Only show when the visitor is logged in
                        </label>
                    </fieldset>
                    <p class="description">Hide the chat widget for anonymous visitors if you only want authenticated members using it.</p>
                </div>
                <div class="n8n_union-field">
                    <span class="n8n_union-field-label">Hide On Selected Pages</span>
                    <?php if (!empty($site_pages)): ?>
                        <div class="n8n_union-checkbox-list" style="max-height: 220px; overflow: auto; border: 1px solid #dcdcde; padding: 10px; background: #fff;">
                            <?php foreach ($site_pages as $page): ?>
                                <label style="display:block; margin:0 0 8px;">
                                    <input type="checkbox" name="n8n_union_chat_hidden_page_ids[]" value="<?php echo esc_attr($page->ID); ?>" <?php checked(in_array((int) $page->ID, $hidden_page_ids, true)); ?>>
                                    <?php echo esc_html(get_the_title($page)); ?>
                                    <code><?php echo esc_html(get_page_uri($page)); ?></code>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">Checked pages will not load the chat widget. These selections use WordPress page IDs, so they keep working if a page slug changes.</p>
                    <?php else: ?>
                        <p class="description">No published or private pages were found.</p>
                    <?php endif; ?>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_hidden_pages">Additional URLs, Paths, Or Slugs</label>
                    <textarea id="n8n_union_chat_hidden_pages" name="n8n_union_chat_hidden_pages" rows="4" class="large-text" placeholder="/contact&#10;/privacy-policy&#10;members-only"><?php echo esc_textarea($hidden_pages); ?></textarea>
                    <p class="description">Optional. Enter one custom rule per line for non-page routes. Query strings are ignored, so <code>/contact</code> also hides the agent on <code>/contact?source=email</code>.</p>
                </div>
            </section>

            <section class="n8n_union-settings-card">
                <h3>Header Content</h3>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_header_title">Chat Header Title</label>
                    <input type="text" id="n8n_union_chat_header_title" name="n8n_union_chat_header_title" value="<?php echo esc_attr($header_title); ?>" class="regular-text" placeholder="Live Chat">
                    <p class="description">The title displayed at the top of the chat window. Default: "Live Chat"</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_header_subtitle">Chat Header Subtitle</label>
                    <textarea id="n8n_union_chat_header_subtitle" name="n8n_union_chat_header_subtitle" rows="2" class="large-text" placeholder="We're here to help"><?php echo esc_textarea($header_subtitle); ?></textarea>
                    <p class="description">Displayed underneath the header title. Supports basic HTML links (e.g. &lt;a href=&quot;https://example.com&quot;&gt;Need help?&lt;/a&gt;). Default: "We're here to help".</p>
                    <div class="n8n_union-link-helper">
                        <label>
                            Link Text:
                            <input type="text" name="n8n_union_chat_header_link_text" value="<?php echo esc_attr($header_link_text); ?>" class="regular-text" placeholder="Need help?">
                        </label>
                        <label>
                            Link URL:
                            <input type="url" name="n8n_union_chat_header_link_url" value="<?php echo esc_attr($header_link_url); ?>" class="regular-text" placeholder="https://example.com/help">
                        </label>
                    </div>
                    <p class="description">Fill these fields to automatically append a link to the subtitle. Leave blank to skip.</p>
                </div>
            </section>

            <section class="n8n_union-settings-card">
                <h3>Disclaimer &amp; Notices</h3>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_disclaimer_text">Chat Disclaimer Text</label>
                    <textarea id="n8n_union_chat_disclaimer_text" name="n8n_union_chat_disclaimer_text" rows="3" class="large-text" placeholder="AI Agent answers instantly&#10;For best results, provide as much detail as possible"><?php echo esc_textarea($disclaimer_text); ?></textarea>
                    <p class="description">The disclaimer text displayed at the bottom of the chat window. Use line breaks for multiple lines. Supports basic HTML links (e.g. &lt;a href=&quot;https://example.com&quot;&gt;Need help?&lt;/a&gt;).</p>
                    <div class="n8n_union-link-helper">
                        <label>
                            Link Text:
                            <input type="text" name="n8n_union_chat_disclaimer_link_text" value="<?php echo esc_attr($disclaimer_link_text); ?>" class="regular-text" placeholder="Read disclaimer">
                        </label>
                        <label>
                            Link URL:
                            <input type="url" name="n8n_union_chat_disclaimer_link_url" value="<?php echo esc_attr($disclaimer_link_url); ?>" class="regular-text" placeholder="https://example.com/disclaimer">
                        </label>
                    </div>
                    <p class="description">Add an optional link line underneath the disclaimer. Leave blank to skip.</p>
                </div>
                <div class="n8n_union-field n8n_union-field-checkbox">
                    <label for="n8n_union_chat_show_disclaimer">
                        <input type="checkbox" id="n8n_union_chat_show_disclaimer" name="n8n_union_chat_show_disclaimer" value="1" <?php checked($show_disclaimer, true); ?>>
                        Show the disclaimer card inside the chat window
                    </label>
                </div>
            </section>

            <section class="n8n_union-settings-card">
                <h3>Attachments &amp; Files</h3>
                <div class="n8n_union-field">
                    <span class="n8n_union-field-label">Attachment Button Mode</span>
                    <fieldset class="n8n_union-radio-group">
                        <label>
                            <input type="radio" name="n8n_union_chat_attachment_mode" value="never" <?php checked($attachment_mode, 'never'); ?>>
                            Never show — hide the uploader completely.
                        </label>
                        <label>
                            <input type="radio" name="n8n_union_chat_attachment_mode" value="always" <?php checked($attachment_mode, 'always'); ?>>
                            Always show — keep the uploader visible regardless of prompts.
                        </label>
                        <label>
                            <input type="radio" name="n8n_union_chat_attachment_mode" value="agent" <?php checked($attachment_mode, 'agent'); ?>>
                            Agent-controlled — only show the uploader when the AI reply contains <code><?php echo esc_html($attachment_prompt_token_admin); ?></code>.
                        </label>
                    </fieldset>
                    <p class="description">Add the phrase above (for example: "Please upload your file <?php echo esc_html($attachment_prompt_token_admin); ?>") inside your workflow response whenever uploads are required.</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_attachment_prompt_token">Prompt keyword</label>
                    <input type="text" id="n8n_union_chat_attachment_prompt_token" name="n8n_union_chat_attachment_prompt_token" value="<?php echo esc_attr($attachment_prompt_token_admin); ?>" class="regular-text" placeholder="<?php echo esc_attr(N8N_UNION_CHAT_ATTACHMENT_PROMPT_TOKEN); ?>">
                    <p class="description">Customize the trigger phrase if your workflow already emits a different keyword. Matching is case-insensitive and the token is automatically stripped from what visitors see.</p>
                </div>
            </section>

            <section class="n8n_union-settings-card">
                <h3>Feedback &amp; Ratings</h3>
                <div class="n8n_union-field n8n_union-field-checkbox">
                    <label>
                        <input type="checkbox" name="n8n_union_chat_enable_ratings" value="1" <?php checked($ratings_enabled, true); ?>>
                        Enable thumbs up / thumbs down controls on bot replies
                    </label>
                    <p class="description">When enabled, each AI response shows a small prompt so visitors can flag good or bad answers. Ratings are forwarded to your webhook as a <code>rateMessage</code> action.</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_rating_label">Prompt label</label>
                    <input type="text" id="n8n_union_chat_rating_label" name="n8n_union_chat_rating_label" value="<?php echo esc_attr($rating_label); ?>" class="regular-text" placeholder="Helpful?">
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_rating_up_text">Thumbs up text/icon</label>
                    <input type="text" id="n8n_union_chat_rating_up_text" name="n8n_union_chat_rating_up_text" value="<?php echo esc_attr($rating_up_text); ?>" class="regular-text" placeholder="👍">
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_rating_down_text">Thumbs down text/icon</label>
                    <input type="text" id="n8n_union_chat_rating_down_text" name="n8n_union_chat_rating_down_text" value="<?php echo esc_attr($rating_down_text); ?>" class="regular-text" placeholder="👎">
                    <p class="description">Use emojis or short labels (e.g. “Yes/No”) to match your branding.</p>
                </div>
            </section>

            <section class="n8n_union-settings-card">
                <h3>Greeting &amp; Welcome</h3>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_greeting_message">Greeting Message</label>
                    <input type="text" id="n8n_union_chat_greeting_message" name="n8n_union_chat_greeting_message" value="<?php echo esc_attr($greeting_message); ?>" class="regular-text" placeholder="Hi, how can we help?">
                    <p class="description">The greeting bubble text that appears next to the chat launcher. Default: "Hi, how can we help?"</p>
                    <label class="n8n_union-inline-checkbox" for="n8n_union_chat_show_greeting">
                        <input type="checkbox" id="n8n_union_chat_show_greeting" name="n8n_union_chat_show_greeting" value="1" <?php checked($show_greeting, true); ?>>
                        Show the greeting bubble next to the launcher
                    </label>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_custom_welcome_text">Initial Bot Message</label>
                    <label class="n8n_union-inline-checkbox" for="n8n_union_chat_custom_welcome">
                        <input type="checkbox" id="n8n_union_chat_custom_welcome" name="n8n_union_chat_custom_welcome" value="1" <?php checked($custom_welcome_enabled, true); ?>>
                        Show this message instead of calling the webhook automatically.
                    </label>
                    <textarea id="n8n_union_chat_custom_welcome_text" name="n8n_union_chat_custom_welcome_text" rows="3" class="large-text" placeholder="Welcome message shown inside the chat window."><?php echo esc_textarea($custom_welcome_text); ?></textarea>
                    <p class="description">Useful when you want to greet visitors without spending tokens. The webhook is only called after the visitor sends a real message.</p>
                </div>
            </section>

            <section class="n8n_union-settings-card n8n_union-settings-card--full">
                <h3>Branding &amp; Icons</h3>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_bot_icon_type">Bot Icon</label>
                    <select id="n8n_union_chat_bot_icon_type" name="n8n_union_chat_bot_icon_type">
                        <option value="default" <?php selected($bot_icon_type, 'default'); ?>>Default Icon</option>
                        <option value="robot" <?php selected($bot_icon_type, 'robot'); ?>>Robot</option>
                        <option value="chat" <?php selected($bot_icon_type, 'chat'); ?>>Chat Bubble</option>
                        <option value="assistant" <?php selected($bot_icon_type, 'assistant'); ?>>Assistant</option>
                        <option value="support" <?php selected($bot_icon_type, 'support'); ?>>Support</option>
                        <option value="custom" <?php selected($bot_icon_type, 'custom'); ?>>Custom Image</option>
                    </select>
                    <p class="description">Choose an icon style for your AI bot or upload a custom image.</p>
                </div>
                <div id="custom_icon_row" class="n8n_union-field n8n_union-custom-icon-row <?php echo esc_attr($bot_icon_type === 'custom' ? 'n8n_union-show' : 'n8n_union-hide'); ?>">
                    <label for="n8n_union_chat_bot_icon_custom">Custom Bot Icon</label>
                    <div class="n8n_union-upload-field">
                        <input type="url" id="n8n_union_chat_bot_icon_custom" name="n8n_union_chat_bot_icon_custom" value="<?php echo esc_attr($bot_icon_custom); ?>" class="regular-text" placeholder="https://example.com/icon.png">
                        <div class="n8n_union-upload-actions">
                            <button type="button" class="button" id="upload_bot_icon_button"><?php echo esc_html($bot_icon_custom ? 'Change Icon' : 'Upload Icon'); ?></button>
                            <button type="button" class="button n8n_union-remove-icon-btn <?php echo esc_attr($bot_icon_custom ? 'n8n_union-show' : 'n8n_union-hide'); ?>" id="remove_bot_icon_button">Remove Icon</button>
                        </div>
                    </div>
                    <div id="bot_icon_preview" class="n8n_union-bot-icon-preview-container">
                        <?php if ($bot_icon_custom): ?>
                            <img src="<?php echo esc_url($bot_icon_custom); ?>" class="n8n_union-bot-icon-preview" alt="<?php echo esc_attr('Custom Bot Icon'); ?>">
                        <?php endif; ?>
                    </div>
                    <p class="description">Upload or paste a custom icon URL for your AI bot. Recommended size: 50x50 pixels. Supported formats: PNG, JPG, SVG.</p>
                </div>
                <div class="n8n_union-field">
                    <span class="n8n_union-field-label">Icon Preview</span>
                    <div id="icon_preview_container" class="n8n_union-icon-preview-container">
                        <div id="icon_preview" class="n8n_union-icon-preview"></div>
                    </div>
                    <p class="description">Preview of how your bot icon will appear in the chat widget.</p>
                </div>
                <div class="n8n_union-field">
                    <label for="n8n_union_chat_launcher_icon_type">Launcher Icon</label>
                    <select id="n8n_union_chat_launcher_icon_type" name="n8n_union_chat_launcher_icon_type">
                        <option value="default" <?php selected($launcher_icon_type, 'default'); ?>>Default Icon</option>
                        <option value="robot" <?php selected($launcher_icon_type, 'robot'); ?>>Robot</option>
                        <option value="chat" <?php selected($launcher_icon_type, 'chat'); ?>>Chat Bubble</option>
                        <option value="assistant" <?php selected($launcher_icon_type, 'assistant'); ?>>Assistant</option>
                        <option value="support" <?php selected($launcher_icon_type, 'support'); ?>>Support</option>
                        <option value="custom" <?php selected($launcher_icon_type, 'custom'); ?>>Custom Image</option>
                    </select>
                    <p class="description">Choose the icon used on the floating launcher button.</p>
                </div>
                <div id="launcher_custom_icon_row" class="n8n_union-field n8n_union-custom-icon-row <?php echo esc_attr($launcher_icon_type === 'custom' ? 'n8n_union-show' : 'n8n_union-hide'); ?>">
                    <label for="n8n_union_chat_launcher_icon_custom">Custom Launcher Icon</label>
                    <div class="n8n_union-upload-field">
                        <input type="url" id="n8n_union_chat_launcher_icon_custom" name="n8n_union_chat_launcher_icon_custom" value="<?php echo esc_attr($launcher_icon_custom); ?>" class="regular-text" placeholder="https://example.com/launcher.png">
                        <div class="n8n_union-upload-actions">
                            <button type="button" class="button" id="upload_launcher_icon_button"><?php echo esc_html($launcher_icon_custom ? 'Change Icon' : 'Upload Icon'); ?></button>
                            <button type="button" class="button n8n_union-remove-icon-btn <?php echo esc_attr($launcher_icon_custom ? 'n8n_union-show' : 'n8n_union-hide'); ?>" id="remove_launcher_icon_button">Remove Icon</button>
                        </div>
                    </div>
                    <div id="launcher_icon_preview" class="n8n_union-icon-preview-container">
                        <?php if ($launcher_icon_custom): ?>
                            <img src="<?php echo esc_url($launcher_icon_custom); ?>" class="n8n_union-bot-icon-preview" alt="<?php echo esc_attr('Custom Launcher Icon'); ?>">
                        <?php endif; ?>
                    </div>
                    <p class="description">Upload or paste the image you want to use for the launcher button. Recommended size: 50x50 pixels.</p>
                </div>
            </section>
        </div>

        <hr class="n8n_union-section-divider">
            

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Function to get predefined bot icons
function n8n_union_get_predefined_icon($icon_type) {
    $icons = [
        'robot' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2M21 9V7H15L13.5 7.5C13.1 7.4 12.6 7.5 12 7.5S10.9 7.4 10.5 7.5L9 7H3V9H4.2C4.6 12 6.9 14.3 10 14.7V19.5C10 20.6 10.4 21.6 11.2 22.2C11.7 22.7 12.4 23 13 23H14C14.6 23 15.2 22.8 15.7 22.4C16.5 21.8 17 20.8 17 19.7V14.6C20.1 14.2 22.4 11.9 22.8 9H24V7H21Z"/></svg>',
        'chat' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12,3C17.5,3 22,6.58 22,11C22,15.42 17.5,19 12,19C10.76,19 9.57,18.82 8.47,18.5C5.55,21 2,21 2,21C4.33,18.67 4.7,17.1 4.75,16.5C3.05,15.07 2,13.13 2,11C2,6.58 6.5,3 12,3Z"/></svg>',
        'assistant' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12,2A2,2 0 0,1 14,4A2,2 0 0,1 12,6A2,2 0 0,1 10,4A2,2 0 0,1 12,2M21,9V7L15,7.5V7.5C15,7.5 13.5,7 12,7C10.5,7 9,7.5 9,7.5L3,7V9H4.2C4.6,12 6.9,14.3 10,14.7V19.5L10.1,19.7C10.1,20.6 10.6,21.4 11.2,22C11.7,22.6 12.4,23 13,23H14C14.6,23 15.2,22.7 15.7,22.2C16.4,21.6 16.9,20.7 16.9,19.8L17,19.5V14.6C20.1,14.2 22.4,11.9 22.8,9H21Z"/></svg>',
        'support' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"/></svg>',
        'default' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12,2A2,2 0 0,1 14,4C14,5.1 13.1,6 12,6C10.9,6 10,5.1 10,4A2,2 0 0,1 12,2M10.5,7.5L9,7H3V9H4.2C4.6,12 6.9,14.3 10,14.7V22H14V14.6C17.1,14.2 19.4,11.9 19.8,9H21V7H15L13.5,7.5C13.1,7.4 12.6,7.5 12,7.5C11.4,7.5 10.9,7.4 10.5,7.5Z"/></svg>'
    ];
    
    return isset($icons[$icon_type]) ? $icons[$icon_type] : $icons['default'];
}

function n8n_union_chat_get_visitors() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    $visitors = get_transient('n8n_union_chat_visitors');
    if (!$visitors) $visitors = [];
    
    // Clean up old/inactive visitors (older than 10 minutes)
    $current_time = time();
    $active_visitors = [];
    
    foreach ($visitors as $visitor) {
        $time_since_activity = $current_time - $visitor['last_activity'];
        
        // Keep visitors active for 10 minutes (600 seconds)
        if ($time_since_activity < 600) {
            $active_visitors[] = $visitor;
        }
    }
    
    // Update the transient with cleaned visitors
    if (count($active_visitors) !== count($visitors)) {
        set_transient('n8n_union_chat_visitors', $active_visitors, 1800);
    }
    
    wp_send_json_success($active_visitors);
}

function n8n_union_chat_proxy_message() {
    check_ajax_referer('n8n_union_chat_frontend');

    $webhook_url = esc_url_raw(get_option('n8n_union_chat_webhook', ''));
    if (empty($webhook_url) || !wp_http_validate_url($webhook_url)) {
        wp_send_json_error(['message' => 'Chat automation endpoint is not configured.']);
    }
    
    $parsed = wp_parse_url($webhook_url);
    if (!$parsed || empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['https'], true)) {
        wp_send_json_error(['message' => 'Webhook URL must use https.']);
    }

    if (!isset($_POST['payload'])) {
        wp_send_json_error(['message' => 'Missing chat payload.']);
    }

    $payload_raw = wp_unslash($_POST['payload']);
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) {
        wp_send_json_error(['message' => 'Invalid chat payload.']);
    }

    if (!isset($payload['chatInput']) && isset($payload['chatinput'])) {
        $payload['chatInput'] = $payload['chatinput'];
    } elseif (!isset($payload['chatinput']) && isset($payload['chatInput'])) {
        $payload['chatinput'] = $payload['chatInput'];
    }

    if (empty($payload['action'])) {
        $payload['action'] = 'sendMessage';
    }

    if (empty($payload['sessionId']) && !empty($payload['visitorId'])) {
        $payload['sessionId'] = $payload['visitorId'];
    }

    if (empty($payload['metadata']) || !is_array($payload['metadata'])) {
        $payload['metadata'] = [];
    }

    if (empty($payload['metadata']['unionUser'])) {
        $payload['metadata']['unionUser'] = n8n_union_chat_get_union_user_context();
    }

    if (empty($payload['metadata']['visitorMeta'])) {
        $payload['metadata']['visitorMeta'] = n8n_union_chat_build_default_visitor_meta();
    }

    $allowed_routes = [];
    foreach (n8n_union_chat_get_routes() as $route) {
        $allowed_routes[$route['value']] = $route['label'];
    }
    $payload_route = !empty($payload['route']) ? sanitize_key($payload['route']) : '';
    if ($payload_route === '' || !isset($allowed_routes[$payload_route])) {
        $payload_route = array_key_first($allowed_routes);
    }
    $payload['route'] = $payload_route;
    $payload['routeLabel'] = $allowed_routes[$payload_route] ?? $payload_route;
    $payload['metadata']['selectedRoute'] = [
        'value' => $payload['route'],
        'label' => $payload['routeLabel'],
    ];

    if (empty($payload['visitorMeta'])) {
        $payload['visitorMeta'] = $payload['metadata']['visitorMeta'];
    }

    $attachments_allowed = true;

    if (!$attachments_allowed) {
        unset($payload['attachments']);
    } elseif (!empty($payload['attachments'])) {
        $payload['attachments'] = n8n_union_chat_prepare_attachments($payload['attachments']);
        if (empty($payload['attachments'])) {
            unset($payload['attachments']);
        }
    }

    $timeout = (int) apply_filters('n8n_union_chat_webhook_timeout', get_option('n8n_union_chat_webhook_timeout', 120));
    if ($timeout < 20) {
        $timeout = 20;
    }

    $response = wp_remote_post($webhook_url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => wp_json_encode($payload),
        'timeout' => $timeout,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        $decoded = [];
    }

    if (!isset($decoded['reply'])) {
        $decoded['reply'] = is_string($body) && $body !== '' ? $body : 'Hello! How can I help you today?';
    }

    wp_send_json_success($decoded);
}

function n8n_union_chat_initiate_chat() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    if (!isset($_POST['visitor_id']) || !isset($_POST['agent_id'])) {
        wp_send_json_error(['message' => 'Missing required parameters']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    $agent_id = sanitize_text_field(wp_unslash($_POST['agent_id']));
    
    // Get visitor and agent data
    $visitors = get_transient('n8n_union_chat_visitors');
    $agents = get_option('n8n_union_chat_agents', []);
    
    $visitor = null;
    $agent = null;
    
    // Find visitor
    foreach ($visitors as &$v) {
        if ($v['id'] === $visitor_id) {
            $visitor = &$v;
            break;
        }
    }
    
    // Find agent
    foreach ($agents as $a) {
        if ($a['id'] === $agent_id && $a['active'] && $a['status'] === 'online') {
            $agent = $a;
            break;
        }
    }
    
    if (!$visitor || !$agent) {
        wp_send_json_error(['message' => 'Invalid visitor or agent']);
        return;
    }
    
    if ($visitor['chatStatus'] === 'human') {
        wp_send_json_error(['message' => 'Visitor is already chatting with an agent']);
        return;
    }
    
    // Update visitor chat status
    $visitor['chatStatus'] = 'human';
    $visitor['agentId'] = $agent_id;
    set_transient('n8n_union_chat_visitors', $visitors, 3600);
    
    // Add agent introduction message to chat history
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    if (!$chat_history) $chat_history = [];
    
    $chat_history[] = [
        'type' => 'agent',
        'sender' => $agent['name'],
        'message' => "Hello! I'm " . $agent['name'] . " and I'll be helping you today. How can I assist you?",
        'timestamp' => time()
    ];
    
    set_transient($chat_key, $chat_history, 3600);
    
    wp_send_json_success(['message' => 'Chat initiated successfully']);
}

// Track active visitors

function n8n_union_chat_track_visitor() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    // Validate required parameters
    if (!isset($_POST['visitor_id']) || !isset($_POST['current_page']) || !isset($_POST['page_title']) || 
        !isset($_POST['referrer']) || !isset($_POST['user_agent']) || !isset($_POST['session_start'])) {
        wp_send_json_error(['message' => 'Missing required parameters']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    $current_page = sanitize_text_field(wp_unslash($_POST['current_page']));
    $page_title = sanitize_text_field(wp_unslash($_POST['page_title']));
    $referrer = sanitize_text_field(wp_unslash($_POST['referrer']));
    $user_agent = sanitize_text_field(wp_unslash($_POST['user_agent']));
    $session_start = intval(wp_unslash($_POST['session_start']));
    
    // Get visitor IP address
    $ip_address = n8n_union_get_visitor_ip();
    
    $visitors = get_transient('n8n_union_chat_visitors');
    if (!$visitors) $visitors = [];
    
    $visitor_exists = false;
    $current_time = time();
    
    foreach ($visitors as &$visitor) {
        if ($visitor['id'] === $visitor_id) {
            $visitor['last_activity'] = $current_time;
            $visitor['currentPage'] = $current_page;
            $visitor['pageTitle'] = $page_title;
            
            // Calculate time on site
            $time_diff = $current_time - $session_start;
            $minutes = floor($time_diff / 60);
            $seconds = $time_diff % 60;
            $visitor['timeOnSite'] = sprintf('%d:%02d', $minutes, $seconds);
            
            $visitor_exists = true;
            break;
        }
    }
    
    if (!$visitor_exists) {
        // Parse user agent for browser info
        $browser_info = n8n_union_parse_user_agent($user_agent);
        
        // Get location from IP
        $location_info = n8n_union_get_location_from_ip($ip_address);
        
        $visitors[] = [
            'id' => $visitor_id,
            'currentPage' => $current_page,
            'pageTitle' => $page_title,
            'referrer' => $referrer,
            'browser' => $browser_info['browser'],
            'os' => $browser_info['os'],
            'country' => $location_info['country'],
            'city' => $location_info['city'],
            'ip_address' => $ip_address,
            'timeOnSite' => '0:00',
            'chatStatus' => 'none',
            'agentId' => null,
            'sessionStart' => $session_start,
            'last_activity' => $current_time,
            'pageViews' => 1
        ];
    }
    
    set_transient('n8n_union_chat_visitors', $visitors, 3600);
    wp_send_json_success();
}

// Enhanced function to get visitor IP address
function n8n_union_get_visitor_ip() {
    // Check for IP from various headers (for proxies, load balancers, etc.)
    $ip_headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',      // Load balancers/proxies
        'HTTP_X_REAL_IP',            // Nginx proxy
        'HTTP_X_FORWARDED',          // Proxies
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
        'HTTP_FORWARDED_FOR',        // Proxies
        'HTTP_FORWARDED',            // Proxies
        'HTTP_CLIENT_IP',            // Proxies
        'REMOTE_ADDR'                // Standard
    ];
    
    $detected_ips = [];
    
    foreach ($ip_headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip_string = sanitize_text_field(wp_unslash($_SERVER[$header]));
            
            // Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs)
            if (strpos($ip_string, ',') !== false) {
                $ips = explode(',', $ip_string);
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $detected_ips[$header][] = $ip;
                    }
                }
            } else {
                $ip = trim($ip_string);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $detected_ips[$header] = $ip;
                }
            }
        }
    }
    
    // Priority order: try to get public IP first, then fall back to any valid IP
    foreach ($ip_headers as $header) {
        if (!empty($detected_ips[$header])) {
            $ip = is_array($detected_ips[$header]) ? $detected_ips[$header][0] : $detected_ips[$header];
            
            // Prefer public IPs over private ones
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    // If no public IP found, return the first valid IP we detected
    foreach ($ip_headers as $header) {
        if (!empty($detected_ips[$header])) {
            $ip = is_array($detected_ips[$header]) ? $detected_ips[$header][0] : $detected_ips[$header];
            return $ip;
        }
    }
    
    // Final fallback
    return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
}

// Debug function to see all IP headers (for troubleshooting)
function n8n_union_debug_ip_headers() {
    if (!n8n_union_chat_current_user_can_manage()) {
        return ['error' => 'Unauthorized'];
    }
    
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];
    
    $debug_info = [];
    $debug_info['detected_ip'] = n8n_union_get_visitor_ip();
    $debug_info['headers'] = [];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $debug_info['headers'][$header] = sanitize_text_field(wp_unslash($_SERVER[$header]));
        }
    }
    
    return $debug_info;
}

// Enhanced function to parse user agent
function n8n_union_parse_user_agent($user_agent) {
    $browser = 'Unknown';
    $os = 'Unknown';
    
    // Detect browser with version
    if (preg_match('/Chrome\/([0-9.]+)/', $user_agent, $matches)) {
        $browser = 'Chrome ' . explode('.', $matches[1])[0];
    } elseif (preg_match('/Firefox\/([0-9.]+)/', $user_agent, $matches)) {
        $browser = 'Firefox ' . explode('.', $matches[1])[0];
    } elseif (preg_match('/Safari\/([0-9.]+)/', $user_agent, $matches) && !strpos($user_agent, 'Chrome')) {
        $browser = 'Safari';
    } elseif (preg_match('/Edge\/([0-9.]+)/', $user_agent, $matches)) {
        $browser = 'Edge ' . explode('.', $matches[1])[0];
    } elseif (preg_match('/Edg\/([0-9.]+)/', $user_agent, $matches)) {
        $browser = 'Edge ' . explode('.', $matches[1])[0];
    } elseif (strpos($user_agent, 'Opera') !== false || strpos($user_agent, 'OPR') !== false) {
        $browser = 'Opera';
    }
    
    // Detect OS with more detail
    if (preg_match('/Windows NT ([0-9.]+)/', $user_agent, $matches)) {
        $version = $matches[1];
        switch ($version) {
            case '10.0': $os = 'Windows 10/11'; break;
            case '6.3': $os = 'Windows 8.1'; break;
            case '6.2': $os = 'Windows 8'; break;
            case '6.1': $os = 'Windows 7'; break;
            default: $os = 'Windows'; break;
        }
    } elseif (preg_match('/Mac OS X ([0-9_]+)/', $user_agent, $matches)) {
        $version = str_replace('_', '.', $matches[1]);
        $os = 'macOS ' . $version;
    } elseif (strpos($user_agent, 'Linux') !== false) {
        if (strpos($user_agent, 'Ubuntu') !== false) {
            $os = 'Ubuntu Linux';
        } else {
            $os = 'Linux';
        }
    } elseif (preg_match('/Android ([0-9.]+)/', $user_agent, $matches)) {
        $os = 'Android ' . explode('.', $matches[1])[0];
    } elseif (preg_match('/iPhone OS ([0-9_]+)/', $user_agent, $matches)) {
        $version = str_replace('_', '.', $matches[1]);
        $os = 'iOS ' . $version;
    } elseif (strpos($user_agent, 'iPad') !== false) {
        $os = 'iPadOS';
    }
    
    return ['browser' => $browser, 'os' => $os];
}

// Enhanced function to get location from IP
function n8n_union_get_location_from_ip($ip) {
    // Default values
    $location = [
        'country' => 'Unknown',
        'city' => 'Unknown'
    ];
    
    if (!(bool) get_option('n8n_union_enable_ip_lookup', 0)) {
        return $location;
    }
    
    // Handle local/private IPs
    if ($ip === '127.0.0.1' || $ip === '::1' || $ip === 'Unknown') {
        $location['country'] = 'Local';
        $location['city'] = 'Localhost';
        return $location;
    }
    
    // Check if it's a private IP range
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $location['country'] = 'Private Network';
        $location['city'] = 'Local Network';
        return $location;
    }
    
    // Try to get location using a free IP geolocation service
    $api_url = "http://ip-api.com/json/{$ip}?fields=status,country,city";
    
    $response = wp_remote_get($api_url, [
        'timeout' => 5,
        'user-agent' => 'WordPress/n8n-ChatAgent'
    ]);
    
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($data && $data['status'] === 'success') {
            $location['country'] = $data['country'] ?? 'Unknown';
            $location['city'] = $data['city'] ?? 'Unknown';
        }
    }
    
    return $location;
}

// AJAX handlers for agent-visitor messaging

function n8n_union_chat_send_agent_message() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    if (!isset($_POST['visitor_id']) || !isset($_POST['message'])) {
        wp_send_json_error(['message' => 'Missing required parameters']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    $message = sanitize_textarea_field(wp_unslash($_POST['message']));
    
    if (empty($message)) {
        wp_send_json_error(['message' => 'Message cannot be empty']);
        return;
    }
    
    // Get current agent info
    $current_user = wp_get_current_user();
    $agents = get_option('n8n_union_chat_agents', []);
    $agent_name = 'Agent';
    
    foreach ($agents as $agent) {
        if ($agent['email'] === $current_user->user_email) {
            $agent_name = $agent['name'];
            break;
        }
    }
    
    // Add message to chat history
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    if (!$chat_history) $chat_history = [];
    
    $chat_history[] = [
        'type' => 'agent',
        'sender' => $agent_name,
        'message' => $message,
        'timestamp' => time()
    ];
    
    set_transient($chat_key, $chat_history, 1800); // 30 minutes for faster updates
    
    wp_send_json_success(['message' => 'Message sent successfully']);
}

function n8n_union_chat_get_chat_messages() {
    $nonce_action = n8n_union_chat_current_user_can_manage() ? 'n8n_union_chat_admin' : 'n8n_union_chat_frontend';
    check_ajax_referer($nonce_action);
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    
    if (!$chat_history) $chat_history = [];
    
    wp_send_json_success($chat_history);
}

function n8n_union_chat_store_visitor_message() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    if (!isset($_POST['visitor_id']) || !isset($_POST['message'])) {
        wp_send_json_error(['message' => 'Missing required parameters']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    $message = sanitize_textarea_field(wp_unslash($_POST['message']));
    
    // Store message in chat history (optimized)
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    if (!$chat_history) $chat_history = [];
    
    $chat_history[] = [
        'type' => 'visitor',
        'sender' => 'Visitor',
        'message' => $message,
        'timestamp' => time()
    ];
    
    set_transient($chat_key, $chat_history, 3600);
    
    wp_send_json_success();
}

function n8n_union_chat_store_bot_message() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    if (!isset($_POST['visitor_id']) || !isset($_POST['message']) || !isset($_POST['sender'])) {
        wp_send_json_error(['message' => 'Missing required parameters']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    $message = sanitize_textarea_field(wp_unslash($_POST['message']));
    $sender = sanitize_text_field(wp_unslash($_POST['sender']));
    
    // Store message in chat history
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    if (!$chat_history) $chat_history = [];
    
    $chat_history[] = [
        'type' => 'bot',
        'sender' => $sender,
        'message' => $message,
        'timestamp' => time()
    ];
    
    set_transient($chat_key, $chat_history, 3600);
    wp_send_json_success();
}

function n8n_union_chat_update_visitor_chat_status() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    if (!isset($_POST['visitor_id']) || !isset($_POST['chat_status'])) {
        wp_send_json_error(['message' => 'Missing required parameters']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    $chat_status = sanitize_text_field(wp_unslash($_POST['chat_status']));
    $allowed_statuses = ['none', 'ai', 'human', 'requesting', 'ended'];
    if (!in_array($chat_status, $allowed_statuses, true)) {
        wp_send_json_error(['message' => 'Invalid chat status'], 400);
    }
    
    $visitors = get_transient('n8n_union_chat_visitors');
    if (!$visitors) $visitors = [];
    
    foreach ($visitors as &$visitor) {
        if ($visitor['id'] === $visitor_id) {
            $visitor['chatStatus'] = $chat_status;
            break;
        }
    }
    
    set_transient('n8n_union_chat_visitors', $visitors, 3600);
    wp_send_json_success();
}

function n8n_union_chat_get_visitor_chat_status() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    
    $visitors = get_transient('n8n_union_chat_visitors');
    if (!$visitors) {
        wp_send_json_error(['message' => 'No visitors found']);
        return;
    }
    
    foreach ($visitors as $visitor) {
        if ($visitor['id'] === $visitor_id) {
            $response_data = [
                'chatStatus' => $visitor['chatStatus'],
                'agent' => null
            ];
            
            // If human mode, get agent info
            if ($visitor['chatStatus'] === 'human' && isset($visitor['agentId'])) {
                $agents = get_option('n8n_union_chat_agents', []);
                foreach ($agents as $agent) {
                    if ($agent['id'] === $visitor['agentId']) {
                        $response_data['agent'] = $agent;
                        break;
                    }
                }
            }
            
            wp_send_json_success($response_data);
            return;
        }
    }
    
    wp_send_json_error(['message' => 'Visitor not found']);
}

function n8n_union_chat_debug_chat_history() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    if (!isset($_GET['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_GET['visitor_id']));
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    
    if (!$chat_history) $chat_history = [];
    
    wp_send_json_success([
        'visitor_id' => $visitor_id,
        'chat_key' => $chat_key,
        'message_count' => count($chat_history),
        'messages' => $chat_history
    ]);
}

function n8n_union_chat_check_agent_availability() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    $agents = get_option('n8n_union_chat_agents', []);
    $available_agents = 0;
    
    foreach ($agents as $agent) {
        if ($agent['active'] && $agent['status'] === 'online') {
            $available_agents++;
        }
    }
    
    wp_send_json_success([
        'available' => $available_agents > 0,
        'count' => $available_agents
    ]);
}

function n8n_union_chat_request_team_chat() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    
    // Get available agents
    $agents = get_option('n8n_union_chat_agents', []);
    $available_agents = [];
    
    foreach ($agents as $agent) {
        if ($agent['active'] && $agent['status'] === 'online') {
            $available_agents[] = $agent;
        }
    }
    
    if (empty($available_agents)) {
        wp_send_json_success([
            'agentAssigned' => false,
            'message' => 'No agents available'
        ]);
        return;
    }
    
    // Create a pending chat request instead of auto-assigning
    $request_id = 'chat_request_' . time() . '_' . $visitor_id;
    $chat_request = [
        'id' => $request_id,
        'visitor_id' => $visitor_id,
        'status' => 'pending',
        'created_at' => time(),
        'available_agents' => array_column($available_agents, 'id'),
        'visitor_info' => n8n_union_get_visitor_info($visitor_id)
    ];
    
    // Store the pending request (but first clean up any old requests for this visitor)
    $pending_requests = get_transient('n8n_union_pending_requests');
    if (!$pending_requests) $pending_requests = [];
    
    // Remove any existing requests for this visitor to prevent duplicates
    foreach ($pending_requests as $existing_id => $existing_request) {
        if ($existing_request['visitor_id'] === $visitor_id) {
            unset($pending_requests[$existing_id]);
        }
    }
    
    // Add the new request
    $pending_requests[$request_id] = $chat_request;
    set_transient('n8n_union_pending_requests', $pending_requests, 1800); // 30 minutes
    
    // Update visitor status to show request is pending
    $visitors = get_transient('n8n_union_chat_visitors');
    if ($visitors) {
        foreach ($visitors as &$visitor) {
            if ($visitor['id'] === $visitor_id) {
                $visitor['chatStatus'] = 'requesting';
                $visitor['requestId'] = $request_id;
                break;
            }
        }
        set_transient('n8n_union_chat_visitors', $visitors, 3600);
    }
    
    wp_send_json_success([
        'agentAssigned' => false,
        'requestPending' => true,
        'message' => 'Request sent to team'
    ]);
}

// Helper function to get visitor info for notifications
function n8n_union_get_visitor_info($visitor_id) {
    $visitors = get_transient('n8n_union_chat_visitors');
    if (!$visitors) return null;
    
    foreach ($visitors as $visitor) {
        if ($visitor['id'] === $visitor_id) {
            return [
                'id' => $visitor['id'],
                'pageTitle' => $visitor['pageTitle'] ?? 'Unknown Page',
                'currentPage' => $visitor['currentPage'] ?? '',
                'timeOnSite' => $visitor['timeOnSite'] ?? '0:00',
                'browser' => $visitor['browser'] ?? 'Unknown',
                'country' => $visitor['country'] ?? 'Unknown',
                'ip_address' => $visitor['ip_address'] ?? 'Unknown'
            ];
        }
    }
    return null;
}

// AJAX handlers for accepting/declining requests

function n8n_union_chat_get_pending_requests() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    $current_user = wp_get_current_user();
    $agents = get_option('n8n_union_chat_agents', []);
    $current_agent_id = null;
    foreach ($agents as $agent) {
        if ($agent['email'] === $current_user->user_email && $agent['active']) {
            $current_agent_id = $agent['id'];
            break;
        }
    }
    
    $pending_requests = get_transient('n8n_union_pending_requests');
    if (!$pending_requests) $pending_requests = [];
    
    // Filter requests for this agent
    $agent_requests = [];
    foreach ($pending_requests as $request) {
        if ($request['status'] === 'pending' && ($current_agent_id === null || in_array($current_agent_id, $request['available_agents'], true))) {
            $agent_requests[] = $request;
        }
    }
    
    wp_send_json_success($agent_requests);
}

function n8n_union_chat_accept_chat_request() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    
    // Get current user/agent info
    $current_agent = n8n_union_chat_get_current_agent_profile();
    
    // Get visitors and find the requesting visitor
    $visitors = get_transient('n8n_union_chat_visitors');
    if (!$visitors) {
        wp_send_json_error(['message' => 'No active visitors found']);
        return;
    }
    
    $visitor_found = false;
    foreach ($visitors as &$visitor) {
        if ($visitor['id'] === $visitor_id) {
            if ($visitor['chatStatus'] !== 'requesting') {
                wp_send_json_error(['message' => 'Visitor is not requesting chat']);
                return;
            }
            
            // Update visitor status to human chat
            $visitor['chatStatus'] = 'human';
            $visitor['agentId'] = $current_agent['id'];
            $visitor['agentName'] = $current_agent['name'];
            $visitor_found = true;
            break;
        }
    }
    
    if (!$visitor_found) {
        wp_send_json_error(['message' => 'Visitor not found']);
        return;
    }
    
    // Save updated visitors
    set_transient('n8n_union_chat_visitors', $visitors, 1800); // 30 minutes for faster updates
    
    // Add agent introduction message to chat history
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    if (!$chat_history) $chat_history = [];
    
    $chat_history[] = [
        'type' => 'agent',
        'sender' => $current_agent['name'],
        'message' => "Hello! I'm " . $current_agent['name'] . " from our team. I saw you wanted to speak with us. How can I help you today?",
        'timestamp' => time()
    ];
    
    set_transient($chat_key, $chat_history, 1800); // 30 minutes for faster updates
    
    wp_send_json_success([
        'message' => 'Chat request accepted successfully',
        'agent_name' => $current_agent['name'],
        'visitor_id' => $visitor_id
    ]);
}

function n8n_union_chat_decline_chat_request() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    if (!isset($_POST['request_id'])) {
        wp_send_json_error(['message' => 'Missing request ID']);
        return;
    }
    
    $request_id = sanitize_text_field(wp_unslash($_POST['request_id']));
    
    $pending_requests = get_transient('n8n_union_pending_requests');
    if (!$pending_requests || !isset($pending_requests[$request_id])) {
        wp_send_json_error(['message' => 'Request not found']);
        return;
    }
    
    $request = $pending_requests[$request_id];
    
    // Remove the declining agent from available agents
    $current_agent = n8n_union_chat_get_current_agent_profile();
    $current_agent_id = $current_agent['id'] ?? null;
    
    if ($current_agent_id) {
        $pending_requests[$request_id]['available_agents'] = array_diff(
            $pending_requests[$request_id]['available_agents'], 
            [$current_agent_id]
        );
        
        // If no more agents available, mark as declined
        if (empty($pending_requests[$request_id]['available_agents'])) {
            $pending_requests[$request_id]['status'] = 'declined';
            
            // Update visitor status back to AI
            $visitors = get_transient('n8n_union_chat_visitors');
            if ($visitors) {
                foreach ($visitors as &$visitor) {
                    if ($visitor['id'] === $request['visitor_id']) {
                        $visitor['chatStatus'] = 'ai';
                        unset($visitor['requestId']);
                        break;
                    }
                }
                set_transient('n8n_union_chat_visitors', $visitors, 3600);
            }
        }
        
        set_transient('n8n_union_pending_requests', $pending_requests, 1800);
    }
    
    wp_send_json_success(['message' => 'Request declined']);
}

function n8n_union_chat_check_visitor_status() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    
    $visitors = get_transient('n8n_union_chat_visitors');
    if (!$visitors) {
        wp_send_json_error(['message' => 'Visitor not found']);
        return;
    }
    
    foreach ($visitors as $visitor) {
        if ($visitor['id'] === $visitor_id) {
            wp_send_json_success([
                'chatStatus' => $visitor['chatStatus'],
                'agentId' => $visitor['agentId'] ?? null
            ]);
            return;
        }
    }
    
    wp_send_json_error(['message' => 'Visitor not found']);
}

// Function to end chat (agent-initiated)
function n8n_union_chat_end_chat() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    
    // Get current agent info
    $current_agent = n8n_union_chat_get_current_agent_profile();
    
    // Update visitor status to end chat
    $visitors = get_transient('n8n_union_chat_visitors');
    if ($visitors) {
        foreach ($visitors as &$visitor) {
            if ($visitor['id'] === $visitor_id && $visitor['agentId'] === $current_agent['id']) {
                $visitor['chatStatus'] = 'ended';
                unset($visitor['agentId']);
                unset($visitor['agentName']);
                break;
            }
        }
        set_transient('n8n_union_chat_visitors', $visitors, 1800);
    }
    
    // Add end message to chat history
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    if (!$chat_history) $chat_history = [];
    
    $chat_history[] = [
        'type' => 'system',
        'sender' => 'System',
        'message' => $current_agent['name'] . ' has ended the chat. Thank you for contacting us!',
        'timestamp' => time()
    ];
    
    set_transient($chat_key, $chat_history, 1800);
    
    wp_send_json_success(['message' => 'Chat ended successfully']);
}

// Function to transfer chat back to AI
function n8n_union_chat_transfer_to_ai() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    
    // Get current agent info
    $current_agent = n8n_union_chat_get_current_agent_profile();
    
    // Update visitor status back to AI
    $visitors = get_transient('n8n_union_chat_visitors');
    if ($visitors) {
        foreach ($visitors as &$visitor) {
            if ($visitor['id'] === $visitor_id && $visitor['agentId'] === $current_agent['id']) {
                $visitor['chatStatus'] = 'ai';
                unset($visitor['agentId']);
                unset($visitor['agentName']);
                break;
            }
        }
        set_transient('n8n_union_chat_visitors', $visitors, 1800);
    }
    
    // Add transfer message to chat history
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    if (!$chat_history) $chat_history = [];
    
    $ai_name = get_option('n8n_union_chat_ai_name', 'AI Assistant');
    $chat_history[] = [
        'type' => 'system',
        'sender' => 'System',
        'message' => $current_agent['name'] . ' has transferred you back to our ' . $ai_name . '. The ' . $ai_name . ' will continue to help you.',
        'timestamp' => time()
    ];
    
    set_transient($chat_key, $chat_history, 1800);
    
    wp_send_json_success(['message' => 'Chat transferred to AI successfully']);
}

// Function for visitor to end chat
function n8n_union_chat_end_visitor_chat() {
    check_ajax_referer('n8n_union_chat_frontend');
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    n8n_union_chat_require_visitor_token($visitor_id);
    
    // Update visitor status to end chat
    $visitors = get_transient('n8n_union_chat_visitors');
    if ($visitors) {
        foreach ($visitors as &$visitor) {
            if ($visitor['id'] === $visitor_id) {
                $visitor['chatStatus'] = 'ended';
                unset($visitor['agentId']);
                unset($visitor['agentName']);
                break;
            }
        }
        set_transient('n8n_union_chat_visitors', $visitors, 1800);
    }
    
    // Add end message to chat history
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $chat_history = get_transient($chat_key);
    if (!$chat_history) $chat_history = [];
    
    $chat_history[] = [
        'type' => 'system',
        'sender' => 'System',
        'message' => 'Visitor has ended the chat. Thank you for using our chat service!',
        'timestamp' => time()
    ];
    
    set_transient($chat_key, $chat_history, 1800);
    
    wp_send_json_success(['message' => 'Chat ended successfully']);
}

// AJAX handler for IP debugging
function n8n_union_chat_debug_ip_detection() {
    check_ajax_referer('n8n_union_chat_admin');
    n8n_union_chat_require_manage_capability();
    $debug_info = n8n_union_debug_ip_headers();
    wp_send_json_success($debug_info);
}

// Additional features section
// Agent visitor tracking function removed for performance optimization

// All agent-related AJAX handler functions removed for performance optimization

/**
 * Get chat messages for a specific visitor
 */
function n8n_union_get_chat_messages() {
    // Verify nonce - use different nonce for frontend vs admin
    $nonce_action = current_user_can('manage_options') ? 'n8n_union_chat_get_messages' : 'n8n_union_chat_frontend';
    
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), $nonce_action)) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }
    
    // Validate visitor_id parameter
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    // For admin users, check permissions. For visitors, allow access to their own messages
    if (current_user_can('manage_options')) {
        // Admin access - can view any visitor's messages
        $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    } else {
        // Visitor access - can only view their own messages  
        $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
        n8n_union_chat_require_visitor_token($visitor_id);
    }
    
    if (empty($visitor_id)) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
    }
    
    // Get real chat messages from your existing storage system
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $messages = get_transient($chat_key);
    
    if (!$messages || !is_array($messages)) {
        $messages = [];
    }
    
    wp_send_json_success($messages);
}

/**
 * Accept a chat request from visitor
 */
function n8n_union_accept_chat_request() {
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'n8n_union_chat_accept')) {
        wp_die('Security check failed');
    }
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    
    if (empty($visitor_id)) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
    }
    
    // Get current user info
    $current_user = wp_get_current_user();
    $agent_name = $current_user->display_name ?: $current_user->user_login;
    
    // Update visitor status in your existing system
    $visitors = get_transient('n8n_union_chat_visitors') ?: [];
    
    // Find and update the visitor
    foreach ($visitors as &$visitor) {
        if ($visitor['id'] === $visitor_id) {
            $visitor['chatStatus'] = 'human';
            $visitor['agentId'] = get_current_user_id();
            $visitor['agent_name'] = $agent_name;
            break;
        }
    }
    
    // Save updated visitors list
    set_transient('n8n_union_chat_visitors', $visitors, 3600);
    
    // Remove any pending requests for this visitor
    $pending_requests = get_transient('n8n_union_pending_requests') ?: [];
    $request_id = isset($_POST['request_id']) ? sanitize_text_field(wp_unslash($_POST['request_id'])) : null;
    
    if ($request_id && isset($pending_requests[$request_id])) {
        // Mark request as accepted and remove it
        unset($pending_requests[$request_id]);
        set_transient('n8n_union_pending_requests', $pending_requests, 1800);
    } else {
        // If no specific request ID, remove any pending requests for this visitor
        foreach ($pending_requests as $req_id => $request) {
            if ($request['visitor_id'] === $visitor_id) {
                unset($pending_requests[$req_id]);
            }
        }
        set_transient('n8n_union_pending_requests', $pending_requests, 1800);
    }
    
    // Add system message about agent joining (only if not already added)
    $chat_key = 'n8n_union_chat_' . $visitor_id;
    $existing_messages = get_transient($chat_key) ?: [];
    
    // Check if agent joined message already exists
    $agent_joined_exists = false;
    foreach ($existing_messages as $msg) {
        if ($msg['type'] === 'system' && strpos($msg['message'], 'has joined the chat') !== false) {
            $agent_joined_exists = true;
            break;
        }
    }
    
    // Only add system message if it doesn't already exist
    if (!$agent_joined_exists) {
        $system_message = [
            'type' => 'system',
            'sender' => 'System',
            'message' => "$agent_name has joined the chat",
            'timestamp' => time()
        ];
        
        $existing_messages[] = $system_message;
        set_transient($chat_key, $existing_messages, 3600);
    }
    
    // Notify visitor via your existing webhook system
    do_action('n8n_union_chat_accepted', $visitor_id, $agent_name);
    
    wp_send_json_success([
        'message' => 'Chat request accepted successfully',
        'agent_name' => $agent_name
    ]);
}

/**
 * Transfer chat back to AI
 */
function n8n_union_transfer_to_ai() {
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'n8n_union_chat_transfer_ai')) {
        wp_die('Security check failed');
    }
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    
    if (empty($visitor_id)) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
    }
    
    // Transfer chat back to AI (implement your logic here)
    wp_send_json_success(['message' => 'Chat transferred to AI']);
}

/**
 * End chat session
 */
function n8n_union_end_chat() {
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'n8n_union_chat_end_chat')) {
        wp_die('Security check failed');
    }
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    if (!isset($_POST['visitor_id'])) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
        return;
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    
    if (empty($visitor_id)) {
        wp_send_json_error(['message' => 'Missing visitor ID']);
    }
    
    // End chat session (implement your logic here)
    wp_send_json_success(['message' => 'Chat ended']);
}

/**
 * Debug IP detection
 */
function n8n_union_debug_ip_detection() {
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'n8n_union_chat_debug_ip')) {
        wp_die('Security check failed');
    }
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    // Get IP detection info
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0 || in_array($key, ['REMOTE_ADDR', 'SERVER_ADDR'])) {
            $headers[$key] = sanitize_text_field(wp_unslash($value));
        }
    }
    
    $detected_ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
    
    wp_send_json_success([
        'detected_ip' => $detected_ip,
        'headers' => $headers
    ]);
}

// Enqueue styles and scripts for admin pages
add_action('admin_enqueue_scripts', function($hook) {
    // Conversations page
    if ($hook === 'n8n-chatagent-for-unions_page_n8n-chatagent-for-unions-conversations') {
        $conversation_css = '
        .conversation-item {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 10px;
            background: #f9f9f9;
        }
        .visitor-info {
            font-weight: bold;
            color: #0073aa;
            margin-bottom: 8px;
        }
        .conversation-preview {
            color: #666;
            font-style: italic;
        }
        .timestamp {
            color: #999;
            font-size: 12px;
            float: right;
        }
        ';
        wp_add_inline_style('n8n-union-ai-admin-css', $conversation_css);
        
        $conversation_js = '
        jQuery(document).ready(function($) {

            
            function loadActiveVisitors() {
                $("#ai-conversations-list").html(`
                    <div class="conversation-item">
                        <div class="visitor-info">
                            Visitor Tracking Available
                            <span class="timestamp">Simplified monitoring</span>
                        </div>
                        <div class="conversation-preview">
                            AI chat monitoring is ready. Conversation logging can be implemented as needed.
                        </div>
                    </div>
                `);
            }
            
            loadActiveVisitors();
            setInterval(loadActiveVisitors, 30000);
        });
        ';
        wp_add_inline_script('n8n-union-ai-admin-js', $conversation_js);
    }
    
});
