<?php
namespace Api;

final class UsageController
{
    public static function summary(): void
    {
        Response::ok('Потребление загружено.',(new \Domain\LlmUsage())->summary((int)($_POST['days']??30)));
    }

    public static function saveRates(): void
    {
        $raw=$_POST['rates']??'';
        if(!is_string($raw) || strlen($raw)>50000)throw new \Core\UserError('Слишком большой список тарифов.');
        $rows=json_decode($raw,true);
        if(!is_array($rows) || !array_is_list($rows))throw new \Core\UserError('Проверь список тарифов.');
        (new \Domain\LlmUsage())->saveRates($rows);
        Response::ok('Тарифы сохранены. Они применятся к новым запросам.');
    }
}
