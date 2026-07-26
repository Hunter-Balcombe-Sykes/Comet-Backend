<?php

// Active lane: obtain a real Supabase access token for a seeded identity
// via GoTrue's password grant — NOT direct-signing.
//
// The plan's original approach (direct-sign an HS256 token with the local
// JWT secret, matching prod claim shape) was spiked here 2026-07-26 and
// does not work against this Supabase CLI version (2.101.0): GoTrue's
// /auth/v1/user endpoint (the Auth-Server fallback VerifySupabaseJwt takes
// for HS256, since its JWKS path only accepts RS256/ES256 by design)
// validates that the JWT's session_id claim corresponds to a REAL row in
// auth.sessions — a forged session_id is rejected with 403
// "session_not_found" no matter how correctly the rest of the token is
// shaped. There is no way to forge a valid session_id; it only exists
// once GoTrue itself creates one. This resolves the plan's own named
// fallback ("Local JWT minting: direct-sign vs create+exchange") in
// favour of create+exchange — signing in for real is what produces a
// session GoTrue will actually recognise.
//
// A real sign-in also issues a real RS256/ES256-signed token, so it's
// accepted by VerifySupabaseJwt's primary JWKS path directly — no
// SUPABASE_JWKS_FAIL_CLOSED=false fallback dependency at runtime (that
// setting stays in .env.dast as a defensive fallback, unused in practice).
//
// Usage: php active/mint-jwt.php <email> <password>
// (seed-identities.php emits both in identities.json per identity)

require __DIR__.'/../../../vendor/autoload.php';

$envFile = __DIR__.'/../../../.env.dast';
if (! file_exists($envFile)) {
    fwrite(STDERR, "$envFile not found — run this against a live bring-up.sh stack\n");
    exit(1);
}
Dotenv\Dotenv::createImmutable(dirname($envFile), basename($envFile))->load();

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;
if (! $email || ! $password) {
    fwrite(STDERR, "usage: php active/mint-jwt.php <email> <password>\n");
    exit(1);
}

// Dotenv::load() populates $_ENV (and $_SERVER) but NOT the process env
// table — getenv() returns false here even after a successful load
// (verified 2026-07-26). Read $_ENV directly.
$supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
$anonKey = $_ENV['SUPABASE_ANON_KEY'] ?? null;
if (! $supabaseUrl || ! $anonKey) {
    fwrite(STDERR, "SUPABASE_URL / SUPABASE_ANON_KEY missing from .env.dast\n");
    exit(1);
}

$ch = curl_init("$supabaseUrl/auth/v1/token?grant_type=password");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['apikey: '.$anonKey, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = json_decode($body, true);
if ($status !== 200 || ! isset($json['access_token'])) {
    fwrite(STDERR, "sign-in failed ($status): $body\n");
    exit(1);
}

echo $json['access_token'];
