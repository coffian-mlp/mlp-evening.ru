<?php

class CaptchaManager {
    private const SESSION_KEY = 'captcha_state';
    
    // Map: Pony Name => [Element, Image Filename]
    // Файлы переименованы для скрытности:
    // q_a1 = Twilight
    // q_b2 = Applejack
    // q_c3 = Rainbow Dash
    // q_d4 = Pinkie Pie
    // q_e5 = Rarity
    // q_f6 = Fluttershy
    private const PONIES = [
        'twilight' => ['name' => 'Твайлайт Спаркл', 'element' => 'magic', 'image' => 'q_a1.png'],
        'applejack' => ['name' => 'Эпплджек', 'element' => 'honesty', 'image' => 'q_b2.png'],
        'rainbow' => ['name' => 'Рэйнбоу Дэш', 'element' => 'loyalty', 'image' => 'q_c3.png'],
        'pinkie' => ['name' => 'Пинки Пай', 'element' => 'laughter', 'image' => 'q_d4.png'],
        'rarity' => ['name' => 'Рарити', 'element' => 'generosity', 'image' => 'q_e5.png'],
        'fluttershy' => ['name' => 'Флаттершай', 'element' => 'kindness', 'image' => 'q_f6.png']
    ];

    // Убраны эмодзи, чтобы не давать подсказок
    private const ELEMENTS = [
        'magic' => 'Магия',
        'honesty' => 'Честность',
        'loyalty' => 'Верность',
        'laughter' => 'Смех',
        'generosity' => 'Щедрость',
        'kindness' => 'Доброта'
    ];

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Initializes or resets the captcha process
     */
    public function start() {
        // Step 1: Text Question
        $ponyKey = array_rand(self::PONIES);
        
        $_SESSION[self::SESSION_KEY] = [
            'step' => 1,
            'completed' => false,
            'history' => [$ponyKey], // Keep track to avoid repeat
            'current_answer' => self::PONIES[$ponyKey]['element']
        ];

        return [
            'step' => 1,
            'type' => 'text',
            'question' => "Какой Элемент Гармонии представляет " . self::PONIES[$ponyKey]['name'] . "?",
            'options' => $this->getRandomOptions() // Shuffle options
        ];
    }

    /**
     * Validates answer and moves to next step
     */
    public function checkAnswer($answer) {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return ['success' => false, 'message' => 'Капча истекла. Начни заново!'];
        }

        $state = &$_SESSION[self::SESSION_KEY];
        
        // Check Answer
        if (is_array($state['current_answer'])) {
            // Case-insensitive check for manual input
            $userAnswer = mb_strtolower(trim($answer), 'UTF-8');
            if (!in_array($userAnswer, $state['current_answer'])) {
                unset($_SESSION[self::SESSION_KEY]);
                return ['success' => false, 'message' => 'Неверно! Попробуй еще раз сначала.'];
            }
        } else {
            // Strict check for option buttons
            if ($answer !== $state['current_answer']) {
                unset($_SESSION[self::SESSION_KEY]);
                return ['success' => false, 'message' => 'Неверно! Попробуй еще раз сначала.'];
            }
        }

        // Move to next step
        $state['step']++;

        // Step 2: Image Question
        if ($state['step'] === 2) {
            // Pick a pony NOT in history
            $availablePonies = array_diff_key(self::PONIES, array_flip($state['history']));
            $ponyKey = array_rand($availablePonies);
            
            $state['history'][] = $ponyKey;
            $state['current_answer'] = self::PONIES[$ponyKey]['element'];
            
            return [
                'success' => true,
                'next_step' => [
                    'step' => 2,
                    'type' => 'image',
                    'image_url' => '/assets/img/mane6/' . self::PONIES[$ponyKey]['image'],
                    'question' => "А кто эта пони? (Выбери её Элемент)",
                    'options' => $this->getRandomOptions() // Shuffle options
                ]
            ];
        }

        // Step 3: Spike Question (Manual Input)
        if ($state['step'] === 3) {
             $state['current_answer'] = ['spike', 'спайк', 'дракончик спайк', 'спайк дракончик'];
             
             return [
                'success' => true,
                'next_step' => [
                    'step' => 3,
                    'type' => 'input',
                    'question' => "Как зовут помощника номер один?",
                    // No options needed for input
                ]
            ];
        }

        // Finish
        if ($state['step'] > 3) {
            $state['completed'] = true;
            return ['success' => true, 'completed' => true];
        }
        
        return ['success' => false, 'message' => 'Unknown step'];
    }

    public function isCompleted() {
        return isset($_SESSION[self::SESSION_KEY]['completed']) && $_SESSION[self::SESSION_KEY]['completed'] === true;
    }

    /**
     * Resets the captcha state (e.g. after failed login attempts)
     */
    public function reset() {
        if (isset($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * Helper to shuffle options preserving keys
     */
    private function getRandomOptions() {
        $options = self::ELEMENTS;
        $keys = array_keys($options);
        shuffle($keys);
        $randomOptions = [];
        foreach ($keys as $key) {
            $randomOptions[$key] = $options[$key];
        }
        return $randomOptions;
    }
}
