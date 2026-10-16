<?php
namespace Domain;

use Infra\Database;
use Infra\Transaction;

/** First-party, consent-gated UX measurements. No raw UA, account ID or IP. */
final class UxStatistics
{
    public const DIMENSIONS = [
        'page'=>'Страницы', 'device'=>'Устройства', 'browser'=>'Браузеры', 'os'=>'Системы',
        'screen'=>'Экран, CSS px', 'viewport'=>'Область страницы, CSS px', 'density'=>'Плотность пикселей (DPR)',
        'pinch_scale'=>'Увеличение жестом', 'font_scale'=>'Масштаб шрифта сайта, %', 'theme'=>'Тема',
        'load'=>'Загрузка страницы', 'chat_error'=>'Ошибка соединения чата', 'player_error'=>'Ошибка плеера',
        'overflow'=>'Горизонтальное переполнение',
    ];

    public static function browser(string $ua): array
    {
        $browser = 'Другой';
        foreach (['Edge'=>'Edg(?:A|iOS)?', 'Opera'=>'OPR', 'Firefox'=>'(?:Firefox|FxiOS)',
            'Chrome'=>'(?:Chrome|CriOS)', 'Safari'=>'Version'] as $name=>$pattern) {
            if (preg_match('~' . $pattern . '/(\d{1,3})~', $ua, $m)) { $browser = $name . ' ' . $m[1]; break; }
        }
        $os = match (true) {
            (bool)preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS / iPadOS',
            str_contains($ua, 'Android') => 'Android', str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'CrOS') => 'ChromeOS', str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux', default => 'Другая',
        };
        $device = preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua) ? 'Планшет'
            : (preg_match('/Mobile|iPhone|iPod/i', $ua) ? 'Телефон' : 'Компьютер');
        return compact('browser','os','device');
    }

    public static function size(mixed $width, mixed $height): string
    {
        $w = is_numeric($width) ? (float)$width : 0; $h = is_numeric($height) ? (float)$height : 0;
        if (!is_finite($w) || !is_finite($h) || $w < 1 || $h < 1 || $w > 20000 || $h > 20000) return 'Неизвестно';
        return max(100, (int)round($w / 100) * 100) . ' × ' . max(100, (int)round($h / 100) * 100);
    }

    public static function normalize(array $data, string $ua): array
    {
        $path = $data['path'] ?? '/';
        $paths = ['/','/episodes.php','/schedule.php','/login.php','/register.php','/chat_popup.php','/profile.php','/privacy.php','/dashboard/','/reset_password.php'];
        if (!is_string($path) || !in_array($path, $paths, true)) $path = 'Другая страница';
        $number = static function ($value, float $min, float $max, float $step, $unknown = null) {
            if (!is_numeric($value) || !is_finite((float)$value) || $value < $min || $value > $max) return $unknown;
            return round((float)$value / $step) * $step;
        };
        return self::browser(substr($ua, 0, 1000)) + [
            'path'=>$path, 'screen'=>self::size($data['screen_w'] ?? null, $data['screen_h'] ?? null),
            'viewport'=>self::size($data['width'] ?? null, $data['height'] ?? null),
            'density'=>$number($data['density'] ?? null, .5, 8, .25, 1),
            'pinch_scale'=>$number($data['pinch_scale'] ?? null, .5, 8, .25),
            'font_scale'=>(int)$number($data['font_scale'] ?? null, 50, 150, 5, 100),
            'theme'=>in_array($data['theme'] ?? '', ['luna','celestia'], true) ? $data['theme'] : 'Неизвестно',
            'load_ms'=>$number($data['load_ms'] ?? null, 0, 60000, 100),
            'chat_error'=>(int)(($data['chat_error'] ?? '') === '1'),
            'player_error'=>(int)(($data['player_error'] ?? '') === '1'),
            'overflow'=>(int)(($data['overflow'] ?? '') === '1'),
        ];
    }

    public static function record(array $data): bool
    {
        $hash = Privacy::tokenHash();
        if (!$hash || !preg_match('/^[a-f0-9]{32}$/D', (string)($data['page_id'] ?? ''))) return false;
        Privacy::lock('privacy-choice-' . substr($hash, 0, 40));
        if (!Privacy::choices()['analytics'] || (Auth::userId() && Privacy::isWithdrawn(Auth::userId()))) return false;
        $rate=$_SESSION['ux_rate'] ?? ['minute'=>0,'count'=>0];
        if ($rate['minute'] !== (int)(time()/60)) $rate=['minute'=>(int)(time()/60),'count'=>0];
        if ($rate['count']>=45) return false;
        $rate['count']++;$_SESSION['ux_rate']=$rate;
        // Back/forward, resize and error reports update the same page; they are not new views.
        $now = time(); $visit = $_SESSION['ux_visit'] ?? null;
        if (!$visit || $visit['seen'] < $now - 1800 || $visit['day'] !== gmdate('Y-m-d')) {
            $visit = ['id'=>bin2hex(random_bytes(16)), 'day'=>gmdate('Y-m-d')];
        }
        $visit['seen'] = $now; $_SESSION['ux_visit'] = $visit;
        $n = self::normalize($data, $_SERVER['HTTP_USER_AGENT'] ?? '');
        $pageKey=substr(hash('sha256',$data['page_id'].$visit['id']),0,32);
        $values = [$pageKey,$visit['id'],$hash,$n['path'],$n['device'],$n['browser'],$n['os'],$n['screen'],$n['viewport'],
            $n['density'],$n['pinch_scale'],$n['font_scale'],$n['theme'],$n['load_ms'],$n['chat_error'],$n['player_error'],$n['overflow']];
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('INSERT INTO ux_pageviews(page_id,visit_id,consent_hash,created_at,updated_at,path,device,browser,os,screen,viewport,density,pinch_scale,font_scale,theme,load_ms,chat_error,player_error,overflow) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE viewport=IF(consent_hash=VALUES(consent_hash),VALUES(viewport),viewport),theme=IF(consent_hash=VALUES(consent_hash),VALUES(theme),theme),font_scale=IF(consent_hash=VALUES(consent_hash),VALUES(font_scale),font_scale),pinch_scale=IF(consent_hash=VALUES(consent_hash),VALUES(pinch_scale),pinch_scale),density=IF(consent_hash=VALUES(consent_hash),VALUES(density),density),load_ms=IF(consent_hash=VALUES(consent_hash),COALESCE(load_ms,VALUES(load_ms)),load_ms),chat_error=IF(consent_hash=VALUES(consent_hash),GREATEST(chat_error,VALUES(chat_error)),chat_error),player_error=IF(consent_hash=VALUES(consent_hash),GREATEST(player_error,VALUES(player_error)),player_error),overflow=IF(consent_hash=VALUES(consent_hash),VALUES(overflow),overflow),updated_at=IF(consent_hash=VALUES(consent_hash),UTC_TIMESTAMP(),updated_at)');
        $stmt->bind_param('sssssssssddisiiii', ...$values); $stmt->execute();
        return true;
    }

    private static function expression(string $dimension): string
    {
        return match ($dimension) {
            'overall'=>"'all'", 'page'=>'path',
            'load'=>"CASE WHEN load_ms IS NULL THEN 'Неизвестно' WHEN load_ms<1000 THEN 'До 1 с' WHEN load_ms<3000 THEN '1–3 с' WHEN load_ms<5000 THEN '3–5 с' ELSE 'Более 5 с' END",
            'chat_error','player_error','overflow'=>"IF($dimension=1,'Да','Нет')",
            default=>$dimension,
        };
    }

    public static function maintain(): void
    {
        $db = Database::getInstance()->getConnection();
        Transaction::run($db, static function () use ($db): void {
            // Only rolled-up days are removed. Re-running the worker is idempotent.
            foreach (['overall'=>''] + self::DIMENSIONS as $dimension=>$unused) {
                $expr = self::expression($dimension);
                $db->query("INSERT INTO ux_daily(day,dimension,label,views,visits) SELECT DATE(created_at),'$dimension',COALESCE(CAST($expr AS CHAR),'Неизвестно'),COUNT(*),COUNT(DISTINCT visit_id) FROM ux_pageviews WHERE created_at<UTC_DATE()-INTERVAL 29 DAY GROUP BY DATE(created_at),$expr ON DUPLICATE KEY UPDATE views=VALUES(views),visits=VALUES(visits)");
            }
            $db->query('DELETE FROM ux_pageviews WHERE created_at<UTC_DATE()-INTERVAL 29 DAY');
            $db->query('DELETE FROM ux_daily WHERE day<UTC_DATE()-INTERVAL 365 DAY');
            $db->query('DELETE FROM ux_peaks WHERE day<UTC_DATE()-INTERVAL 365 DAY');
        });
    }

    public static function dashboard(int $days): array
    {
        $days = in_array($days, [7,30,90,365], true) ? $days : 30;
        $db = Database::getInstance()->getConnection(); $series = []; $groups = [];
        foreach (['overall'=>''] + self::DIMENSIONS as $dimension=>$unused) {
            $expr = self::expression($dimension);
            $sql = "SELECT day,label,SUM(views) views,SUM(visits) visits FROM (
                SELECT day,label,views,visits FROM ux_daily WHERE dimension='$dimension' AND day>=UTC_DATE()-INTERVAL " . ($days-1) . " DAY
                UNION ALL SELECT DATE(created_at),COALESCE(CAST($expr AS CHAR),'Неизвестно'),COUNT(*),COUNT(DISTINCT visit_id) FROM ux_pageviews WHERE created_at>=UTC_DATE()-INTERVAL " . ($days-1) . " DAY GROUP BY DATE(created_at),$expr
            ) totals GROUP BY day,label ORDER BY day";
            $result = $db->query($sql); $group = [];
            while ($row = $result->fetch_assoc()) {
                $label = $row['label']; $group[$label] = ($group[$label] ?? 0) + (int)$row['views'];
                if ($dimension === 'overall') $series[] = ['day'=>$row['day'],'views'=>(int)$row['views'],'visits'=>(int)$row['visits']];
            }
            arsort($group); $groups[$dimension] = $group;
        }
        $peak=(int)$db->query('SELECT COALESCE(MAX(peak),0) peak FROM ux_peaks WHERE day>=UTC_DATE()-INTERVAL '.($days-1).' DAY')->fetch_assoc()['peak'];
        return ['days'=>$days,'series'=>$series,'groups'=>$groups,'views'=>array_sum(array_column($series,'views')),'visits'=>array_sum(array_column($series,'visits')),'peak'=>$peak];
    }

    /** Reuse the existing presence heartbeat; no additional frequent browser requests. */
    public static function touch(): void
    {
        $hash=Privacy::tokenHash(); $visit=$_SESSION['ux_visit']['id'] ?? null;
        if (!$hash || !$visit || !Privacy::choices()['analytics'] || ($_POST['ux_visible']??'')!=='1' || ($_SESSION['ux_visit']['seen']??0)<time()-1800) return;
        $_SESSION['ux_visit']['seen']=time();
        $db=Database::getInstance()->getConnection();
        $stmt=$db->prepare('UPDATE ux_pageviews SET updated_at=UTC_TIMESTAMP() WHERE consent_hash=? AND visit_id=?');
        $stmt->bind_param('ss',$hash,$visit);$stmt->execute();
        $db->query('INSERT INTO ux_peaks(day,peak) SELECT UTC_DATE(),COUNT(DISTINCT visit_id) FROM ux_pageviews WHERE updated_at>UTC_TIMESTAMP()-INTERVAL 3 MINUTE ON DUPLICATE KEY UPDATE peak=GREATEST(peak,VALUES(peak))');
    }

    public static function forgetBrowser(string $hash): void {
        $db=Database::getInstance()->getConnection();$stmt=$db->prepare('DELETE FROM ux_pageviews WHERE consent_hash=?');$stmt->bind_param('s',$hash);$stmt->execute();
    }
}
