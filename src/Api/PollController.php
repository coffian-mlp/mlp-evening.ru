<?php

namespace Api;

use Auth;
use ChatManager;
use Infra\ConfigManager;
use PollManager;


/**
 * API-действия опросов (MLP-238) — второй срез тонкого роутера api.php.
 * Роль (logged-in) проверяет роутер; тонкую проверку «кто может создавать»
 * (конфиг polls_create_role) и «кто может закрыть» — здесь.
 * Ответы через глобальную sendResponse().
 */
class PollController {

    /** Право создавать опрос по настройке дашборда (admin / moderator / all). */
    private static function canCreate(): bool {
        $role = ConfigManager::getInstance()->getOption('polls_create_role', 'moderator');
        if ($role === 'all')   return Auth::check();
        if ($role === 'admin') return Auth::isAdmin();
        return Auth::isModerator(); // 'moderator' (дефолт) = модер + админ
    }

    public static function create(): void {
        if (!self::canCreate()) {
            sendResponse(false, "Недостаточно прав для создания опроса", 'error');
        }
        $question = trim($_POST['question'] ?? '');
        // Варианты: options[] (текст) + опциональный параллельный option_images[] (URL превью).
        $optTexts  = $_POST['options'] ?? [];
        $optImages = $_POST['option_images'] ?? [];
        if (!is_array($optTexts)) $optTexts = [];
        if (!is_array($optImages)) $optImages = [];
        $options = [];
        foreach ($optTexts as $i => $t) {
            $options[] = ['text' => (string)$t, 'image_url' => (string)($optImages[$i] ?? '')];
        }
        $isMulti     = !empty($_POST['is_multi']);
        $isAnonymous = !empty($_POST['is_anonymous']);

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $pm = new PollManager();
        $pollId = $pm->create($userId, $question, $options, $isMulti, $isAnonymous);
        if (!$pollId) {
            sendResponse(false, "Нужен вопрос и хотя бы 2 варианта", 'error');
        }

        // Опрос появляется в ленте как сообщение-карточка (маркер рендерит фронт, MLP-239).
        // addMessage сам делает realtime-broadcast — отдельная рассылка не нужна.
        try {
            $chat = new ChatManager();
            $username = $_SESSION['username'] ?? 'user';
            $messageId = $chat->addMessage($userId, $username, '[[poll:' . $pollId . ']]');
            if ($messageId) $pm->attachMessage($pollId, (int)$messageId);
        } catch (\Throwable $e) {
            error_log('PollController::create addMessage ' . $e->getMessage());
        }

        sendResponse(true, "Опрос создан!", 'success', [
            'poll'    => $pm->getPoll($pollId),
            'results' => $pm->getResults($pollId),
        ]);
    }

    public static function vote(): void {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $optionIds = $_POST['option_ids'] ?? [];
        if (!is_array($optionIds)) {
            $optionIds = ($optionIds === '' || $optionIds === null) ? [] : [$optionIds];
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);

        $pm = new PollManager();
        if (!$pm->vote($pollId, $userId, $optionIds)) {
            sendResponse(false, "Не удалось проголосовать (опрос закрыт или неверный вариант)", 'error');
        }
        // Realtime-broadcast делает PollManager::vote() — единый путь для API и бота.
        $poll    = $pm->getPoll($pollId);
        $results = $pm->getResults($pollId);
        $voters  = empty($poll['is_anonymous']) ? $pm->getVotersByOption($pollId) : null;

        sendResponse(true, "Голос учтён", 'success', [
            'results'  => $results,
            'my_votes' => $pm->getUserVotes($pollId, $userId),
            'voters'   => $voters,
        ]);
    }

    public static function close(): void {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $pm = new PollManager();
        $poll = $pm->getPoll($pollId);
        if (!$poll) {
            sendResponse(false, "Опрос не найден", 'error');
        }
        // Закрыть может автор или модератор/админ.
        if ((int)$poll['created_by'] !== (int)($_SESSION['user_id'] ?? 0) && !Auth::isModerator()) {
            sendResponse(false, "Access Denied", 'error');
        }
        $pm->close($pollId); // realtime poll_closed шлёт PollManager::close()
        sendResponse(true, "Опрос закрыт", 'success', ['results' => $pm->getResults($pollId)]);
    }

    public static function get(): void {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $pm = new PollManager();
        $poll = $pm->getPoll($pollId);
        if (!$poll) {
            sendResponse(false, "Опрос не найден", 'error');
        }
        $data = ['poll' => $poll, 'results' => $pm->getResults($pollId)];
        if (empty($poll['is_anonymous'])) {
            $data['voters'] = $pm->getVotersByOption($pollId);
        }
        if (Auth::check()) {
            $data['my_votes'] = $pm->getUserVotes($pollId, (int)$_SESSION['user_id']);
        }
        sendResponse(true, "OK", 'success', $data);
    }
}
