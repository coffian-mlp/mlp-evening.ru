<?php
/** MLP-458: observe every regex stage and inject failures without changing production hooks. */
namespace LLM {
    function preg_replace_callback($pattern, $callback, $subject, $limit = -1, &$count = null, $flags = 0) {
        $GLOBALS['latin_callback_calls']++;
        if (($GLOBALS['latin_fail_callback'] ?? 0) === $GLOBALS['latin_callback_calls']) return null;
        $result = \preg_replace_callback($pattern, $callback, $subject, $limit, $count, $flags);
        $GLOBALS['latin_regex_errors'][] = \preg_last_error();
        return $result;
    }
    function preg_match_all($pattern, $subject, &$matches = null, $flags = 0, $offset = 0) {
        $GLOBALS['latin_match_calls']++;
        if (($GLOBALS['latin_fail_match'] ?? 0) === $GLOBALS['latin_match_calls']) return false;
        $result = \preg_match_all($pattern, $subject, $matches, $flags, $offset);
        $GLOBALS['latin_regex_errors'][] = \preg_last_error();
        return $result;
    }
    function preg_replace($pattern, $replacement, $subject, $limit = -1, &$count = null) {
        $result = \preg_replace($pattern, $replacement, $subject, $limit, $count);
        $GLOBALS['latin_regex_errors'][] = \preg_last_error();
        return $result;
    }
}
namespace {
    require dirname(__DIR__) . '/autoload.php';
    $fail = 0;
    function latin_failure_check($condition, $label) {
        global $fail;
        echo ($condition ? '[OK] ' : '[FAIL] ') . $label . "\n";
        if (!$condition) $fail++;
    }
    function latin_failure_reset($callback = 0, $match = 0) {
        $GLOBALS['latin_callback_calls'] = $GLOBALS['latin_match_calls'] = 0;
        $GLOBALS['latin_fail_callback'] = $callback; $GLOBALS['latin_fail_match'] = $match;
        $GLOBALS['latin_regex_errors'] = [];
    }
    $profiles = [
        'Title ' . str_repeat('of ', 8000) . 'random',
        str_repeat('Title ', 4000) . 'random',
        '«' . str_repeat('quoted ', 4000) . '» random',
        str_repeat('«', 11000) . ' random',
    ];
    foreach ($profiles as $index => $text) {
        latin_failure_reset();
        $foreign = LLM\ResponseSanitizer::foreignLatinInReply($text, []);
        latin_failure_check(strlen($text) >= 20000 && in_array('random', $foreign, true), 'long profile preserves foreign word ' . $index);
        latin_failure_check(!array_filter($GLOBALS['latin_regex_errors']), 'every regex stage has no error ' . $index);
    }
    latin_failure_reset(1);
    latin_failure_check(LLM\ResponseSanitizer::foreignLatinInReply('https://example.test/?q=Title&lang=english random', []) === ['random'], 'URL regex failure retains original URL exclusion');
    latin_failure_reset(2);
    latin_failure_check(LLM\ResponseSanitizer::foreignLatinInReply('«unapproved» random', []) === ['unapproved', 'random'], 'quote regex failure retains input');
    latin_failure_reset(0, 1);
    latin_failure_check(LLM\ResponseSanitizer::foreignLatinInReply('Ace Attorney random', []) === ['ace', 'attorney', 'random'], 'token regex failure retains input');
    echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
    exit((int)($fail > 0));
}
