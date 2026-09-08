<?php
/**
 * trello-lib.php — shared Trello REST helpers used by trello-lists.php
 * (setup-time board/list lookup), trello-sync.php (push SocialFlow stage
 * changes to Trello), trello-webhook-register.php, and trello-webhook.php
 * (pull Trello card moves back into SocialFlow).
 *
 * Trello's API auth is just a key+token pair appended to every request —
 * no OAuth dance, no token refresh — so every call here just needs both
 * passed in explicitly (they're stored per-client in integrations.credentials).
 */

function trello_request(string $method, string $path, string $apiKey, string $token, array $params = []) {
    $params['key'] = $apiKey;
    $params['token'] = $token;
    $url = "https://api.trello.com/1{$path}";
    $ch = curl_init();
    if ($method === 'GET' || $method === 'DELETE') {
        $url .= '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return [0, ['error' => $err]];
    return [$code, json_decode($res, true)];
}

/**
 * A board can be identified by its real id, or the short id/slug from its
 * URL (trello.com/b/XXXXXXXX/board-name) — accepts either and always
 * returns the canonical id + name.
 */
function trello_resolve_board(string $apiKey, string $token, string $boardInput): array {
    $id = $boardInput;
    if (preg_match('~trello\.com/b/([a-zA-Z0-9]+)~', $boardInput, $m)) $id = $m[1];
    [$code, $resp] = trello_request('GET', "/boards/{$id}", $apiKey, $token, ['fields' => 'name,id']);
    if ($code !== 200 || !isset($resp['id'])) {
        return ['ok' => false, 'error' => $resp['error'] ?? "Couldn't find that Trello board — check the URL/ID and that the token has access to it."];
    }
    return ['ok' => true, 'id' => $resp['id'], 'name' => $resp['name']];
}

function trello_get_lists(string $apiKey, string $token, string $boardId): array {
    [$code, $resp] = trello_request('GET', "/boards/{$boardId}/lists", $apiKey, $token, ['fields' => 'name,id', 'filter' => 'open']);
    if ($code !== 200 || !is_array($resp)) {
        return ['ok' => false, 'error' => $resp['error'] ?? 'Could not load lists for this board.'];
    }
    return ['ok' => true, 'lists' => array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name']], $resp)];
}

function trello_create_card(string $apiKey, string $token, string $listId, string $name, string $desc = ''): array {
    [$code, $resp] = trello_request('POST', '/cards', $apiKey, $token, ['idList' => $listId, 'name' => $name, 'desc' => $desc]);
    if ($code !== 200 || !isset($resp['id'])) {
        return ['ok' => false, 'error' => $resp['error'] ?? 'Trello card creation failed.'];
    }
    return ['ok' => true, 'card_id' => $resp['id']];
}

function trello_move_card(string $apiKey, string $token, string $cardId, string $listId): array {
    [$code, $resp] = trello_request('PUT', "/cards/{$cardId}", $apiKey, $token, ['idList' => $listId]);
    if ($code !== 200) {
        return ['ok' => false, 'error' => $resp['error'] ?? 'Trello card move failed.'];
    }
    return ['ok' => true];
}

function trello_create_webhook(string $apiKey, string $token, string $callbackUrl, string $idModel): array {
    [$code, $resp] = trello_request('POST', '/webhooks', $apiKey, $token, [
        'callbackURL' => $callbackUrl, 'idModel' => $idModel, 'description' => 'SocialFlow sync',
    ]);
    if ($code !== 200 || !isset($resp['id'])) {
        return ['ok' => false, 'error' => $resp['error'] ?? 'Trello webhook registration failed — Trello must be able to reach the callback URL over HTTPS.'];
    }
    return ['ok' => true, 'webhook_id' => $resp['id']];
}

function trello_delete_webhook(string $apiKey, string $token, string $webhookId): void {
    trello_request('DELETE', "/webhooks/{$webhookId}", $apiKey, $token);
}

function trello_add_comment(string $apiKey, string $token, string $cardId, string $text): array {
    [$code, $resp] = trello_request('POST', "/cards/{$cardId}/actions/comments", $apiKey, $token, ['text' => $text]);
    if ($code !== 200) return ['ok' => false, 'error' => $resp['error'] ?? 'Trello comment failed.'];
    return ['ok' => true, 'comment_id' => $resp['id'] ?? null];
}

// Attaches by URL (not a raw file upload) — the file already lives in
// SocialFlow's own storage with a public URL, so Trello just needs to link
// to it rather than receiving a re-upload of the bytes.
function trello_add_attachment_url(string $apiKey, string $token, string $cardId, string $url, string $name = ''): array {
    $params = ['url' => $url];
    if ($name !== '') $params['name'] = $name;
    [$code, $resp] = trello_request('POST', "/cards/{$cardId}/attachments", $apiKey, $token, $params);
    if ($code !== 200) return ['ok' => false, 'error' => $resp['error'] ?? 'Trello attachment failed.'];
    return ['ok' => true, 'attachment_id' => $resp['id'] ?? null];
}
