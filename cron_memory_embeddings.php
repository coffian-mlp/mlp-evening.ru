<?php
/** Background semantic indexing. Explicit operator flags precede any source read or API call. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/autoload.php';

use Infra\ConfigManager;
use LLM\LLMManager;
use LLM\MemoryAssociations;
use LLM\MemoryEpisodeBuilder;
use LLM\MemoryIndexer;
use LLM\RouterAIEmbeddingProvider;
use Domain\MemoryEpisodeManager;

try {
    $arguments = array_slice($argv, 1);
    $dryRun = in_array('--dry-run', $arguments, true);
    $config = ConfigManager::getInstance();
    if (!$dryRun && (!(int)$config->getOption('ai_memory_index_enabled', 0) || !(int)$config->getOption('ai_memory_index_history', 0))) {
        echo "Memory indexing disabled.\n";
        exit(0);
    }
    $llm = new LLMManager();
    $associations = new MemoryAssociations($llm->generateMemoryAssociations(...), $llm->memoryAssociationProfile());
    $indexer = new MemoryIndexer(new MemoryEpisodeManager(),
        new MemoryEpisodeBuilder((int)$config->getOption('ai_bot_user_id', 12)), RouterAIEmbeddingProvider::fromConfig(), $associations);
    $result = $indexer->run($dryRun);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    exit(($result['errors'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $error) {
    // Provider responses and exception messages may include credentials or source contents.
    fwrite(STDERR, 'Memory indexing failed: ' . get_class($error) . "\n");
    exit(1);
}
