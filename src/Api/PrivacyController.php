<?php
namespace Api;

use Domain\Auth;
use Domain\Privacy;

final class PrivacyController
{
    public static function leave(): void {
        if(!Auth::checkCsrfToken($_POST['csrf_token']??''))Response::fail('Обнови страницу и повтори выход.');
        Privacy::exitWebsite();
        Response::ok('Разрешения в этом браузере отозваны. Выход выполнен.');
    }
    public static function status(): void
    {
        Response::ok('', ['choices'=>Privacy::choices(),'account'=>Auth::userId()?Privacy::account(Auth::userId()):null]);
    }
    public static function fileReview(): void
    {
        if(!is_string($_POST['path']??null) || !in_array($_POST['decision']??'', ['belongs','shared'],true))Response::fail('Проверь решение по файлу.');
        if(!Privacy::decideFile($_POST['path'],$_POST['decision']==='belongs'))Response::fail('Решение уже изменилось. Обнови страницу.');
        Response::ok('Решение сохранено. Подтверждённый файл будет удалён вместе с данными аккаунта.');
    }
    public static function choices(): void
    {
        if (!Auth::checkCsrfToken($_POST['csrf_token'] ?? '')) Response::fail('Обнови страницу и повтори выбор.');
        foreach (['analytics','external_media'] as $key) {
            if (!isset($_POST[$key]) || !in_array($_POST[$key], ['0','1'], true)) Response::fail('Некорректный выбор разрешений.');
        }
        $analytics = $_POST['analytics'] === '1';
        if (Auth::userId() && Privacy::isWithdrawn(Auth::userId())) $analytics = false;
        Response::ok('Настройки приватности сохранены.', ['choices'=>Privacy::saveChoices($analytics, $_POST['external_media'] === '1')]);
    }

    public static function account(): void
    {
        $choice = $_POST['decision'] ?? '';
        if (!in_array($choice, ['accept','withdraw'], true) || ($_POST['policy_version'] ?? '') !== Privacy::version()) Response::fail('Обнови страницу и проверь условия согласия.');
        if ($choice === 'withdraw' && ($_POST['confirm'] ?? '') !== 'withdraw') Response::fail('Подтверди отзыв согласия.');
        $state = Privacy::setAccountConsent(Auth::userId(), $choice === 'accept');
        Response::ok($choice === 'accept' ? 'Согласие сохранено. Учётная запись активна.' : 'Согласие отозвано. Учётная запись неактивна.', ['account'=>$state,'redirect'=>'/profile.php']);
    }
}
