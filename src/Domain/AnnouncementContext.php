<?php
namespace Domain;

/** Independent source selections for each text and image generation target. */
final class AnnouncementContext
{
    public static function sources(string $target): array
    {
        if (!in_array($target,['main','reminder','image_main','image_reminder'],true)) throw new \InvalidArgumentException('Unknown announcement target');
        $sources=['playlist'=>'Серии из плейлиста','event'=>'Название и описание события','night'=>'Следующий ночной показ','memory'=>'Общая память сообщества','chat'=>'Чат прошлого вечерка'];
        if (str_starts_with($target,'image_')) {
            $sources['event']='Сведения о событии, включая дату и время';
            $sources+=['caption'=>'Текст этого поста','style'=>'Общий художественный стиль Лиры'];
        }
        return $sources;
    }

    public static function defaults(): array
    {
        $out=[];
        foreach (['main','reminder','image_main','image_reminder'] as $target) {
            foreach (self::sources($target) as $source=>$label) {
                $image=str_starts_with($target,'image_');
                $out['announcements_'.$target.'_use_'.$source]=(!$image || in_array($source,['playlist','event','style'],true))?'1':'0';
            }
        }
        return $out;
    }

    public static function enabled(array $settings,string $target,string $source): bool
    {
        $key='announcements_'.$target.'_use_'.$source;
        $defaults=self::defaults();
        if (!isset($defaults[$key])) throw new \InvalidArgumentException('Unknown announcement context source');
        return ($settings[$key]??$defaults[$key])==='1';
    }
}
