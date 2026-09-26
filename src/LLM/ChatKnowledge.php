<?php

namespace LLM;

use Domain\UserManager;
use Infra\ConfigManager;

/**
 * Знания Лиры о чате (MLP-349, прод-беклог №22 и №24): правила чата и роли участников.
 * Блок дописывается в конец промпта персоны (LLMManager::personaPrompt) — стабильная часть
 * промпта, не зависит от беседы.
 *
 * Правила — настройка дашборда ai_chat_rules (пусто = без блока); та же настройка подставляется
 * в промпт команды /правила через плейсхолдер {rules} — один источник истины.
 * Роли — users.role (admin/moderator) + владелец сайта ai_owner_user_id (0 = не задан).
 */
final class ChatKnowledge {
    /** Плейсхолдер правил в промпте текстовой команды (/правила). */
    public const RULES_PLACEHOLDER = '{rules}';

    /** Pure: блок правил для промпта персоны; пустые правила → null. */
    public static function rulesBlock(string $rules): ?string {
        $rules = trim($rules);
        if ($rules === '') {
            return null;
        }
        return "[Правила чата]: их установили админы, ты их знаешь как участница чата. "
            . "Вспоминай правила к месту — когда о них спрашивают или разговор их касается; не цитируй их без повода "
            . "и не обвиняй участников в нарушениях: решают админы и модераторы.\n" . $rules;
    }

    /**
     * Pure: строка ролей. $users — строки users (id, nickname, login, role); учитываются admin и moderator.
     * Владелец ($ownerId > 0) идёт первым с пометкой «владелец сайта» и своей ролью; бот исключается.
     * Нет ни персонала, ни владельца → null.
     */
    public static function rolesLine(array $users, int $ownerId, int $botId): ?string {
        $owner = null;
        $admins = [];
        $moderators = [];
        foreach ($users as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id <= 0 || $id === $botId) {
                continue;
            }
            $name = trim((string)($u['nickname'] ?? '')) ?: trim((string)($u['login'] ?? ''));
            if ($name === '') {
                continue;
            }
            $role = (string)($u['role'] ?? 'user');
            if ($ownerId > 0 && $id === $ownerId) {
                $owner = ['name' => $name, 'role' => $role];
                continue;
            }
            if ($role === 'admin') {
                $admins[] = $name;
            } elseif ($role === 'moderator') {
                $moderators[] = $name;
            }
        }

        $parts = [];
        if ($owner !== null) {
            $suffix = ['admin' => ' и админ', 'moderator' => ' и модератор'][$owner['role']] ?? '';
            $parts[] = $owner['name'] . ' — владелец сайта' . $suffix;
        }
        if ($admins) {
            $parts[] = implode(', ', $admins) . (count($admins) > 1 ? ' — админы' : ' — админ');
        }
        if ($moderators) {
            $parts[] = implode(', ', $moderators) . (count($moderators) > 1 ? ' — модераторы' : ' — модератор');
        }
        if (!$parts) {
            return null;
        }
        return '[Роли в чате]: ' . implode('; ', $parts) . '. '
            . 'Админы управляют сайтом, его настройками и твоей памятью; админы и модераторы следят за порядком в чате '
            . '(муты, баны, удаление сообщений). Остальные — обычные участники.';
    }

    /**
     * Pure: промпт текстовой команды с подставленными правилами. Без плейсхолдера — как есть.
     * Правила не заданы → честная пометка вместо пустоты (иначе модель сочинит правила сама).
     */
    public static function withRules(string $commandPrompt, string $rules): string {
        if (strpos($commandPrompt, self::RULES_PLACEHOLDER) === false) {
            return $commandPrompt;
        }
        $rules = trim($rules);
        return str_replace(self::RULES_PLACEHOLDER, $rules !== '' ? $rules : '(правила пока не заданы — так и скажи)', $commandPrompt);
    }

    /** Pure: блок знаний целиком (правила, затем роли); оба пусты → null. */
    public static function block(?string $rulesBlock, ?string $rolesLine): ?string {
        $parts = array_values(array_filter([$rulesBlock, $rolesLine], static fn($p) => $p !== null && $p !== ''));
        return $parts ? implode("\n\n", $parts) : null;
    }

    /** Текст правил из дашборда (ai_chat_rules). */
    public static function rulesText(): string {
        return trim((string)ConfigManager::getInstance()->getOption('ai_chat_rules', ''));
    }

    /** Живой блок для промпта персоны: правила из дашборда + роли из users (кеш списка — 5 минут). */
    public static function forPersona(int $botId): ?string {
        $ownerId = (int)ConfigManager::getInstance()->getOption('ai_owner_user_id', 0);
        $users = (new UserManager())->getAllUsers();
        return self::block(
            self::rulesBlock(self::rulesText()),
            self::rolesLine(is_array($users) ? $users : [], $ownerId, $botId)
        );
    }
}
