<?php
use Infra\Database;
use Infra\ConfigManager;


class EpisodeManager {
    private $db;
    private $cacheFile;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->cacheFile = __DIR__ . '/../cache/episodes.json';
    }

    private function clearCache() {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function getEveningPlaylist($limit = 8) {
        $saved = $this->getSavedPlaylist();
        if ($saved) {
            return $saved;
        }
        return $this->regeneratePlaylist($limit);
    }

    public function getSavedPlaylist() {
        $row = ConfigManager::getInstance()->getOptionDetails('current_playlist');
        
        if ($row) {
            $data = json_decode($row['value'], true);
            if (is_array($data)) {
                $data['_meta'] = [
                    'updated_at' => $row['updated_at'],
                    'is_old' => (strtotime($row['updated_at']) < strtotime('-7 days'))
                ];
                return $data;
            }
        }
        return null;
    }

    public function regeneratePlaylist($limit = 8) {
        // Генерируем новый плейлист с учетом весов и лимитов
        $playlist = $this->generateWeightedPlaylist($limit);
        
        $json = json_encode($playlist);
        ConfigManager::getInstance()->setOption('current_playlist', $json);
        
        $playlist['_meta'] = [
            'updated_at' => date('Y-m-d H:i:s'),
            'is_old' => false
        ];
        
        return $playlist;
    }

    /**
     * 🧠 Баклажановая магия: Умная генерация плейлиста
     * Использует взвешенный рандом и группировку историй.
     */
    private function generateWeightedPlaylist($timeLimit) {
        // 1. Получаем ВООБЩЕ ВСЕ эпизоды, чтобы иметь полный контекст (для двусерийников)
        // Их всего ~250 штук, так что по памяти это копейки.
        $query = "SELECT ID, TITLE, TWOPART_ID, LENGTH, WANNA_WATCH, TIMES_WATCHED FROM episode_list";
        $result = $this->db->query($query);
        $allEpisodes = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $allEpisodes[$row['ID']] = $row;
            }
            $result->free();
        }

        // 2. Группируем в "Истории" (Stories)
        // Идем только по тем, которые можно смотреть (не просмотрены или очень желанны)
        $stories = [];
        $processedIds = []; 

        foreach ($allEpisodes as $id => $ep) {
            // Пропускаем уже обработанные (например, вторые части, которые мы прицепили к первым)
            if (in_array($id, $processedIds)) continue;

            // Критерий отбора кандидата:
            // Либо ни разу не смотрели, либо есть голоса "хочу посмотреть"
            if ($ep['TIMES_WATCHED'] > 0 && $ep['WANNA_WATCH'] == 0) {
                continue;
            }

            $story = [
                'ids' => [(int)$ep['ID']],
                'titles' => [$ep['TITLE']],
                'length' => (int)$ep['LENGTH'], // Длина первой части
                'weight' => 10,
            ];

            if ($ep['WANNA_WATCH'] > 0) {
                $story['weight'] += ($ep['WANNA_WATCH'] * 5);
            }

            // Обработка связей (Двусерийники)
            if (!empty($ep['TWOPART_ID']) && $ep['TWOPART_ID'] != $ep['ID']) {
                $part2Id = $ep['TWOPART_ID'];
                
                // Ищем вторую часть в полном списке
                if (isset($allEpisodes[$part2Id])) {
                    $part2 = $allEpisodes[$part2Id];
                    
                    // Добавляем вторую часть в историю
                    $story['ids'][] = (int)$part2['ID'];
                    
                    // Важно: берем реальное название второй части!
                    $story['titles'][] = $part2['TITLE']; 
                    
                    // Увеличиваем длину истории
                    // В старом коде длина бралась из первой части, если она была = 2.
                    // Если в базе у частей длина по 1, то надо суммировать.
                    // Если у первой части длина уже прописана как 2 (как у "To Where and Back Again"), то не надо.
                    // Но судя по твоему логу, у "To Where and Back Again" ID 142.
                    // Давай будем умнее: если мы добавили вторую часть, длина истории должна быть суммой длин, 
                    // ИЛИ если в базе у первой части уже стоит 2, то верим базе.
                    // Давай предположим худшее: в базе каша.
                    // Безопасный вариант: считаем количество частей = длине.
                    $story['length'] = count($story['ids']); 

                    // Учитываем голоса второй части
                    if ($part2['WANNA_WATCH'] > 0) {
                        $story['weight'] += ($part2['WANNA_WATCH'] * 5);
                    }
                    
                    $processedIds[] = $part2Id; // Метим вторую часть как обработанную
                }
            }

            // Если вдруг история получилась длиннее лимита (что вряд ли), пропускаем
            if ($story['length'] > $timeLimit) continue;

            $processedIds[] = $id;
            $stories[] = $story;
        }

        // 3. Наполняем рюкзак (Плейлист)
        $finalPlaylist = [];
        $currentLength = 0;

        // Пока есть место в эфире
        while ($currentLength < $timeLimit) {
            $remainingSpace = $timeLimit - $currentLength;

            // 3.1 Фильтруем: оставляем только те истории, которые влезают в остаток времени
            $candidates = array_filter($stories, function($s) use ($remainingSpace) {
                return $s['length'] <= $remainingSpace;
            });

            // Если ничего не влезает - останавливаемся
            if (empty($candidates)) {
                break;
            }

            // 3.2 Выбираем случайную историю с учетом веса (Weighted Random)
            $selectedKey = $this->pickWeightedStory($candidates);
            $selectedStory = $candidates[$selectedKey];

            // 3.3 Добавляем в плейлист
            $finalPlaylist[] = $selectedStory;
            $currentLength += $selectedStory['length'];

            // 3.4 Удаляем выбранную историю из общего пула (чтобы не повторилась)
            // Важно удалить из основного массива $stories, а не из копии $candidates
            unset($stories[$selectedKey]);
        }

        return $finalPlaylist;
    }

    /**
     * 🎲 Помощник: Выбор элемента на основе веса
     */
    private function pickWeightedStory($candidates) {
        $totalWeight = 0;
        foreach ($candidates as $story) {
            $totalWeight += $story['weight'];
        }

        $rand = mt_rand(1, $totalWeight);
        
        foreach ($candidates as $key => $story) {
            $rand -= $story['weight'];
            if ($rand <= 0) {
                return $key;
            }
        }
        
        // На случай ядерной войны возвращаем первый ключ
        return array_key_first($candidates);
    }

    public function getAllEpisodes() {
        // 1. Try Cache (TTL 30 days)
        if (file_exists($this->cacheFile)) {
            if (time() - filemtime($this->cacheFile) < 2592000) { // 30 * 24 * 60 * 60
                $json = file_get_contents($this->cacheFile);
                if ($json) {
                    $data = json_decode($json, true);
                    if (is_array($data)) return $data;
                }
            }
        }

        // 2. DB Query
        $query = "SELECT * FROM episode_list";
        $result = $this->db->query($query);
        $episodes = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $episodes[] = $row;
            }
        }

        // 3. Save Cache
        if (!is_dir(dirname($this->cacheFile))) {
            mkdir(dirname($this->cacheFile), 0777, true);
        }
        file_put_contents($this->cacheFile, json_encode($episodes));

        return $episodes;
    }

    public function getWatchHistory($limit = 100) {
        $query = "SELECT ID, EPNUM, TITLE FROM watching_now ORDER BY ID DESC LIMIT " . (int)$limit;
        $result = $this->db->query($query);
        $history = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
        }
        return $history;
    }

    public function voteForEpisode($id) {
        $this->clearCache();
        $id = (int)$id;
        $stmt = $this->db->prepare("UPDATE episode_list SET WANNA_WATCH = WANNA_WATCH + 1 WHERE ID = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $stmtCheck = $this->db->prepare("SELECT TWOPART_ID FROM episode_list WHERE ID = ?");
        $stmtCheck->bind_param("i", $id);
        $stmtCheck->execute();
        $result = $stmtCheck->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            if (!empty($row['TWOPART_ID'])) {
                $twopartId = (int)$row['TWOPART_ID'];
                $stmtPart2 = $this->db->prepare("UPDATE episode_list SET WANNA_WATCH = WANNA_WATCH + 1 WHERE ID = ?");
                $stmtPart2->bind_param("i", $twopartId);
                $stmtPart2->execute();
                $stmtPart2->close();
            }
        }
        $stmtCheck->close();
        return true;
    }

    public function markAsWatched(array $ids) {
        $this->clearCache();
        $stmtUpdate = $this->db->prepare("UPDATE episode_list SET TIMES_WATCHED = TIMES_WATCHED + 1 WHERE ID = ?");
        $stmtSelect = $this->db->prepare("SELECT TITLE FROM episode_list WHERE ID = ?");
        $stmtInsert = $this->db->prepare("INSERT INTO watching_now (EPNUM, TITLE) VALUES (?, ?)");

        foreach ($ids as $id) {
            $id = (int)$id;
            $stmtUpdate->bind_param("i", $id);
            $stmtUpdate->execute();
            
            $stmtSelect->bind_param("i", $id);
            $stmtSelect->execute();
            $res = $stmtSelect->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $title = $row['TITLE'];
                $stmtInsert->bind_param("is", $id, $title);
                $stmtInsert->execute();
            }
        }
        $stmtUpdate->close();
        $stmtSelect->close();
        $stmtInsert->close();
        return true;
    }
    
    public function clearWannaWatch() {
         $this->clearCache();
         return $this->db->query("UPDATE episode_list SET WANNA_WATCH = 0");
    }

    public function resetTimesWatched() {
         $this->clearCache();
         return $this->db->query("UPDATE episode_list SET TIMES_WATCHED = 0");
    }

    public function clearWatchingNowLog() {
         return $this->db->query("TRUNCATE TABLE watching_now");
    }
}