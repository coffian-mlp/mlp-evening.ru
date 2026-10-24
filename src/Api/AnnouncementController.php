<?php
namespace Api;

/** Role and CSRF enforcement are provided by the API router. */
final class AnnouncementController
{
    public static function journal(): void
    {
        if (!in_array($_POST['errors_only']??'1',['0','1'],true)) Response::fail('Некорректный фильтр журнала.');
        try {
            $settings=\Domain\AnnouncementSettings::values();
            $timezone=new \DateTimeZone($settings['announcements_timezone']);
            $rows=(new \Infra\AnnouncementLog())->recent(100,($_POST['errors_only']??'1')==='1');
            $entries=array_map(static fn($row)=>\Domain\AnnouncementJournal::describe($row,$timezone),$rows);
        } catch (\Throwable $e) {
            Response::fail('Не удалось прочитать журнал. Попробуй обновить его позже.');
        }
        Response::ok('', ['entries'=>$entries,'timezone'=>$timezone->getName()]);
    }

    public static function action(): void
    {
        try {$message=\Domain\AnnouncementDashboard::execute($_POST);}
        catch (\mysqli_sql_exception $e) {Response::json(false,'Не удалось изменить пост. Попробуй ещё раз.','error');}
        catch (\InvalidArgumentException|\RuntimeException $e) {Response::json(false,$e->getMessage(),'error');}
        Response::json(true,$message);
    }
}
