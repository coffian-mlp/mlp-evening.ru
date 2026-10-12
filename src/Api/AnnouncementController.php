<?php
namespace Api;

/** Role and CSRF enforcement are provided by the API router. */
final class AnnouncementController
{
    public static function action(): void
    {
        try {$message=\Domain\AnnouncementDashboard::execute($_POST);}
        catch (\mysqli_sql_exception $e) {Response::json(false,'Не удалось изменить пост. Попробуй ещё раз.','error');}
        catch (\InvalidArgumentException|\RuntimeException $e) {Response::json(false,$e->getMessage(),'error');}
        Response::json(true,$message);
    }
}
