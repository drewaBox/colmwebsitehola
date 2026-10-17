<?php
// Compares the name on the student's account with the name read from a document.
// Works with any word order ("Juan Dela Cruz" = "CRUZ, JUAN D."), ignores accents, titles and small spelling mistakes
// that come from reading a photo.

// Lowercase letters and spaces only, without titles like "Mr." or "Jr.".
function normalize_name($name) {
    $name = trim((string)$name);
    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($name, Normalizer::FORM_D);
        if ($decomposed !== false) $name = preg_replace('/\p{Mn}+/u', '', $decomposed);
    }
    $name = mb_strtolower($name, 'UTF-8');
    $name = preg_replace('/[^\p{L}\s]/u', ' ', $name);           // dots, commas, hyphens, digits -> space
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $ignore = ['mr', 'mrs', 'ms', 'miss', 'dr', 'jr', 'sr', 'ii', 'iii', 'iv'];
    return array_values(array_filter($words, fn($w) => !in_array($w, $ignore, true)));
}

// PHP's levenshtein() works on bytes, so letters like "ñ" would count twice. We first give every letter
// one byte of its own, then compare. Returns 0.0 (different) ... 1.0 (same) for two single words.
function word_similarity($a, $b) {
    if ($a === $b) return 1.0;
    $lettersA = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
    $lettersB = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
    $longest = max(count($lettersA), count($lettersB));
    if ($longest === 0 || $longest > 200) return 0.0;
    $codes = [];
    $toBytes = function ($letters) use (&$codes) {
        $text = '';
        foreach ($letters as $letter) {
            if (!isset($codes[$letter])) $codes[$letter] = chr(1 + count($codes) % 250);
            $text .= $codes[$letter];
        }
        return $text;
    };
    $distance = levenshtein($toBytes($lettersA), $toBytes($lettersB));
    $similarity = 1 - $distance / $longest;
    // One wrong or missing letter is accepted in words of 4+ letters (reading a photo is not perfect: "Anna" / "Ana").
    if ($distance <= 1 && $longest >= 4) $similarity = max($similarity, 0.8);
    return $similarity;
}

// Best similarity of one word against all words of the other name.
function best_word_match($word, $otherWords) {
    $best = 0.0;
    foreach ($otherWords as $other) $best = max($best, word_similarity($word, $other));
    return $best;
}

// Returns ['match' => true/false, 'score' => 0-100].
// The first and the last word of the account name must both be found in the document name.
function names_match($accountName, $documentName) {
    $account = normalize_name($accountName);
    $document = normalize_name($documentName);
    if (!$account || !$document) return ['match' => false, 'score' => 0];

    $important = array_values(array_filter($account, fn($w) => mb_strlen($w) > 1));
    if (!$important) $important = $account;

    $scores = [];
    foreach ($important as $word) $scores[] = best_word_match($word, $document);
    $score = (int)round(100 * array_sum($scores) / count($scores));

    $first = $scores[0];
    $last = $scores[count($scores) - 1];
    $threshold = 0.8; // 1 wrong letter in a short name is accepted, a different name is not
    return ['match' => $first >= $threshold && $last >= $threshold, 'score' => $score];
}
