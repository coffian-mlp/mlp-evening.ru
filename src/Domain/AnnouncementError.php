<?php
namespace Domain;

/** Safe operational explanations; raw provider responses must never reach the editor. */
final class AnnouncementError
{
    public static function describe(string $code, array $facts = []): string
    {
        [$reason,$id]=array_pad(explode(':',$code,2),2,'');
        $episode='серии';
        foreach (($facts['snapshot']['stories']??[]) as $story) {
            foreach (($story['ids']??[]) as $i=>$episodeId) {
                if ((string)$episodeId!==$id) continue;
                $title=$story['titles'][$i]??'';
                $episode='серии «'.(EpisodeCatalog::metadata($title)['name']??$title).'»';
            }
        }
        return match($reason) {
            'synopsis_sources_missing'=>'Не удалось найти подтверждённые источники с описанием '.$episode.'.',
            'synopsis_unavailable'=>'Сервис не вернул описание '.$episode.'.',
            'synopsis_invalid'=>'Описание '.$episode.' неполное или не прошло проверку источников и формата.',
            'synopsis_deadline'=>'Не хватило времени на описание '.$episode.'. Уже готовые описания сохранены.',
            'synopsis_catalogue_invalid'=>'Не удалось прочитать список серий закреплённого плейлиста.',
            'text_unavailable','text_exception'=>'Модель не смогла подготовить текст анонса.',
            'text_invalid'=>'Текст анонса не прошёл проверку после двух исправлений: дата, время, ссылка, длина или требования к тексту.',
            'image_daily_limit'=>'Достигнут дневной лимит генерации изображений.',
            'image_unavailable','image_exception'=>'Сервис не смог создать или сохранить изображение анонса.',
            'delivery_uncertain','delivery_interrupted'=>'Доставка в Telegram не подтверждена. Проверь личку и канал перед повторной отправкой.',
            'delivery_rejected'=>'Telegram отклонил отправку. Проверь доступность Telegram и права бота.',
            'source_unavailable'=>'Событие или закреплённый плейлист больше недоступны.',
            default=>'Не удалось подготовить анонс. Повтори подготовку; если ошибка сохранится, проверь журнал сервера.',
        };
    }
}
