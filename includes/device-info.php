<?php

/**
 * Best-effort device/platform/brand/model parsing from a User-Agent string.
 * No client JS, no third-party service — just regex heuristics, so this is
 * necessarily approximate. See quizParseUserAgent()'s doc comment for what
 * it can and can't reliably tell apart.
 */

/**
 * @return array{deviceType:string, platform:string, browser:string, brand:string, model:string}
 *
 * Reliable: deviceType (mobile/tablet/desktop) and platform (Android/iOS/
 * Windows/macOS/Linux/ChromeOS) — both still present in every mainstream
 * browser's User-Agent string today.
 *
 * Best-effort, often blank: brand/model. Two separate limitations, neither
 * fixable by parsing harder:
 *   - iOS/Safari has never put the exact hardware model in the UA string —
 *     every iPhone says "iPhone", every iPad says "iPad", with no model
 *     number. brand still resolves to "Apple" there; model does not.
 *   - Chrome's "User-Agent Reduction" (rolling out since 2023 on Android,
 *     and already the default on desktop) replaces the real OS version and
 *     device model with frozen placeholders (e.g. "Android 10; K") for a
 *     growing share of visitors, by design, for privacy — this is a
 *     browser-side change with no server-side workaround. Getting the real
 *     model back would require the client to opt in via
 *     navigator.userAgentData.getHighEntropyValues(['model']) (Client
 *     Hints) and send it up separately; that's Chromium-only (no Safari/
 *     Firefox support) and isn't implemented here.
 */
function quizParseUserAgent(string $userAgent): array
{
    $ua = $userAgent;

    $deviceType = 'desktop';
    if (preg_match('/iPad|Tablet/i', $ua)) {
        $deviceType = 'tablet';
    } elseif (preg_match('/Mobi|iPhone|Android.*Mobile/i', $ua)) {
        $deviceType = 'mobile';
    }

    $platform = 'unknown';
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $platform = 'iOS';
    } elseif (stripos($ua, 'Android') !== false) {
        $platform = 'Android';
    } elseif (stripos($ua, 'Windows') !== false) {
        $platform = 'Windows';
    } elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        $platform = 'macOS';
    } elseif (stripos($ua, 'CrOS') !== false) {
        $platform = 'ChromeOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $platform = 'Linux';
    }

    $browser = 'unknown';
    if (stripos($ua, 'EdgA') !== false || stripos($ua, 'Edg/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'SamsungBrowser') !== false) {
        $browser = 'Samsung Internet';
    } elseif (stripos($ua, 'CriOS') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'FxiOS') !== false || stripos($ua, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    }

    $brand = '';
    $model = '';

    if ($platform === 'iOS') {
        $brand = 'Apple';
        $model = preg_match('/iPad/i', $ua) ? 'iPad' : (preg_match('/iPod/i', $ua) ? 'iPod' : 'iPhone');
    } elseif ($platform === 'macOS') {
        $brand = 'Apple';
    } elseif ($platform === 'Android') {
        // Typical shape: "...Android 13; SM-S918B Build/...) ..." — capture
        // the segment between the Android version's semicolon and either
        // ") " or " Build" — but note the limitation in the doc comment
        // above: Chrome's User-Agent Reduction replaces this with a frozen
        // placeholder like "K" for a growing share of requests, which this
        // regex will faithfully extract and which is simply not a model.
        if (preg_match('/Android [^;]+;\s*([^);]+?)(?:\s+Build\/|\))/i', $ua, $m)) {
            $token = trim($m[1]);
            if ($token !== '' && strtolower($token) !== 'k') {
                $model = $token;
                $brand = quizGuessAndroidBrand($token);
            }
        }
    }

    return [
        'deviceType' => $deviceType,
        'platform' => $platform,
        'browser' => $browser,
        'brand' => $brand,
        'model' => $model,
    ];
}

/** Best-effort brand guess from common Android model-string prefixes. */
function quizGuessAndroidBrand(string $model): string
{
    $prefixes = [
        'SM-' => 'Samsung',
        'GT-' => 'Samsung',
        'Pixel' => 'Google',
        'Nexus' => 'Google',
        'CPH' => 'Oppo',
        'RMX' => 'Realme',
        'RMP' => 'Realme',
        'Redmi' => 'Xiaomi',
        'POCO' => 'Xiaomi',
        'Mi ' => 'Xiaomi',
        'M20' => 'Xiaomi',
        'ONEPLUS' => 'OnePlus',
        'ONE PLUS' => 'OnePlus',
        'HUAWEI' => 'Huawei',
        'HONOR' => 'Honor',
        'Infinix' => 'Infinix',
        'Tecno' => 'Tecno',
        'Itel' => 'Itel',
        'vivo' => 'Vivo',
        'V20' => 'Vivo',
        'LG-' => 'LG',
        'Moto' => 'Motorola',
        'XT' => 'Motorola',
        'Lenovo' => 'Lenovo',
        'Nokia' => 'Nokia',
        'ASUS' => 'Asus',
        'Sony' => 'Sony',
    ];
    foreach ($prefixes as $prefix => $brand) {
        if (stripos($model, $prefix) === 0 || stripos($model, $prefix) !== false) {
            return $brand;
        }
    }
    return '';
}
