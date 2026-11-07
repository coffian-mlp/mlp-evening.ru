<?php
namespace Domain {
    // Capture HTTP attempt accounting without opening a database in this unit test.
    class LlmUsage { public static function record(array $row): void {} }
}
namespace {
    require_once __DIR__ . '/../autoload.php';
    $fail = 0;
    function budgetCheck(bool $ok, string $label): void {
        global $fail;
        echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
        if (!$ok) $fail++;
    }
    $before = LLM\UsageMeter::httpAttempts();
    LLM\UsageMeter::capture('routerai', 'fake', null, 0, true, 5, 'memory_embedding_index');
    LLM\UsageMeter::capture('routerai', 'fake', '{"usage":{"prompt_tokens":2,"total_tokens":2}}', 200, false, 5, 'memory_embedding_index');
    budgetCheck(LLM\UsageMeter::httpAttempts() - $before === 2, 'failed and repeated HTTP attempts are counted independently');
    $rows = [];
    for ($i=1; $i<=1000; $i++) $rows[] = ['id'=>$i,'user_id'=>1,'username'=>'Fixture','message'=>'fixture','created_at'=>'2026-11-07 00:00:00'];
    $checks = 0;
    try {
        (new LLM\MemoryEpisodeBuilder(12))->chat($rows, 'profile', function () use (&$checks) {
            if (++$checks === 3) throw new LLM\MemoryIndexDeadline('deadline');
        });
        budgetCheck(false, 'construction should stop');
    } catch (LLM\MemoryIndexDeadline $error) {
        budgetCheck($checks === 3, 'construction observes deadline during traversal');
    }
    echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
    exit($fail ? 1 : 0);
}
