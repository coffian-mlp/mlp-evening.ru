<?php
namespace Api;

use Core\UserError;
use Domain\Auth;
use Domain\EpisodeManager;
use Domain\EpisodeRatingManager;

/** Explicit participant mutations; viewing the catalogue never invokes this controller. */
final class EpisodeCatalogueController
{
    public static function wish(): void { self::respond('wish'); }
    public static function cancelWish(): void { self::respond('cancel'); }

    public static function operationKey(int $actorId, string $kind, mixed $episodeId, mixed $token): string
    {
        if ($actorId<=0 || !in_array($kind,['wish','cancel'],true)) throw new UserError('Некорректное действие.');
        if (!is_scalar($episodeId) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$episodeId) || (float)$episodeId>2147483647) throw new UserError('Некорректный ID эпизода.');
        if (!is_string($token) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$token)) throw new UserError('Некорректный ключ операции.');
        return 'catalogue:'.$actorId.':'.$kind.':'.(int)$episodeId.':'.strtolower($token);
    }

    private static function respond(string $kind): void
    {
        try {
            Auth::requireApiLogin();
            $actor=(int)Auth::userId();
            $id=$_POST['episode_id']??null;
            $key=self::operationKey($actor,$kind,$id,$_POST['operation_token']??null);
            $manager=new EpisodeManager();
            $out=$kind==='wish'?$manager->wish($actor,(int)$id,$key):$manager->cancelWish($actor,(int)$id,$key);
            $projection=(new EpisodeRatingManager())->getCatalogueProjection($actor);
            $rows=array_column($projection['rows'],null,'id');
            Response::ok(self::outcomeMessage($out),['outcome'=>$out,'row'=>$rows[(int)$id]??null,'quota'=>$projection['viewer']['quota']]);
        } catch (\Throwable $error) { Response::caught($error); }
    }

    public static function outcomeMessage(array $out): string
    {
        return match($out['code']??'') {
            'accepted'=>'Пожелание принято.',
            'cancelled'=>'Пожелание отменено.',
            'cooldown'=>'Этот эпизод можно пожелать повторно через семь дней после предыдущего пожелания.',
            'daily_limit'=>'Дневной лимит пожеланий исчерпан.',
            'not_active'=>'Активного пожелания нет.',
            'missing'=>'Эпизод не найден.',
            default=>'Действие не выполнено.',
        };
    }
}
