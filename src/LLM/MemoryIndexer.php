<?php
namespace LLM;

use Domain\MemoryEpisodeManager;

/** Reconciles the corpus within one deadline; each element has association and embedding steps. */
final class MemoryIndexer
{
    private MemorySourceReader $sources;

    public function __construct(
        private MemoryEpisodeManager $store,
        private MemoryEpisodeBuilder $builder,
        private EmbeddingProviderInterface $embeddings,
        private MemoryAssociations $associations
    ) {
        $this->sources = new MemorySourceReader();
    }

    /** $elementLimit counts episodes or memory records, with up to two API calls per element. */
    public function run(bool $dryRun = false, int $elementLimit = 20, ?float $deadline = null): array
    {
        $deadline ??= microtime(true) + 50;
        $result = [
            'episodes' => 0, 'registered' => 0, 'ready' => 0, 'stale' => 0, 'errors' => 0,
            'pruned' => 0, 'dry_run' => $dryRun, 'elements' => 0, 'association_calls' => 0,
            'embedding_calls' => 0, 'http_attempts' => 0, 'deadline_stopped' => false,
            'stop_reason' => '', 'reconciled' => false, 'reconciliation_ms' => 0,
        ];
        if (microtime(true) >= $deadline) {
            $result['deadline_stopped'] = true;
            $result['stop_reason'] = 'deadline';
            return $result;
        }
        $db = $this->store->connection();
        $acquired = $db->query("SELECT GET_LOCK('memory_embeddings_indexer', 0)")->fetch_row()[0];
        if ((int)$acquired !== 1) return $result + ['busy' => 1];
        $attemptsBefore = UsageMeter::httpAttempts();
        $budget = null;
        $reconciliationStart = microtime(true);
        $reconciliationEnd = null;
        try {
            $budget = new MemoryIndexBudget($db, $deadline);
            $check = $budget->check(...);
            $prepare = $budget->prepare(...);
            $profile = hash('sha256', $this->embeddings->profile() . '|' . $this->associations->profile());
            $sources = $this->sources->sources($check, $prepare);
            $episodes = array_merge(
                $this->builder->chat($sources['chat'], $profile, $check),
                $this->builder->memory($sources['memory'], $profile, $check)
            );
            $budget->check();
            $result['episodes'] = count($episodes);
            $validate = fn($episode) => $this->sources->current($episode, $prepare);
            if (!$dryRun) {
                $pending = [];
                foreach ($episodes as $episode) {
                    $record = $budget->transaction(fn() => $this->store->register($episode, $validate));
                    if ($record === null) {
                        $result['stale']++;
                        continue;
                    }
                    $result['registered']++;
                    if ($record['status'] !== 'ready') $pending[] = [$episode, $record];
                }
                $budget->check();
                // The whole prune rolls back if its deadline or a lock budget expires.
                $result['pruned'] = $budget->transaction(fn() => $this->store->prune(array_column($episodes, 'key'), $prepare));
                $result['reconciled'] = true;
                $reconciliationEnd = microtime(true);
                foreach (array_slice($pending, 0, max(1, min(100, $elementLimit))) as [$episode, $record]) {
                    $budget->check();
                    try {
                        if (!$budget->transaction(fn() => $this->store->current($episode, $record, $validate))) {
                            $result['stale']++;
                            continue;
                        }
                        $budget->check();
                        $result['elements']++;
                        $result['association_calls']++;
                        $tags = $this->associations->generate($episode['text'], $deadline);
                        if (!$budget->transaction(fn() => $this->store->current($episode, $record, $validate))) {
                            $result['stale']++;
                            continue;
                        }
                        $budget->check();
                        $text = $episode['text'] . "\nПоисковые ассоциации (гипотезы): " . $tags;
                        $result['embedding_calls']++;
                        $vectors = $this->embeddings->embed([$text], $deadline);
                        if ($budget->transaction(fn() => $this->store->publish($episode, $record, $tags, $vectors[0], $validate))) {
                            $result['ready']++;
                        } else {
                            $result['stale']++;
                        }
                    } catch (MemoryIndexDeadline $error) {
                        throw $error;
                    } catch (\Throwable $error) {
                        $budget->check();
                        $result['errors']++;
                        error_log('Memory indexing deferred: ' . get_class($error));
                    }
                }
            }
        } catch (MemoryIndexDeadline $error) {
            $result['deadline_stopped'] = true;
            $result['stop_reason'] = $error->getMessage();
        } catch (\mysqli_sql_exception $error) {
            if (!in_array($error->getCode(), [1205, 1213, 3024], true)) throw $error;
            $result['deadline_stopped'] = true;
            $result['stop_reason'] = 'sql_wait';
        } finally {
            $result['http_attempts'] = UsageMeter::httpAttempts() - $attemptsBefore;
            $result['reconciliation_ms'] = (int)round((($reconciliationEnd ?? microtime(true)) - $reconciliationStart) * 1000);
            if ($result['reconciliation_ms'] > 25000) {
                error_log('Memory index reconciliation slow: ' . $result['reconciliation_ms'] . ' ms');
            }
            try {
                if ($budget !== null) $budget->restore();
            } finally {
                $db->query("SELECT RELEASE_LOCK('memory_embeddings_indexer')");
            }
        }
        return $result;
    }
}
