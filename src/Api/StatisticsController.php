<?php
namespace Api;

use Domain\Auth;
use Domain\UxStatistics;

final class StatisticsController
{
    public static function record(): void
    {
        foreach ($_POST as $value) if (!is_scalar($value)) Response::fail('Некорректное измерение.');
        if (!Auth::checkCsrfToken($_POST['csrf_token'] ?? '')) Response::fail('Обнови страницу.');
        if (strlen(http_build_query($_POST)) > 4000) Response::fail('Запрос слишком большой.');
        $recorded=UxStatistics::record($_POST);
        Response::json($recorded, '', $recorded?'success':'error');
    }
}
