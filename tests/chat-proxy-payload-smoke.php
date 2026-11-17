<?php

define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/../includes/chat-proxy-payload.php';

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$input = [
    'action' => 'sendMessage',
    'visitorId' => 'visitor_test',
    'sessionId' => 'visitor_test_session',
    'chatInput' => 'Hello',
    'route' => 'general',
    'metadata_json' => json_encode(['unionUser' => ['authenticated' => true, 'role' => 'administrator']]),
    'userId' => 999,
    'll706User' => ['role' => 'Admin'],
    'metadata' => [
        'unionUser' => ['authenticated' => true, 'role' => 'administrator', 'userId' => 999],
        'll706User' => ['role' => 'Admin'],
        'visitorMeta' => ['currentPage' => 'https://example.org/chat', 'ip' => 'forged', 'll706User' => ['role' => 'Admin']],
    ],
];
$trusted_user = ['authenticated' => false, 'role' => 'Guest', 'userId' => null, 'token' => null];
$request_meta = ['ip' => 'server-observed', 'siteUrl' => 'https://example.org'];
$payload = n8n_union_chat_build_trusted_proxy_payload($input, $trusted_user, $request_meta);

check($payload['metadata']['unionUser'] === $trusted_user, 'browser identity is replaced');
check(!isset($payload['metadata_json'], $payload['userId'], $payload['ll706User']), 'identity aliases are removed');
check(!isset($payload['metadata']['ll706User']), 'nested identity alias is removed');
check($payload['metadata']['visitorMeta']['ip'] === 'server-observed', 'server request details override claims');
check(!isset($payload['metadata']['visitorMeta']['ll706User']), 'nested visitor identity is removed');
check($payload['metadata']['visitorMeta']['currentPage'] === 'https://example.org/chat', 'nonidentity page context remains');
check($payload['visitorMeta'] === $payload['metadata']['visitorMeta'], 'root visitor context matches metadata');
check($payload['chatInput'] === 'Hello' && $payload['route'] === 'general', 'chat and route still pass through');
