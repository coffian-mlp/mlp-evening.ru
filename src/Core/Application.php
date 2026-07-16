<?php
namespace Core;

class Application {
    private static $instance = null;
    
    private $css = [];
    private $js = [];
    private $strings = [];
    private $title = 'MLP-evening.ru';
    
    private function __construct() {}
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function setTitle($title) {
        $this->title = $title;
    }
    
    public function getTitle() {
        return $this->title;
    }
    
    public function addCss($path) {
        if (!in_array($path, $this->css)) {
            $this->css[] = $path;
        }
    }
    
    public function addJs($path) {
        if (!in_array($path, $this->js)) {
            $this->js[] = $path;
        }
    }
    
    public function addString($str) {
        $this->strings[] = $str;
    }
    
    // Метод для подключения компонента
    public function includeComponent($componentName, $templateName = 'default', $params = []) {
        // Путь к компоненту: src/Components/Name/class.php
        $componentPath = $_SERVER['DOCUMENT_ROOT'] . '/src/Components/' . $componentName;
        $classFile = $componentPath . '/class.php';
        
        if (file_exists($classFile)) {
            require_once $classFile;
            
            // Имя класса: Components\Chat\ChatComponent
            // Преобразуем имя папки в Namespace (Chat -> Components\Chat\ChatComponent)
            $className = "\\Components\\{$componentName}\\{$componentName}Component";
            
            if (class_exists($className)) {
                $component = new $className($componentName, $templateName, $params);
                $component->executeComponent();
            } else {
                echo "<!-- Component class $className not found -->";
            }
        } else {
             echo "<!-- Component file $classFile not found -->";
        }
    }
    
    public function showHead() {
        echo '<!-- APP_HEAD_STRINGS -->';
    }
    
    /**
     * Версия ассета для cache-busting: mtime файла вместо time() (A10).
     * time() менял query на каждый запрос → браузерный кеш был фактически выключен.
     * mtime меняется только при изменении файла — кеш работает, но сбрасывается при деплое.
     */
    private function assetVersion($path) {
        $file = ($_SERVER['DOCUMENT_ROOT'] ?? '') . $path;
        return is_file($file) ? filemtime($file) : time();
    }

    public function finalize() {
        $content = ob_get_clean();

        $headHtml = '';
        // Вывод CSS
        foreach ($this->css as $path) {
            $headHtml .= '<link rel="stylesheet" href="' . $path . '?v=' . $this->assetVersion($path) . '">' . PHP_EOL;
        }
        
        // Вывод произвольных строк
        foreach ($this->strings as $str) {
            $headHtml .= $str . PHP_EOL;
        }
        
        echo str_replace('<!-- APP_HEAD_STRINGS -->', $headHtml, $content);
    }
    
    public function showFooterScripts() {
        foreach ($this->js as $path) {
            echo '<script src="' . $path . '?v=' . $this->assetVersion($path) . '"></script>' . PHP_EOL;
        }
    }
}
