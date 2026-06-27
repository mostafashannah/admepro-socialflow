<?php
// ================================================================
// SocialFlow — shared LinkedIn API publish logic.
// Used by both social-publish.php (manual "Publish Now" button) and
// auto-publish.php (scheduled cron). Mirrors meta-lib.php's shape.
//
// LinkedIn's public API only supports posting as the member who
// authorized the app (no DM/comment reply support, no Company Page
// posting without separate Marketing API partner approval).
// ================================================================

define('LINKEDIN_API_VERSION', '202401');

// Performs a LinkedIn post as the given member. Returns [http_code, decoded_response].
function linkedin_publish($author_urn, $access_token, $message, $image_url = '') {
    $endpoint = 'https://api.linkedin.com/v2/ugcPosts';

    $shareContent = [
        'shareCommentary'    => ['text' => $message],
        'shareMediaCategory' => $image_url ? 'IMAGE' : 'NONE',
    ];

    if ($image_url) {
        $asset = linkedin_register_and_upload_image($author_urn, $access_token, $image_url);
        if (!$asset) {
            return [502, ['error' => 'Failed to upload image to LinkedIn']];
        }
        $shareContent['media'] = [[
            'status' => 'READY',
            'media'  => $asset,
        ]];
    }

    $body = [
        'author'          => $author_urn,
        'lifecycleState'  => 'PUBLISHED',
        'specificContent' => [
            'com.linkedin.ugc.ShareContent' => $shareContent,
        ],
        'visibility' => [
            'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
        ],
    ];

    return linkedin_curl($endpoint, 'POST', $body, $access_token);
}

// Registers an upload slot, downloads the source image, uploads it to
// LinkedIn, and returns the asset URN to reference in the post body.
function linkedin_register_and_upload_image($author_urn, $access_token, $image_url) {
    [$code, $resp] = linkedin_curl('https://api.linkedin.com/v2/assets?action=registerUpload', 'POST', [
        'registerUploadRequest' => [
            'recipes'              => ['urn:li:digitalmediaRecipe:feedshare-image'],
            'owner'                => $author_urn,
            'serviceRelationships' => [[
                'relationshipType' => 'OWNER',
                'identifier'        => 'urn:li:userGeneratedContent',
            ]],
        ],
    ], $access_token);

    $uploadUrl = $resp['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? null;
    $asset     = $resp['value']['asset'] ?? null;
    if ($code !== 200 || !$uploadUrl || !$asset) return null;

    $imageData = @file_get_contents($image_url);
    if ($imageData === false) return null;

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_PUT            => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
    ]);
    $tmp = tmpfile();
    fwrite($tmp, $imageData);
    rewind($tmp);
    curl_setopt($ch, CURLOPT_INFILE, $tmp);
    curl_setopt($ch, CURLOPT_INFILESIZE, strlen($imageData));
    curl_exec($ch);
    $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($tmp);

    if ($uploadHttpCode < 200 || $uploadHttpCode >= 300) return null;
    return $asset;
}

function linkedin_curl($endpoint, $method, $body, $access_token) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'X-Restli-Protocol-Version: 2.0.0',
            'LinkedIn-Version: ' . LINKEDIN_API_VERSION,
        ],
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return [502, ['error' => "cURL error: {$curl_err}"]];
    return [$http_code, json_decode($response, true) ?: ['raw' => $response]];
}
