<?php
declare(strict_types=1);

function lti_build_launch_params(array $tool, array $user, array $course, string $returnUrl): array
{
    $roles = match ($user['role'] ?? 'student') {
        'instructor' => 'Instructor',
        'ta' => 'TeachingAssistant',
        default => 'Learner',
    };

    $params = [
        'lti_version' => 'LTI-1p0',
        'lti_message_type' => 'basic-lti-launch-request',
        'resource_link_id' => 'yourlms-tool-' . $tool['id'],
        'resource_link_title' => $tool['name'],
        'user_id' => (string) $user['id'],
        'roles' => $roles,
        'lis_person_name_full' => $user['full_name'] ?? '',
        'lis_person_contact_email' => $user['email'] ?? '',
        'context_id' => 'course-' . $course['id'],
        'context_label' => $course['code'] ?? '',
        'context_title' => $course['name'] ?? '',
        'launch_presentation_return_url' => $returnUrl,
        'tool_consumer_instance_guid' => 'yourlms.local',
        'tool_consumer_instance_name' => config()['app_name'] ?? 'YourLMS',
    ];

    if (!empty($tool['custom_params'])) {
        foreach (preg_split('/\r\n|\n/', (string) $tool['custom_params']) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $params[$k] = $v;
        }
    }

    return $params;
}

function lti_sign_params(array $params, string $url, string $key, string $secret): array
{
    $params['oauth_callback'] = 'about:blank';
    $params['oauth_consumer_key'] = $key;
    $params['oauth_nonce'] = bin2hex(random_bytes(8));
    $params['oauth_signature_method'] = 'HMAC-SHA1';
    $params['oauth_timestamp'] = (string) time();
    $params['oauth_version'] = '1.0';

    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    $base = 'POST&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $pairs));
    $signKey = rawurlencode($secret) . '&';
    $params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $signKey, true));
    return $params;
}