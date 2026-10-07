<?php
namespace LLM {
    /** Isolated provider seam: semantic/live responses only; rating authority remains actual SQL. */
    function curl_init($url) { return new \stdClass(); }
    function curl_setopt($handle, $option, $value) { $GLOBALS['mlp366_options'][$option] = $value; return true; }
    function curl_exec($handle) {
        $payload = json_decode($GLOBALS['mlp366_options'][CURLOPT_POSTFIELDS], true);
        $system = $payload['messages'][0]['content'] ?? '';
        $user = $payload['messages'][array_key_last($payload['messages'])]['content'] ?? '';
        $stage = isset($payload['plugins']) ? 'search' : (str_contains($system, 'Convert the supplied original_query') ? 'normalize' : 'live');
        $GLOBALS['mlp366_trace'][] = ['stage' => $stage, 'user' => $user];
        if ($stage === 'search') throw new \RuntimeException('Local unconstrained ratings must not use external WEB');
        if ($stage === 'normalize') {
            $mode = $GLOBALS['mlp366_mode'];
            $text = json_encode(['version' => 2, 'intent' => 'rating', 'direction' => $mode === 'worst' ? 'worst' : ($mode === 'best' ? 'best' : 'polarized'),
                'selection' => 'extreme', 'metric' => $mode === 'sd' ? 'standard_deviation' : ($mode === 'lovehate' ? 'polarization' : 'mean_score'),
                'requested_source' => null, 'scope_constraints' => [], 'search_query' => 'My Little Pony episode user ratings']);
        } else {
            // A provider decline deliberately exercises the guarded factual fallback, not external accuracy.
            $text = '';
        }
        return json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]]]);
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}
namespace {
    require_once dirname(__DIR__) . '/integration_helpers.php';
    if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') exit(1);
    it_require_db();
    $db = \Infra\Database::getInstance()->getConnection();
    $config = \Infra\ConfigManager::getInstance();
    $mode = $argv[1] ?? '';
    $shared = dirname(__DIR__, 2) . '/docs/private/mlp361-interactions-local.json';
    $private = dirname(__DIR__, 2) . '/docs/private/mlp366-ratings-local.json';
    $columns = ['IMDB_ID', 'IMDB_RATING', 'IMDB_VOTES', 'IMDB_HISTOGRAM', 'IMDB_SD', 'IMDB_RETRIEVED_AT', 'IMDB_SOURCE_ASOF', 'IMDB_PROVENANCE'];
    function mlp366Save(string $path, array $data): void {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $mask = umask(0077);
        try { file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR)); chmod($path, 0600); }
        finally { umask($mask); }
    }
    function mlp366Read(string $path): array { return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
    function mlp366Existing(array $args): string {
        $argv = [__FILE__, ...$args];
        ob_start();
        try { require __DIR__ . '/mlp-361-interactions-fixture.php'; return ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    function mlp366Import(array $fixture, array $case, bool $stale): array {
        $catalog = (new \Domain\EpisodeManager())->getAllEpisodes();
        $fingerprint = \Domain\EpisodeRatingSnapshot::catalogFingerprint($catalog);
        $base = ['schema_version' => 1, 'source' => 'imdb', 'parent_series' => 'tt1751105', 'catalog_fingerprint' => $fingerprint];
        $mapping = $base + ['records' => []];
        $snapshot = $base + ['scope' => ['kind' => 'catalogue', 'ids' => array_map(static fn($r) => (int)$r['ID'], $catalog), 'coverage' => 'complete'], 'records' => []];
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', time() - ($stale ? 46 * 86400 : 0));
        foreach ($catalog as $row) {
            $id = (int)$row['ID']; $imdb = $row['IMDB_ID'] ?? ('tt' . (string)(800000000 + $id));
            $meta = \Domain\EpisodeCatalog::metadata($row['TITLE']);
            $mapping['records'][] = ['episode_id' => $id, 'imdb_id' => $imdb, 'kind' => $meta ? 'episode' : 'special',
                'season' => $meta['season'] ?? null, 'episode' => $meta['episode'] ?? null, 'identity_url' => 'https://www.imdb.com/title/' . $imdb . '/'];
            $bins = [0,0,0,0,100,0,0,0,0,0]; $score = 5.0;
            if ($id === $case['worstId']) { $score = $case['worstValue'] ?? 2.1; $bins = [0,0,0,100,0,0,0,0,0,0]; }
            if ($id === $case['sdId']) { $score = 6.1; $bins = [60,0,0,0,0,0,0,0,0,40]; }
            if ($id === $case['lovehateId']) { $score = 7.1; $bins = [0,0,50,0,0,0,0,50,0,0]; }
            if ($id === $case['belowNId']) { $score = 10.0; $bins = [0,0,0,0,0,0,0,0,0,99]; }
            if ($id === $case['bestId']) { $score = $case['bestValue'] ?? 9.9; $bins = $case['bestHistogram'] ?? [0,0,0,0,0,0,0,0,0,100]; }
            $snapshot['records'][] = ['episode_id' => $id, 'imdb_id' => $imdb, 'rating' => $score, 'votes' => array_sum($bins),
                'histogram' => !empty($case['histogramMissing']) ? null : $bins, 'retrieved_at' => $timestamp, 'source_asof' => null, 'source_url' => 'https://www.imdb.com/title/' . $imdb . '/ratings/',
                'vote_scope' => 'all_countries', 'provenance' => ['channel' => 'ordinary_browser_dom']];
        }
        $header = json_decode(\Infra\ConfigManager::getInstance()->getOptionDetails(\Domain\EpisodeRatingManager::HEADER_KEY)['value'] ?? 'null', true);
        return (new \Domain\EpisodeRatingManager())->apply($snapshot, $mapping, $header['batch_hash'] ?? null);
    }
    if ($mode === 'setup') {
        if (is_file($private) || is_file($shared)) throw new \RuntimeException('Cleanup existing isolated fixture first');
        $rows = $db->query('SELECT ID,' . implode(',', $columns) . ' FROM episode_list ORDER BY ID')->fetch_all(MYSQLI_ASSOC);
        mlp366Save($private, ['ratings' => $rows, 'header' => $config->getOptionDetails(\Domain\EpisodeRatingManager::HEADER_KEY)]);
        echo mlp366Existing(['setup']);
    } elseif ($mode === 'user') {
        echo mlp366Existing(['continuation-user', $argv[2] ?? '']);
    } elseif ($mode === 'inspect') {
        echo mlp366Existing(['continuation-inspect', $argv[2] ?? '']);
    } elseif ($mode === 'invalidate') {
        echo mlp366Existing(['continuation-invalidate', $argv[2] ?? '', $argv[3] ?? '']);
    } elseif ($mode === 'ratings') {
        $fixture = mlp366Read($shared); $key = $argv[2] ?? ''; $case = $fixture['cases'][$key] ?? null;
        if (!$case) throw new \RuntimeException('Owned fixture case required');
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $title = 'MLP366 isolated local rating ' . $key . ' ' . $i;
            $stmt = $db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES (?,1)'); $stmt->bind_param('s', $title); $stmt->execute();
            $ids[] = (int)$db->insert_id; $fixture['episodes'][] = (int)$db->insert_id;
        }
        $targets = ['worstId' => $ids[0], 'sdId' => $ids[2], 'lovehateId' => $ids[3], 'belowNId' => $ids[4], 'bestId' => $ids[5], 'firstThreeIds' => array_slice($ids, 0, 3)];
        $fixture['cases'][$key] += $targets; mlp366Save($shared, $fixture);
        $report = mlp366Import($fixture, $fixture['cases'][$key], false);
        if ($report['status'] !== 'applied') throw new \RuntimeException('Actual local snapshot import required');
        echo json_encode($targets, JSON_THROW_ON_ERROR);
    } elseif ($mode === 'stale') {
        $fixture = mlp366Read($shared); $case = $fixture['cases'][$argv[2] ?? ''] ?? null;
        if (!$case) throw new \RuntimeException('Owned fixture case required');
        echo json_encode(mlp366Import($fixture, $case, true), JSON_THROW_ON_ERROR);
    } elseif ($mode === 'legacy-ratings') {
        $fixture = mlp366Read($shared); $case = $fixture['cases'][$argv[2] ?? ''] ?? null;
        if (!$case) throw new \RuntimeException('Owned legacy rating case required');
        if (!is_file($private)) {
            $rows = $db->query('SELECT ID,' . implode(',', $columns) . ' FROM episode_list ORDER BY ID')->fetch_all(MYSQLI_ASSOC);
            mlp366Save($private, ['ratings' => $rows, 'header' => $config->getOptionDetails(\Domain\EpisodeRatingManager::HEADER_KEY)]);
        }
        $scenario = $argv[3] ?? '';
        if ($scenario === 'rating-empty') {
            $headerKey = \Domain\EpisodeRatingManager::HEADER_KEY;
            $stmt = $db->prepare('DELETE FROM site_options WHERE key_name=?'); $stmt->bind_param('s', $headerKey); $stmt->execute();
            echo json_encode(['status' => 'missing_snapshot']);
        } else {
            $case += ['bestId' => (int)$case['targetId'], 'worstId' => (int)($case['lowTargetId'] ?? $case['targetId']),
                'sdId' => 0, 'lovehateId' => 0, 'belowNId' => 0, 'bestValue' => 9.5, 'worstValue' => 3.2,
                'histogramMissing' => $scenario === 'rating-polar-missing'];
            if ($scenario === 'rating-polar') $case['bestHistogram'] = [20,0,0,0,60,0,0,0,0,20];
            echo json_encode(mlp366Import($fixture, $case, false), JSON_THROW_ON_ERROR);
        }
    } elseif ($mode === 'worker') {
        $fixture = mlp366Read($shared); $key = $argv[2] ?? ''; $case = $fixture['cases'][$key] ?? null;
        if (!$case) throw new \RuntimeException('Owned fixture case required');
        $GLOBALS['mlp366_mode'] = $argv[3] ?? 'best'; $GLOBALS['mlp366_trace'] = [];
        if (!in_array($GLOBALS['mlp366_mode'], ['best','worst','sd','lovehate'], true)) throw new \RuntimeException('Unknown semantic fixture mode');
        $restore = [];
        foreach (['ai_enabled' => '1', 'ai_live_confirm' => '1', 'ai_primary_provider' => 'routerai', 'ai_routerai_key' => 'fixture-key', 'ai_routerai_model' => 'fixture-main',
            'ai_fast_model' => 'fixture-fast', 'ai_proxy_url' => '', 'ai_openai_key' => '', 'ai_openrouter_key' => '', 'ai_yandex_key' => '', 'ai_gigachat_key' => '',
            'ai_proactive_interval' => '99999999', 'bot_last_proactive' => (string)time(), 'ai_image_auto_interval' => '0', 'ai_memory_auto' => '0', 'bot_worker_heartbeat' => (string)time()] as $keyName => $value) {
            $restore[$keyName] = $config->getOption($keyName, null); $config->setOption($keyName, $value);
        }
        try { (new \LLM\BotWorker())->tick(); }
        finally {
            foreach ($restore as $keyName => $value) {
                if ($value === null) { $stmt = $db->prepare('DELETE FROM site_options WHERE key_name=?'); $stmt->bind_param('s', $keyName); $stmt->execute(); }
                else $config->setOption($keyName, $value);
            }
            $config->flushCache();
        }
        echo json_encode(['calls' => $GLOBALS['mlp366_trace']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } elseif ($mode === 'cleanup' || $mode === 'legacy-cleanup') {
        if (!is_file($private) && $mode === 'legacy-cleanup') { echo "No rating fixture to restore\n"; exit(0); }
        $saved = mlp366Read($private);
        $query = 'UPDATE episode_list SET ' . implode(',', array_map(static fn($c) => $c . '=?', $columns)) . ' WHERE ID=?';
        foreach ($saved['ratings'] as $row) {
            $values = array_map(static fn($c) => $row[$c], $columns); $values[] = $row['ID'];
            $stmt = $db->prepare($query); $stmt->bind_param(str_repeat('s', count($values)), ...$values); $stmt->execute();
        }
        $key = \Domain\EpisodeRatingManager::HEADER_KEY;
        if ($saved['header'] === null) {
            $stmt = $db->prepare('DELETE FROM site_options WHERE key_name=?'); $stmt->bind_param('s', $key); $stmt->execute();
        } else {
            $value = $saved['header']['value']; $updatedAt = $saved['header']['updated_at'];
            $stmt = $db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)');
            $stmt->bind_param('sss', $key, $value, $updatedAt); $stmt->execute();
        }
        $config->flushCache(); unlink($private);
        if ($mode === 'cleanup') echo mlp366Existing(['cleanup']);
        else echo "Rating fixture restored\n";
    } else {
        throw new \RuntimeException('Use setup|user|ratings|inspect|worker|stale|invalidate|legacy-ratings|legacy-cleanup|cleanup');
    }
}
