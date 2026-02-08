<?php
namespace Core;

abstract class Component {
    protected $componentName;
    protected $templateName;
    protected $params;
    protected $result;
    
    public function __construct($componentName, $templateName, $params) {
        $this->componentName = $componentName;
        $this->templateName = $templateName;
        $this->params = $params;
        $this->result = [];
    }
    
    abstract public function executeComponent();
    
    protected function includeTemplate($templatePage = 'template.php') {
        global $app;
        
        // Если шаблон не передан, используем дефолтный (теперь 'default' без точки)
        if (empty($this->templateName) || $this->templateName === '.default') {
            $this->templateName = 'default';
        }
        $templateFolder = $_SERVER['DOCUMENT_ROOT'] . '/src/Components/' . $this->componentName . '/templates/' . $this->templateName;
        
        // Автоматическое подключение стилей и скриптов шаблона
        if (file_exists($templateFolder . '/style.css')) {
            $app->addCss('/src/Components/' . $this->componentName . '/templates/' . $this->templateName . '/style.css');
        }
        
        if (file_exists($templateFolder . '/script.js')) {
            $app->addJs('/src/Components/' . $this->componentName . '/templates/' . $this->templateName . '/script.js');
        }
        
        // Подключаем сам шаблон
        $file = $templateFolder . '/' . $templatePage;
        if (file_exists($file)) {
            // Делаем $arResult доступным в шаблоне (как в Битриксе)
            $arResult = $this->result; 
            $arParams = $this->params;
            require $file;
        } else {
            echo "Template file not found: $file";
        }
    }
}
