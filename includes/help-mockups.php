<?php

/**
 * Small, hand-drawn SVG primitives used to illustrate help.php's steps.
 * These are stylised mockups (not screenshots) that mimic assets/css/style.css's
 * real colors/shapes so the manual stays visually in sync with the live UI
 * without depending on a screenshot pipeline that would go stale.
 */

function helpSvgStart(int $width, int $height): string
{
    return '<svg class="help-mockup-svg" viewBox="0 0 ' . $width . ' ' . $height . '" '
        . 'xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" focusable="false">'
        . '<defs><linearGradient id="helpBtnGrad" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#ff6fa5"/><stop offset="1" stop-color="#7c5cff"/>'
        . '</linearGradient></defs>'
        . '<rect x="1" y="1" width="' . ($width - 2) . '" height="' . ($height - 2) . '" rx="16" '
        . 'fill="#ffffff" stroke="#efeaf7" stroke-width="2"/>';
}

function helpSvgEnd(): string
{
    return '</svg>';
}

function helpLabel(int $x, int $y, string $text, string $color = '#3a3350', int $size = 13): string
{
    return '<text x="' . $x . '" y="' . $y . '" font-family="Nunito, sans-serif" font-size="' . $size
        . '" font-weight="800" fill="' . $color . '">' . htmlspecialchars($text) . '</text>';
}

function helpFieldBox(int $x, int $y, int $w, int $h, string $value, string $icon = '▾'): string
{
    $out = '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="10" '
        . 'fill="#faf9ff" stroke="#d8d3e6" stroke-width="2"/>';
    $out .= '<text x="' . ($x + 14) . '" y="' . ($y + intdiv($h, 2) + 5) . '" font-family="Nunito, sans-serif" '
        . 'font-size="13" font-weight="700" fill="#3a3350">' . htmlspecialchars($value) . '</text>';
    if ($icon !== '') {
        $out .= '<text x="' . ($x + $w - 22) . '" y="' . ($y + intdiv($h, 2) + 5) . '" font-size="13" '
            . 'fill="#8a8299">' . htmlspecialchars($icon) . '</text>';
    }
    return $out;
}

function helpButton(int $x, int $y, int $w, int $h, string $label, string $variant = 'outline'): string
{
    if ($variant === 'primary') {
        $fill = 'url(#helpBtnGrad)';
        $textColor = '#ffffff';
        $strokeAttr = '';
    } else {
        $fill = '#ffffff';
        $textColor = '#6a45f0';
        $strokeAttr = ' stroke="#6a45f0" stroke-width="2"';
    }
    $out = '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . intdiv($h, 2)
        . '" fill="' . $fill . '"' . $strokeAttr . '/>';
    $out .= '<text x="' . ($x + intdiv($w, 2)) . '" y="' . ($y + intdiv($h, 2) + 5) . '" text-anchor="middle" '
        . 'font-family="Nunito, sans-serif" font-size="12.5" font-weight="800" fill="' . $textColor . '">'
        . htmlspecialchars($label) . '</text>';
    return $out;
}

function helpCheckboxRow(int $x, int $y, string $label, bool $checked = false): string
{
    if ($checked) {
        $box = '<rect x="' . $x . '" y="' . ($y - 12) . '" width="16" height="16" rx="4" fill="#7c5cff"/>'
            . '<path d="M' . ($x + 3) . ' ' . ($y - 4) . ' l4 4 l7 -8" stroke="#fff" stroke-width="2" '
            . 'fill="none" stroke-linecap="round" stroke-linejoin="round"/>';
    } else {
        $box = '<rect x="' . $x . '" y="' . ($y - 12) . '" width="16" height="16" rx="4" fill="#fff" '
            . 'stroke="#d8d3e6" stroke-width="2"/>';
    }
    return $box . helpLabel($x + 24, $y, $label, '#3a3350', 12);
}

function helpRadioRow(int $x, int $y, string $label, bool $checked = false): string
{
    $circle = $checked
        ? '<circle cx="' . $x . '" cy="' . ($y - 4) . '" r="8" fill="#fff" stroke="#7c5cff" stroke-width="2"/>'
            . '<circle cx="' . $x . '" cy="' . ($y - 4) . '" r="4" fill="#7c5cff"/>'
        : '<circle cx="' . $x . '" cy="' . ($y - 4) . '" r="8" fill="#fff" stroke="#d8d3e6" stroke-width="2"/>';
    return $circle . helpLabel($x + 16, $y, $label, '#3a3350', 12);
}

function helpCodeBox(int $x, int $y, int $w, int $h, string $text): string
{
    $out = '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="8" '
        . 'fill="#f6f2ff" stroke="#d8d3e6" stroke-width="2"/>';
    $out .= '<text x="' . ($x + 10) . '" y="' . ($y + intdiv($h, 2) + 4) . '" font-family="monospace" '
        . 'font-size="11" fill="#6a45f0">' . htmlspecialchars($text) . '</text>';
    return $out;
}

/**
 * Dashed highlight box with a short pointer label, drawn over an existing
 * element to show the learner exactly what to tap/click/type into.
 */
function helpHighlight(int $x, int $y, int $w, int $h, string $calloutText = 'Tap here', string $calloutSide = 'top'): string
{
    $out = '<rect x="' . ($x - 6) . '" y="' . ($y - 6) . '" width="' . ($w + 12) . '" height="' . ($h + 12)
        . '" rx="12" fill="none" stroke="#e5484d" stroke-width="3" stroke-dasharray="6 5"/>';
    if ($calloutSide === 'top') {
        $cx = $x + intdiv($w, 2);
        $cy = $y - 16;
        $out .= '<text x="' . $cx . '" y="' . $cy . '" text-anchor="middle" font-family="Nunito, sans-serif" '
            . 'font-size="12" font-weight="800" fill="#e5484d">👉 ' . htmlspecialchars($calloutText) . '</text>';
    } elseif ($calloutSide === 'bottom') {
        $cx = $x + intdiv($w, 2);
        $cy = $y + $h + 22;
        $out .= '<text x="' . $cx . '" y="' . $cy . '" text-anchor="middle" font-family="Nunito, sans-serif" '
            . 'font-size="12" font-weight="800" fill="#e5484d">👆 ' . htmlspecialchars($calloutText) . '</text>';
    } else {
        $cy = $y + intdiv($h, 2) + 4;
        $out .= '<text x="' . ($x + $w + 14) . '" y="' . $cy . '" text-anchor="start" font-family="Nunito, sans-serif" '
            . 'font-size="12" font-weight="800" fill="#e5484d">👈 ' . htmlspecialchars($calloutText) . '</text>';
    }
    return $out;
}

/**
 * The five quiz-setup action buttons as they appear on index.php, with one
 * of them highlighted. $highlightKey matches one of the array keys below.
 */
function helpActionButtonsMockup(string $highlightKey, string $calloutText): string
{
    $buttons = [
        'start' => ['label' => 'Start Quiz', 'variant' => 'primary', 'w' => 110],
        'share' => ['label' => 'Share Quiz', 'variant' => 'outline', 'w' => 110],
        'embedApp' => ['label' => 'Embed App', 'variant' => 'outline', 'w' => 110],
        'embedQuiz' => ['label' => 'Embed Quiz', 'variant' => 'outline', 'w' => 115],
        'pdf' => ['label' => 'PDF', 'variant' => 'outline', 'w' => 70],
    ];
    $width = 600;
    $height = 70;
    $out = helpSvgStart($width, $height);
    $x = 16;
    $y = 18;
    $h = 34;
    $gap = 10;
    foreach ($buttons as $key => $btn) {
        $out .= helpButton($x, $y, $btn['w'], $h, $btn['label'], $btn['variant']);
        if ($key === $highlightKey) {
            $out .= helpHighlight($x, $y, $btn['w'], $h, $calloutText, 'top');
        }
        $x += $btn['w'] + $gap;
    }
    $out .= helpSvgEnd();
    return $out;
}

/** An input field + "Copy" button row, as used for the Share Quiz link. */
function helpCopyRowMockup(string $label, string $value, string $calloutText = 'Tap Copy'): string
{
    $width = 600;
    $height = 90;
    $out = helpSvgStart($width, $height);
    $out .= helpLabel(20, 26, $label);
    $out .= helpFieldBox(20, 36, 440, 36, $value, '');
    $out .= helpButton(474, 36, 100, 36, 'Copy', 'outline');
    $out .= helpHighlight(474, 36, 100, 36, $calloutText, 'top');
    $out .= helpSvgEnd();
    return $out;
}

/** A two-line textarea + "Copy" button row, as used for the embed codes. */
function helpCopyCodeMockup(string $label, string $codeText, string $calloutText = 'Copy & paste into your site'): string
{
    $width = 600;
    $height = 110;
    $out = helpSvgStart($width, $height);
    $out .= helpLabel(20, 26, $label);
    $out .= helpCodeBox(20, 36, 440, 54, $codeText);
    $out .= helpButton(474, 36, 100, 36, 'Copy', 'outline');
    $out .= helpHighlight(20, 36, 554, 54, $calloutText, 'bottom');
    $out .= helpSvgEnd();
    return $out;
}
