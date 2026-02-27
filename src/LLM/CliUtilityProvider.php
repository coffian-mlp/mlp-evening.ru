<?php

require_once __DIR__ . '/LLMProviderInterface.php';

class CliUtilityProvider implements LLMProviderInterface {
    private $commandTemplate;

    public function __construct($commandTemplate = 'aider --message %s --no-git --yes') {
        $this->commandTemplate = $commandTemplate;
    }

    public function askChat(array $messagesContext, string $systemPrompt): ?string {
        // Build a single prompt from context for CLI
        $fullPrompt = $systemPrompt . "\n\nContext:\n";
        foreach ($messagesContext as $msg) {
            $fullPrompt .= "[{$msg['role']}] {$msg['content']}\n";
        }
        $fullPrompt .= "\nRespond as assistant:";

        $escapedPrompt = escapeshellarg($fullPrompt);
        $command = sprintf($this->commandTemplate, $escapedPrompt);

        $output = shell_exec($command);
        
        if ($output === null || trim($output) === '') {
            throw new Exception("CLI Utility returned empty response");
        }

        return trim($output);
    }
}
