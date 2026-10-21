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