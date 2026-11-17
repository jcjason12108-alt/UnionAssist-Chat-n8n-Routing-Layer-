<?php

if (!defined('ABSPATH')) exit;

/**
 * Keep the widget's supported message fields, but never trust identity fields
 * supplied by a browser. The identity and request details come from WordPress.
 */
function n8n_union_chat_build_trusted_proxy_payload(array $input, array $union_user, array $request_meta) {
    $allowed_fields = [
        'action', 'sessionId', 'chatInput', 'chatinput', 'visitorId',
        'origin', 'humanMode', 'isHumanMode', 'agentId', 'route',
        'routeLabel', 'selectedRoute', 'routeSwitch', 'metadata',
        'attachments', 'responseId', 'rating',
    ];
    $payload = array_intersect_key($input, array_fill_keys($allowed_fields, true));

    $client_meta = isset($payload['metadata']) && is_array($payload['metadata'])
        ? $payload['metadata']
        : [];
    $visitor_meta = isset($client_meta['visitorMeta']) && is_array($client_meta['visitorMeta'])
        ? $client_meta['visitorMeta']
        : [];
    $client_visitor_fields = [
        'currentPage', 'pageTitle', 'referrer', 'userAgent', 'language',
        'timezone', 'screen', 'platform', 'sessionStart',
    ];
    $visitor_meta = array_intersect_key($visitor_meta, array_fill_keys($client_visitor_fields, true));

    $payload['metadata'] = [
        'unionUser' => $union_user,
        'visitorMeta' => array_merge($visitor_meta, $request_meta),
    ];
    $payload['visitorMeta'] = $payload['metadata']['visitorMeta'];

    return $payload;
}
