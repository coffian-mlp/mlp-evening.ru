<?php

namespace Api;

use Domain\Auth;
use Domain\CommandInteractionManager;

/** HTTP boundary; handlers and post-commit delivery are injected by api.php. */
final class CommandInteractionController
{
    private static array $registry = [];
    private static $notifier = null;

    public static function configure(array $registry, callable $notifier): void
    {
        self::$registry = $registry;
        self::$notifier = $notifier;
    }

    public static function get(): void
    {
        $manager = new CommandInteractionManager(self::$registry);
        Response::ok('', $manager->readPublic((int)($_POST['interaction_id'] ?? 0),
            Auth::check() ? (int)$_SESSION['user_id'] : null, (int)($_POST['message_id'] ?? 0)));
    }

    public static function act(): void
    {
        Auth::requireApiLogin();
        $actorId = (int)$_SESSION['user_id'];
        $id = (int)($_POST['interaction_id'] ?? 0);
        $manager = new CommandInteractionManager(self::$registry);
        $outcome = $manager->consume($id, (string)($_POST['option_key'] ?? ''), $actorId);
        // Delivery failure cannot change the already committed domain result.
        try {
            if (self::$notifier !== null) (self::$notifier)($id, $actorId, $outcome);
        } catch (\Throwable $error) {
            error_log('Command interaction delivery failed: id=' . $id . ' ' . $error->getMessage());
        }
        Response::ok('', ['outcome' => $outcome]);
    }
}
