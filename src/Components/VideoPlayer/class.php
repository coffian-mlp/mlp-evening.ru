<?php
namespace Components\VideoPlayer;

use Core\Component;
use Infra\ConfigManager;

class VideoPlayerComponent extends Component {
    public function executeComponent() {
        global $app;
        $config = ConfigManager::getInstance();
        $stream=\Domain\SelfHostedStream::settings();
        $this->result['self_hosted']=$stream['source']==='self_hosted'||(!empty($this->params['self_hosted_preview'])&&\Domain\Auth::isAdmin());
        $this->result['hls_url']=$stream['url'];
        $this->result['stream_url'] = $config->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
        $this->result['twitch'] = self::twitchOptions($this->result['stream_url']);
        if($this->result['self_hosted']){$app->addJs('/assets/vendor/hls/hls.min.js');$app->addJs('/assets/js/self-hosted-player.js');}
        $this->includeTemplate();
    }

    /** Recognize supported Twitch embeds and public channel/VOD URLs only. */
    public static function twitchOptions(string $source): ?array {
        $url = parse_url(trim($source));
        if (!$url || !in_array(strtolower($url['scheme'] ?? ''), ['https', 'http'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['port'])) return null;
        $host = strtolower($url['host'] ?? '');
        $query = [];
        parse_str($url['query'] ?? '', $query);
        if ($host === 'player.twitch.tv') {
            if (!in_array($url['path'] ?? '', ['', '/'], true)) return null;
        } elseif (in_array($host, ['twitch.tv', 'www.twitch.tv'], true)) {
            $path = $url['path'] ?? '';
            if (preg_match('#^/videos/(\d+)/?$#D', $path, $match)) $query['video'] = $match[1];
            elseif (preg_match('#^/([a-zA-Z0-9_]{1,25})/?$#D', $path, $match)
                && !in_array(strtolower($match[1]), ['directory','downloads','settings','subscriptions','wallet','inventory','videos'], true)) $query['channel'] = $match[1];
            else return null;
        } else return null;
        $options = [];
        foreach (['channel'=>'/^[a-zA-Z0-9_]{1,25}$/D', 'video'=>'/^v?\d+$/D', 'collection'=>'/^[a-zA-Z0-9_-]{1,128}$/D'] as $key=>$pattern) {
            if (is_string($query[$key] ?? null) && preg_match($pattern, $query[$key])) $options[$key] = $query[$key];
        }
        if (!$options) return null;
        if (isset($options['channel'])) unset($options['video'], $options['collection']);
        foreach (['autoplay','muted'] as $key) {
            if (is_string($query[$key] ?? null) && in_array(strtolower($query[$key]), ['true','false','1','0'], true)) $options[$key] = in_array(strtolower($query[$key]), ['true','1'], true);
        }
        if (is_string($query['time'] ?? null) && preg_match('/^(?:\d+h)?(?:\d+m)?(?:\d+s)?$/D', $query['time']) && $query['time'] !== '') $options['time'] = $query['time'];
        $parents = [];
        foreach (explode('&', $url['query'] ?? '') as $part) {
            $pair = explode('=', $part, 2);
            if (!in_array(urldecode($pair[0]), ['parent','parent[]'], true)) continue;
            $parent = urldecode($pair[1] ?? '');
            if (preg_match('/^[a-zA-Z0-9.-]+$/D', $parent)) $parents[] = $parent;
        }
        $options['parent'] = array_values(array_unique($parents));
        return $options;
    }
}
