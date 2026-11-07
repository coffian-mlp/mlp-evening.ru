<?php
namespace LLM;

/** Indexer-only session limits and single-attempt transactions; shared Transaction remains unchanged. */
final class MemoryIndexBudget
{
    private array $previous;

    public function __construct(private \mysqli $db, private float $deadline)
    {
        $this->check();
        $this->previous = $db->query('SELECT @@SESSION.innodb_lock_wait_timeout AS row_wait, @@SESSION.lock_wait_timeout AS metadata_wait, @@SESSION.max_execution_time AS query_ms')->fetch_assoc();
        $this->prepare();
    }

    public function check(): void
    {
        if (microtime(true) >= $this->deadline) throw new MemoryIndexDeadline('deadline');
    }

    public function prepare(): void
    {
        $this->check();
        $remaining = $this->deadline - microtime(true);
        $wait = max(1, min(5, (int)floor($remaining - 0.05)));
        $queryMs = max(1, (int)floor($remaining * 1000));
        $this->db->query("SET SESSION innodb_lock_wait_timeout=$wait, lock_wait_timeout=$wait, max_execution_time=$queryMs");
    }

    public function transaction(callable $work): mixed
    {
        $this->prepare();
        // An outer native transaction makes nested Transaction::run use savepoints without retrying.
        $this->db->begin_transaction();
        try {
            $result = $work();
            $this->check();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollback();
            if ($error instanceof \mysqli_sql_exception && in_array($error->getCode(), [1205, 1213, 3024], true)) {
                throw new MemoryIndexDeadline('sql_wait', 0, $error);
            }
            throw $error;
        }
    }

    public function restore(): void
    {
        $rowWait = (int)$this->previous['row_wait'];
        $metadataWait = (int)$this->previous['metadata_wait'];
        $queryMs = (int)$this->previous['query_ms'];
        $this->db->query("SET SESSION innodb_lock_wait_timeout=$rowWait, lock_wait_timeout=$metadataWait, max_execution_time=$queryMs");
    }
}
