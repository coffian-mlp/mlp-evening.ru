<?php
namespace Api;

final class SelfHostedStreamController
{
    public static function save(): void
    {
        try{\Domain\Auth::requireAdmin();\Domain\SelfHostedStream::save($_POST);Response::ok('Настройки плеера сохранены.');}catch(\Throwable $e){Response::caught($e);}
    }
    public static function status(): void
    {
        try{\Domain\Auth::requireAdmin();header('Cache-Control: no-store');$state=\Domain\SelfHostedStream::status();Response::ok($state['message'],$state);}catch(\Throwable $e){Response::caught($e);}
    }
    public static function credentials(): void
    {
        try{\Domain\Auth::requireAdmin();header('Cache-Control: no-store');Response::ok('Параметры OBS получены.',\Domain\SelfHostedStream::credentials());}catch(\Throwable $e){Response::caught($e);}
    }
}
