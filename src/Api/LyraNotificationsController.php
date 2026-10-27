<?php
namespace Api;

use Domain\Auth;
use Domain\LyraNotifications;

final class LyraNotificationsController
{
    public static function status(): void { Response::ok('',(new LyraNotifications())->status(Auth::userId())); }
    public static function connect(): void
    {
        try { Response::ok('Открой Telegram и нажми «Начать».',['url'=>(new LyraNotifications())->begin(Auth::userId())]); }
        catch(\Throwable $e){Response::caught($e);}
    }
    public static function disconnect(): void { (new LyraNotifications())->disconnect(Auth::userId());Response::ok('Уведомления Лиры отключены.'); }
}
