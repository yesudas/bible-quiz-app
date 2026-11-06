<?php

/**
 * Leaderboard "Achievements" star tiers — a lifetime-score milestone ladder
 * shown on leaderboard.php once a player is identified by mobile number
 * (see includes/quiz-leaderboard.php's matchHash lookup). Five tiers, each
 * with 5 stars; a tier's Nth star lights up once the player's lifetime
 * correctCount reaches that star's threshold. Thresholds are admin-editable
 * (a.php?view=achievements) and stored in counters/achievement-thresholds.txt
 * (same gitignored/.htaccess-protected pattern as the other counters/*.txt
 * files — this is config, not PII, but it's edited live on the server so it
 * must not be overwritten by a deploy).
 */

/** Ordered tier keys (display order) with a label and CSS color. */
function quizAchievementTiers(): array
{
    return [
        'silver' => ['label' => 'Silver', 'color' => '#b9c2cc'],
        'bronze' => ['label' => 'Bronze', 'color' => '#c27a3f'],
        'gold' => ['label' => 'Gold', 'color' => '#e8b923'],
        'platinum' => ['label' => 'Platinum', 'color' => '#7fa8b8'],
        'diamond' => ['label' => 'Diamond', 'color' => '#58c7e0'],
    ];
}

function quizAchievementThresholdsFile(): string
{
    return __DIR__ . '/../counters/achievement-thresholds.txt';
}

/** @return array<string, list<int>> tier key => 5 ascending correct-answer thresholds */
function quizAchievementDefaultThresholds(): array
{
    return [
        'silver' => [100, 200, 300, 400, 500],
        'bronze' => [1000, 1500, 2000, 2500, 3000],
        'gold' => [3500, 4000, 4500, 5000, 5500],
        'platinum' => [6000, 6500, 7000, 8000, 9000],
        'diamond' => [10000, 11000, 12000, 13000, 14000],
    ];
}

/**
 * Reads the admin-configured thresholds, falling back to the default for any
 * tier that's missing or malformed (so a partially-written/corrupt file still
 * yields a usable, fully-populated table).
 *
 * @return array<string, list<int>>
 */
function quizAchievementReadThresholds(): array
{
    $defaults = quizAchievementDefaultThresholds();
    $file = quizAchievementThresholdsFile();
    if (!is_file($file)) {
        return $defaults;
    }

    $raw = file_get_contents($file);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return $defaults;
    }

    $out = [];
    foreach ($defaults as $tier => $defaultValues) {
        $values = $decoded[$tier] ?? null;
        if (is_array($values) && count($values) === 5) {
            $values = array_map('intval', array_values($values));
            sort($values);
            $out[$tier] = $values;
        } else {
            $out[$tier] = $defaultValues;
        }
    }
    return $out;
}

/**
 * Admin edit: overwrites every tier's 5 thresholds. Each tier's 5 values are
 * sorted ascending and clamped to non-negative integers — malformed input
 * (wrong count) leaves that tier at its previous value rather than writing a
 * broken table.
 *
 * @param array<string, list<int>> $thresholds
 */
function quizAchievementSetThresholds(array $thresholds): bool
{
    $current = quizAchievementReadThresholds();
    foreach (array_keys(quizAchievementDefaultThresholds()) as $tier) {
        $values = $thresholds[$tier] ?? null;
        if (!is_array($values) || count($values) !== 5) {
            continue;
        }
        $values = array_map(static fn ($v): int => max(0, (int) $v), array_values($values));
        sort($values);
        $current[$tier] = $values;
    }

    $dir = dirname(quizAchievementThresholdsFile());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $written = @file_put_contents(
        quizAchievementThresholdsFile(),
        json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $written !== false;
}

/**
 * Computes which stars are lit for a given lifetime correct-answer count.
 *
 * @return list<array{key:string, label:string, color:string, stars:list<bool>, thresholds:list<int>}>
 */
function quizAchievementProgress(int $correctCount): array
{
    $thresholds = quizAchievementReadThresholds();
    $out = [];
    foreach (quizAchievementTiers() as $key => $meta) {
        $tierThresholds = $thresholds[$key] ?? [];
        $stars = [];
        foreach ($tierThresholds as $threshold) {
            $stars[] = $correctCount >= $threshold;
        }
        $out[] = [
            'key' => $key,
            'label' => $meta['label'],
            'color' => $meta['color'],
            'stars' => $stars,
            'thresholds' => $tierThresholds,
        ];
    }
    return $out;
}
