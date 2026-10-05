<?php
namespace LLM {
    // Replace only the external HTTP transport; manager and provider remain real.
    function curl_init($url) { return new \stdClass(); }
    function curl_setopt($handle, $option, $value) {
        $GLOBALS['playlist_scoped_options'][$option] = $value;
        return true;
    }
    function curl_exec($handle) {
        $GLOBALS['playlist_scoped_calls']++;
        if ($GLOBALS['playlist_scoped_throw'] ?? false) throw new \RuntimeException('Fixture transport failure');
        return json_encode(['choices' => [['finish_reason' => 'stop', 'message' => [
            'content' => $GLOBALS['playlist_scoped_text'],
            'annotations' => $GLOBALS['playlist_scoped_annotations'],
        ]]]]);
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}
namespace {
    require_once __DIR__ . '/integration_helpers.php';
    if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('requires isolated db');
    $probe = it_require_db();
    $probe->close();
    $db = Infra\Database::getInstance()->getConnection();
    $GLOBALS['playlist_scoped_calls'] = 0;
    $GLOBALS['playlist_scoped_text'] = '{"candidates":[]}';
    $GLOBALS['playlist_scoped_annotations'] = [['url_citation' => [
        'url' => 'https://example.org/episode', 'title' => 'Fixture source',
    ]]];
    try {
        Infra\Transaction::run($db, static function () {
            $config = Infra\ConfigManager::getInstance();
            foreach (['ai_enabled' => '1', 'ai_live_confirm' => '1', 'ai_bot_user_id' => '1',
                'ai_primary_provider' => 'routerai', 'ai_routerai_key' => 'fixture-key',
                'ai_routerai_model' => 'fixture-main', 'ai_fast_model' => 'fixture-fast',
                'ai_proxy_url' => '', 'ai_openai_key' => '', 'ai_openrouter_key' => '',
                'ai_yandex_key' => '', 'ai_gigachat_key' => ''] as $key => $value) {
                $config->setOption($key, $value);
            }
            $manager = new LLM\LLMManager();
            $search = json_decode($manager->generateSearchUtility([], 'Fixture query', time() + 12), true);
            check($search['sources'][0]['url'] === 'https://example.org/episode', 'manager returns actual transport citations');
            $payload = json_decode($GLOBALS['playlist_scoped_options'][CURLOPT_POSTFIELDS], true);
            check($payload['plugins'][0]['max_results'] === 3 && $payload['reasoning']['effort'] === 'low', 'search manager applies scoped web and reasoning');
            check($GLOBALS['playlist_scoped_options'][CURLOPT_TIMEOUT] <= 12, 'search timeout respects remaining deadline');
            $calls = $GLOBALS['playlist_scoped_calls'];
            check($manager->generateSearchUtility([], 'Expired query', time() + 4) === null && $GLOBALS['playlist_scoped_calls'] === $calls, 'search insufficient budget makes no HTTP call');
            $GLOBALS['playlist_scoped_annotations'] = [];
            check($manager->generateSearchUtility([], 'No sources', time() + 12) === null, 'search without provider citations rejected');
            $GLOBALS['playlist_scoped_text'] = '{"verified":true}';
            check($manager->generateBoundedUtility([], 'Fixture verification', time() + 9, 20) === '{"verified":true}', 'bounded verifier executes actual manager and provider');
            check($GLOBALS['playlist_scoped_options'][CURLOPT_TIMEOUT] <= 9, 'verification clamps transport to absolute deadline');
            $calls = $GLOBALS['playlist_scoped_calls'];
            check($manager->generateBoundedUtility([], 'Expired verification', time() - 1) === null && $GLOBALS['playlist_scoped_calls'] === $calls, 'expired verifier makes no HTTP call');
            $GLOBALS['playlist_scoped_text'] = 'С удовольствием! Пожелание №7 записано. [[reaction:heart]]';
            $live = $manager->liveTextBounded('Confirm recorded wish №7', '№7', time() + 12);
            check($live !== null && str_contains($live, '№7') && !str_contains($live, '[[reaction:'), 'live manager preserves anchor and strips reaction');
            check($manager->liveTextBounded('Confirm recorded wish №8', '№8', time() + 12) === null, 'live missing required fact rejected');
            $GLOBALS['playlist_scoped_text'] = '![image](https://example.org/image.png)';
            check($manager->liveTextBounded('Confirm', null, time() + 12) === null, 'live image reply rejected');
            $config->setOption('ai_live_confirm', '0');
            $calls = $GLOBALS['playlist_scoped_calls'];
            check($manager->liveTextBounded('Confirm', null, time() + 12) === null && $GLOBALS['playlist_scoped_calls'] === $calls, 'disabled live makes no HTTP call');
            $config->setOption('ai_live_confirm', '1');
            $GLOBALS['playlist_scoped_throw'] = true;
            check($manager->generateSearchUtility([], 'Transport failure', time() + 12) === null, 'search transport failure returns unavailable');
            check($manager->generateBoundedUtility([], 'Transport failure', time() + 12) === null, 'verification transport failure returns unavailable');
            $GLOBALS['playlist_scoped_throw'] = false;
            $config->setOption('ai_enabled', '0');
            $calls = $GLOBALS['playlist_scoped_calls'];
            check($manager->generateSearchUtility([], 'Disabled AI', time() + 12) === null
                && $manager->generateBoundedUtility([], 'Disabled AI', time() + 12) === null
                && $GLOBALS['playlist_scoped_calls'] === $calls, 'disabled AI skips search and verifier transport');
            throw new RuntimeException('scoped fixture rollback');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'scoped fixture rollback') throw $error;
    } finally {
        Infra\ConfigManager::getInstance()->flushCache();
    }
    it_done();
}
