<?php

if (!defined('ABSPATH')) exit;

/** Decode a short-lived WordPress chat token only after its HMAC and time checks pass. */
function n8n_union_chat_decode_session_token($token, $secret, $now = null) {
    if (!is_string($token) || $token === '' || strlen($token) > 8192) {
        return null;
    }
    $decoded = base64_decode($token, true);
    if (!is_string($decoded)) {
        return null;
    }
    $separator = strrpos($decoded, '.');
    if ($separator === false) {
        return null;
    }
    $json = substr($decoded, 0, $separator);
    $signature = substr($decoded, $separator + 1);
    if (!preg_match('/^[a-f0-9]{64}$/D', $signature)) {
        return null;
    }
    if (!hash_equals(hash_hmac('sha256', $json, $secret), $signature)) {
        return null;
    }
    $claims = json_decode($json, true);
    if (!is_array($claims)) {
        return null;
    }
    $now = $now === null ? time() : (int) $now;
    $id = filter_var($claims['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $issued = filter_var($claims['iat'] ?? null, FILTER_VALIDATE_INT);
    $expires = filter_var($claims['exp'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($id) || !is_int($issued) || !is_int($expires) || $issued > $now + 60 || $expires <= $now || $expires <= $issued) {
        return null;
    }
    return $claims;
}

function n8n_union_chat_register_verification_route() {
    register_rest_route('n8n-union-chat/v1', '/verify-message', [
        'methods' => 'POST',
        'callback' => 'n8n_union_chat_verify_message',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'n8n_union_chat_register_verification_route');

/**
 * n8n calls this before any chat tools run. It returns the original message
 * with an identity reconstructed from the signed token and current WP user.
 */
function n8n_union_chat_verify_message($request) {
    $input = $request->get_json_params();
    $client_user = is_array($input) && isset($input['metadata']['unionUser']) && is_array($input['metadata']['unionUser'])
        ? $input['metadata']['unionUser']
        : [];
    $token = $client_user['token'] ?? null;
    $claims = n8n_union_chat_decode_session_token($token, wp_salt('auth'));
    if ($claims === null) {
        return new WP_Error('n8n_union_chat_invalid_token', 'Chat session is invalid or expired.', ['status' => 403]);
    }
    $user = get_userdata((int) $claims['id']);
    if (!$user || !$user->ID) {
        return new WP_Error('n8n_union_chat_missing_user', 'Chat account is unavailable.', ['status' => 403]);
    }

    $trusted_user = n8n_union_chat_get_union_user_context($user);
    $trusted_user['token'] = null;
    $payload = n8n_union_chat_build_trusted_proxy_payload($input, $trusted_user, []);
    if (isset($payload['route']) && is_scalar($payload['route'])) {
        $payload['metadata']['selectedRoute'] = [
            'value' => sanitize_key((string) $payload['route']),
            'label' => isset($payload['routeLabel']) && is_scalar($payload['routeLabel'])
                ? sanitize_text_field((string) $payload['routeLabel'])
                : '',
        ];
    }
    if (!empty($payload['attachments'])) {
        $payload['attachments'] = n8n_union_chat_prepare_attachments($payload['attachments']);
    }
    return $payload;
}
