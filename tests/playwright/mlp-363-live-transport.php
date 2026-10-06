<?php
namespace LLM {
    /** External HTTP seam, loaded only by the isolated CLI live fixture. */
    function curl_init($url) { return new \stdClass(); }
    function curl_setopt($handle, $option, $value) { $GLOBALS['mlp363_options'][$option]=$value; return true; }
    function curl_exec($handle) {
        $GLOBALS['mlp363_calls']=($GLOBALS['mlp363_calls'] ?? 0)+1;
        $GLOBALS['mlp363_payload']=json_decode($GLOBALS['mlp363_options'][CURLOPT_POSTFIELDS],true);
        if (!empty($GLOBALS['mlp364_transport'])) {
            $payload = $GLOBALS['mlp363_payload'];
            $system = $payload['messages'][0]['content'] ?? '';
            $user = $payload['messages'][1]['content'] ?? '';
            $stage = isset($payload['plugins']) ? 'search' : (str_contains($system, 'Независимо проверь') ? 'verify' : (str_contains($system, 'Convert the supplied original_query') ? 'normalize' : 'live'));
            $GLOBALS['mlp364_trace'][] = ['stage' => $stage, 'user' => $user, 'timeout' => $GLOBALS['mlp363_options'][CURLOPT_TIMEOUT] ?? null];
            if (isset($GLOBALS['mlp364_on_transport'])) ($GLOBALS['mlp364_on_transport'])($stage);
            if ($stage === 'search' && ($GLOBALS['mlp364_scenario'] ?? '') === 'error') return false;
            $id = (int)($GLOBALS['mlp364_target'] ?? 0);
            $text = match ($stage) {
                'normalize' => 'Rarity scene dragon smoke',
                'search' => json_encode(['candidates' => ($GLOBALS['mlp364_scenario'] ?? '') === 'noresults' ? [] : [['episode_id' => $id, 'evidence' => 'Fixture plot evidence', 'source_url' => 'https://example.org/fixture']]]),
                'verify' => json_encode(['verified' => [$id]]),
                default => 'Уточни описание и ответь с цитатой на моё сообщение.',
            };
            if ($stage === 'live' && str_contains($user, 'Доступный вариант:')) $text = 'Выбирай подходящий вариант кнопкой — этот выбор за тобой!';
            if ($stage === 'live' && str_contains($user, 'Выбор делает пользователь')) $text = 'Выбирай подходящий вариант кнопкой — этот выбор за тобой!';
            if ($stage === 'live' && str_contains($user, 'Пользователь отменил выбор')) $text = 'Хорошо, выбор отменён; пожелания не изменились.';
            if ($stage === 'live' && preg_match('/Номер эпизода: (\d+)\nПолное название эпизода: (.+)\nКоличество доступных пожеланий сегодня: (\d+)/u', $user, $facts)) $text = '№' . $facts[1] . ' — ' . $facts[2] . '. Осталось ' . $facts[3] . '.';
            $message = ['content' => $text];
            if ($stage === 'search') $message['annotations'] = [['url_citation' => ['url' => 'https://example.org/fixture', 'title' => 'Fixture evidence']]];
            return json_encode(['choices' => [['finish_reason' => 'stop', 'message' => $message]]]);
        }
        return json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'Жми на кнопочку под ответом — этот выбор за тобой!']]]]);
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}
