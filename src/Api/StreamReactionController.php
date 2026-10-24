<?php
namespace Api;
final class StreamReactionController
{
    public static function send(): void
    {
        $reaction=$_POST['reaction']??'';
        if(!is_string($reaction) || strlen($reaction)>20)throw new \Core\UserError('Выбери эмоцию из набора.');
        $event=(new \Domain\StreamReactions())->send((int)\Domain\Auth::userId(),$reaction);
        Response::ok('Эмоция отправлена.',['event'=>$event,'cooldown'=>3]);
    }
}
