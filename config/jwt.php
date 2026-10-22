<?php

function generateJWT($payload, $secret, $expire)
{
    if (!is_array($payload)) {
        throw new InvalidArgumentException('JWT payload must be an array.');
    }

    if (!is_string($secret) || $secret === '') {
        throw new InvalidArgumentException('JWT secret must be a non-empty string.');
    }

    if (!is_int($expire) || $expire <= 0) {
        throw new InvalidArgumentException('JWT expiration must be a positive integer.');
    }

    $issuedAt = time();
    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256',
    ];
    $claims = array_merge($payload, [
        'iat' => $issuedAt,
        'exp' => $issuedAt + $expire,
    ]);

    $encodedHeader = base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
    $encodedPayload = base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
    $signingInput = $encodedHeader . '.' . $encodedPayload;
    $signature = hash_hmac('sha256', $signingInput, $secret, true);

    return $signingInput . '.' . base64UrlEncode($signature);
}

function base64UrlEncode($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode($value)
{
    $remainder = strlen($value) % 4;
    if ($remainder) {
        $padlen = 4 - $remainder;
        $value .= str_repeat('=', $padlen);
    }
    return base64_decode(strtr($value, '-_', '+/'));
}

function verifyJWT(string $token, string $secret)
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    [$header64, $payload64, $sig64] = $parts;
    $signingInput = $header64 . '.' . $payload64;
    $expectedSig = base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));

    if (!hash_equals($expectedSig, $sig64)) {
        return false;
    }

    $payloadJson = base64UrlDecode($payload64);
    if (!$payloadJson) {
        return false;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return false;
    }

    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false; // Expired
    }

    return $payload;
}