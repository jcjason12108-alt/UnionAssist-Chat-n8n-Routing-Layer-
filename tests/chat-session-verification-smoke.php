<?php

define('ABSPATH', __DIR__ . '/');
function add_action() {}
function wp_salt() { return 'test-only-secret'; }
function get_userdata($id) {
    return $id === 7 ? (object) ['ID' => 7] : false;
}
function n8n_union_chat_get_union_user_context($user) {
    return ['authenticated' => true, 'role' => 'Member', 'userId' => $user->ID, 'name' => 'Real Member', 'token' => 'new-token'];
}
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return strip_tags($value); }
function n8n_union_chat_prepare_attachments() { return []; }
class WP_Error {
    public $code;
    public $data;
    public function __construct($code, $message, $data) { $this->code = $code; $this->data = $data; }
}
class TestChatRequest {
    private $body;
    public function __construct($body) { $this->body = $body; }
    public function get_json_params() { return $this->body; }
}
require_once __DIR__ . '/../includes/chat-proxy-payload.php';
require_once __DIR__ . '/../includes/chat-session-verification.php';

function check($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}
function token_for($id, $issued, $expires) {
    $json = json_encode(['id' => $id, 'iat' => $issued, 'exp' => $expires]);
    return base64_encode($json . '.' . hash_hmac('sha256', $json, wp_salt()));
}

$now = time();
$valid = token_for(7, $now - 10, $now + 600);
$forged = [
    'action' => 'sendMessage', 'chatInput' => 'Hello', 'sessionId' => 'test-session',
    'route' => 'general', 'routeLabel' => 'General',
    'metadata_json' => json_encode(['unionUser' => ['role' => 'Admin']]),
    'metadata' => ['unionUser' => ['authenticated' => true, 'role' => 'Admin', 'userId' => 999, 'name' => 'Impostor', 'token' => $valid]],
];
$result = n8n_union_chat_verify_message(new TestChatRequest($forged));
check(is_array($result), 'valid token passes verification');
check($result['metadata']['unionUser']['userId'] === 7 && $result['metadata']['unionUser']['role'] === 'Member', 'forged identity is replaced with token owner');
check($result['metadata']['unionUser']['token'] === null && !isset($result['metadata_json']), 'token and alternate metadata are not forwarded');
check($result['metadata']['selectedRoute']['value'] === 'general', 'route context survives verification');

$forged['metadata']['unionUser']['token'] = token_for(7, $now - 700, $now - 100);
check(n8n_union_chat_verify_message(new TestChatRequest($forged)) instanceof WP_Error, 'expired token is rejected');
$forged['metadata']['unionUser']['token'] = substr($valid, 0, -2) . 'xx';
check(n8n_union_chat_verify_message(new TestChatRequest($forged)) instanceof WP_Error, 'tampered token is rejected');
unset($forged['metadata']['unionUser']['token']);
check(n8n_union_chat_verify_message(new TestChatRequest($forged)) instanceof WP_Error, 'missing token is rejected');
