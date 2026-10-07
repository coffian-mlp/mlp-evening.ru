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
            $user = $payload['messages'][array_key_last($payload['messages'])]['content'] ?? '';
            $stage = isset($payload['plugins']) ? 'search' : (str_contains($system, 'Независимо проверь') ? 'verify' : (str_contains($system, 'Convert the supplied original_query') ? 'normalize' : 'live'));
            $GLOBALS['mlp364_trace'][] = ['stage' => $stage, 'user' => $user, 'messages' => $payload['messages'], 'timeout' => $GLOBALS['mlp363_options'][CURLOPT_TIMEOUT] ?? null];
            if (isset($GLOBALS['mlp364_on_transport'])) ($GLOBALS['mlp364_on_transport'])($stage);
            if ($stage === 'search' && ($GLOBALS['mlp364_scenario'] ?? '') === 'error') return false;
            $id = (int)($GLOBALS['mlp364_target'] ?? 0);
            $text = match ($stage) {
                'normalize' => json_encode(['version'=>1,'intent'=>'plot','search_query'=>($GLOBALS['mlp364_scenario'] ?? '') === 'firstappearance' ? 'Lyra Heartstrings first appearance' : 'Rarity scene dragon smoke']),
                'search' => json_encode(['candidates' => in_array($GLOBALS['mlp364_scenario'] ?? '', ['noresults','quoted-noresults','bad-noresults'], true) ? [] : [['episode_id' => $id, 'evidence' => 'Fixture plot evidence', 'source_url' => 'https://example.org/fixture']]]),
                'verify' => json_encode(['verified' => [$id]]),
                default => 'Уточни описание и ответь с цитатой на моё сообщение.',
            };
            if ($stage === 'live' && str_contains($user, 'Доступный вариант:')) $text = 'Выбирай подходящий вариант кнопкой — этот выбор за тобой!';
            if ($stage === 'live' && str_contains($user, 'Выбор делает пользователь')) $text = 'Выбирай подходящий вариант кнопкой — этот выбор за тобой!';
            if ($stage === 'live' && str_contains($user, 'Пользователь отменил выбор')) $text = 'Хорошо, выбор отменён; пожелания не изменились.';
            if ($stage === 'live' && preg_match('/Номер эпизода: (\d+)\nПолное название эпизода: (.+)\nКоличество доступных пожеланий сегодня: (\d+)/u', $user, $facts)) $text = '№' . $facts[1] . ' — ' . $facts[2] . '. Осталось ' . $facts[3] . '.';
            if ($stage === 'live' && ($GLOBALS['mlp364_scenario'] ?? '') === 'quoted-noresults') $text = 'Не удалось подтвердить подходящий эпизод. Ответь с цитатой на моё сообщение: "вспомни сцену" & опиши детали. Можно нажать "Передумал".';
            if ($stage === 'live' && ($GLOBALS['mlp364_scenario'] ?? '') === 'bad-noresults') $text = 'Пока никто не отозвался. Если кто-то вспомнит момент, пусть ответит с цитатой в формате "Уточнение: …". Можно нажать "Передумал".';
            if ($stage === 'live' && ($GLOBALS['mlp364_scenario'] ?? '') === 'quoted-found' && str_contains($user, 'Доступный вариант:')) $text = 'Выбери вариант №' . $id . ' кнопкой "подтвердить" — решение за тобой & пожелание пока не записано.';
            if (($GLOBALS['mlp364_scenario'] ?? '') === 'firstappearance') {
                if ($stage === 'search') $text=json_encode(['candidates'=>[['episode_code'=>'S01E01','title'=>'Mare in the Moon','evidence'=>'Lyra first appears in the background of the first episode','source_url'=>'https://example.org/fixture']]]);
                if ($stage === 'live' && str_contains($user,'Доступный вариант:')) $text='Я появляюсь уже в первой серии, но фоном. Выбери её кнопкой, или уточни, если интересует первое заметное участие — решение за тобой.';
            }
            $ratingMode=$GLOBALS['mlp364_scenario'] ?? '';
            $ratingUrl='https://www.imdb.com/title/tt1751105/episodes/?topRated=DESC';
            if (str_starts_with($ratingMode,'rating-')) {
                $worst = $ratingMode === 'rating-worst'; $direction = $worst ? 'worst' : 'best';
                $intent = ['version'=>2, 'intent'=>'rating', 'direction'=>$direction, 'selection'=>'extreme', 'metric'=>'mean_score',
                    'requested_source'=>$ratingMode==='rating-source' ? 'Rotten Tomatoes' : null,
                    'scope_constraints'=>str_contains($user,'Лир') ? ['Lyra appears'] : [], 'search_query'=>$worst ? 'lowest rated Lyra episodes' : 'highest rated episodes'];
                if ($ratingMode === 'rating-polar-missing') { $intent['direction']='polarized'; $intent['metric']='standard_deviation'; $intent['selection']='qualifying'; }
                if ($ratingMode === 'rating-polar') { $intent['direction']='polarized'; $intent['metric']='polarization'; $intent['selection']='qualifying'; }
                if ($stage === 'normalize') $text = json_encode($intent);
                // WEB verifies plot membership only. IMDb score/order always comes from the real local importer/SQL.
                if ($stage === 'search') $text = json_encode(['candidates'=>array_map(static fn($r)=>[
                    'episode_id'=>$r['id'], 'evidence'=>'Lyra appears in this fixture scene', 'source_url'=>$ratingUrl], $GLOBALS['mlp365_rating_rows'])]);
                if ($stage === 'verify') $text = json_encode(['verified'=>array_column($GLOBALS['mlp365_rating_rows'],'id')]);
                if ($stage === 'live' && str_contains($user,'Источник оценок:')) {
                    $text = ''; // Deterministic provider decline exercises the existing factual fallback.
                }
            }
            $message = ['content' => $text];
            if ($stage === 'search') $message['annotations'] = [['url_citation' => ['url' => str_starts_with($ratingMode,'rating-')?$ratingUrl:'https://example.org/fixture', 'title' => 'Fixture evidence']]];
            return json_encode(['choices' => [['finish_reason' => 'stop', 'message' => $message]]]);
        }
        return json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'Жми на кнопочку под ответом — этот выбор за тобой!']]]]);
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}
