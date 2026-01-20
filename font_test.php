<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Битва Шрифтов Эквестрии</title>
    
    <!-- Подключение кандидатов через Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Sans:ital,wght@0,400;0,600;1,400&family=Inter:wght@400;600&family=PT+Sans:ital,wght@0,400;0,700;1,400&family=Rubik:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    
    <!-- Текущие стили для контекста -->
    <link rel="stylesheet" href="assets/css/main.css">

    <style>
        body {
            background-color: #2b2b3b;
            color: #eee;
            padding: 20px;
            font-family: sans-serif; /* Временный фоллбек */
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        h1 {
            font-family: 'Philosopher', sans-serif;
            text-align: center;
            color: var(--accent-color);
            margin-bottom: 40px;
        }
        .font-card {
            background: rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .font-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .font-name {
            font-size: 1.5em;
            color: var(--focus-color);
        }
        .font-desc {
            color: var(--text-muted);
            font-style: italic;
        }
        .sample-block {
            line-height: 1.6;
            font-size: 16px; /* Как у нас в чате примерно */
        }
        .sample-small {
            font-size: 14px;
            color: #ccc;
            margin-top: 10px;
        }
        
        /* Специфические классы шрифтов */
        .font-opensans { font-family: 'Open Sans', sans-serif; } /* Текущий */
        .font-fira { font-family: 'Fira Sans', sans-serif; }
        .font-pt { font-family: 'PT Sans', sans-serif; }
        .font-rubik { font-family: 'Rubik', sans-serif; }
        .font-inter { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body>

<div class="container">
    <h1>Испытание Шрифтов</h1>
    
    <!-- 1. Open Sans (Current) -->
    <div class="font-card font-opensans">
        <div class="font-header">
            <span class="font-name">Open Sans (Текущий)</span>
            <span class="font-desc">Нейтральный, проверенный временем.</span>
        </div>
        <div class="sample-block">
            <p><strong>Принцесса Селестия писала:</strong> "Дорогая Твайлайт, дружба — это магия!"</p>
            <p>Съешь же ещё этих мягких французских булок, да выпей чаю. The quick brown fox jumps over the lazy dog.</p>
            <p><em>— Но почему аликорны должны нести такую ответственность? — спросила Свитти Белль, поправляя свою метку искателей.</em></p>
        </div>
        <div class="sample-small">Мелкий текст: 1234567890 | L l I i | O 0 | Или (Cyrillic) | Дд Лл Фф Жж</div>
    </div>

    <!-- 2. Fira Sans -->
    <div class="font-card font-fira">
        <div class="font-header">
            <span class="font-name">Fira Sans</span>
            <span class="font-desc">Четкий, технологичный, открытый (от Mozilla).</span>
        </div>
        <div class="sample-block">
            <p><strong>Принцесса Селестия писала:</strong> "Дорогая Твайлайт, дружба — это магия!"</p>
            <p>Съешь же ещё этих мягких французских булок, да выпей чаю. The quick brown fox jumps over the lazy dog.</p>
            <p><em>— Но почему аликорны должны нести такую ответственность? — спросила Свитти Белль, поправляя свою метку искателей.</em></p>
        </div>
        <div class="sample-small">Мелкий текст: 1234567890 | L l I i | O 0 | Или (Cyrillic) | Дд Лл Фф Жж</div>
    </div>

    <!-- 3. PT Sans -->
    <div class="font-card font-pt">
        <div class="font-header">
            <span class="font-name">PT Sans</span>
            <span class="font-desc">Родная кириллица, строгий и компактный.</span>
        </div>
        <div class="sample-block">
            <p><strong>Принцесса Селестия писала:</strong> "Дорогая Твайлайт, дружба — это магия!"</p>
            <p>Съешь же ещё этих мягких французских булок, да выпей чаю. The quick brown fox jumps over the lazy dog.</p>
            <p><em>— Но почему аликорны должны нести такую ответственность? — спросила Свитти Белль, поправляя свою метку искателей.</em></p>
        </div>
        <div class="sample-small">Мелкий текст: 1234567890 | L l I i | O 0 | Или (Cyrillic) | Дд Лл Фф Жж</div>
    </div>

    <!-- 4. Rubik -->
    <div class="font-card font-rubik">
        <div class="font-header">
            <span class="font-name">Rubik</span>
            <span class="font-desc">Округлый, мягкий, дружелюбный.</span>
        </div>
        <div class="sample-block">
            <p><strong>Принцесса Селестия писала:</strong> "Дорогая Твайлайт, дружба — это магия!"</p>
            <p>Съешь же ещё этих мягких французских булок, да выпей чаю. The quick brown fox jumps over the lazy dog.</p>
            <p><em>— Но почему аликорны должны нести такую ответственность? — спросила Свитти Белль, поправляя свою метку искателей.</em></p>
        </div>
        <div class="sample-small">Мелкий текст: 1234567890 | L l I i | O 0 | Или (Cyrillic) | Дд Лл Фф Жж</div>
    </div>

    <!-- 5. Inter -->
    <div class="font-card font-inter">
        <div class="font-header">
            <span class="font-name">Inter</span>
            <span class="font-desc">Современный стандарт интерфейсов.</span>
        </div>
        <div class="sample-block">
            <p><strong>Принцесса Селестия писала:</strong> "Дорогая Твайлайт, дружба — это магия!"</p>
            <p>Съешь же ещё этих мягких французских булок, да выпей чаю. The quick brown fox jumps over the lazy dog.</p>
            <p><em>— Но почему аликорны должны нести такую ответственность? — спросила Свитти Белль, поправляя свою метку искателей.</em></p>
        </div>
        <div class="sample-small">Мелкий текст: 1234567890 | L l I i | O 0 | Или (Cyrillic) | Дд Лл Фф Жж</div>
    </div>

</div>

</body>
</html>
