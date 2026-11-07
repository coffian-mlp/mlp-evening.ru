<?php
namespace LLM;

/** Removes addressing and conversational scaffolding, never source facts or permissions. */
final class MemoryQueryText
{
    public static function clean(string $text, array $botAliases = []): string
    {
        $text=html_entity_decode(mb_substr($text,0,2000),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $botAliases=array_values(array_unique(array_filter(array_map(static fn($alias)=>ltrim(trim($alias),'@'),$botAliases))));
        $aliases=array_values(array_unique(array_merge(['лира','лиры','лире','лиру','лирой','лирою','лирочка','лирочки','лирочке','лирочку','лирочкой','лиронька','lyra'],$botAliases)));
        usort($aliases,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        $greeting='доброе\s+утро|добрый\s+(?:день|вечер)|доброго\s+(?:дня|вечера)|привет|приветик|здравствуй|здравствуйте|хай|hello|hey';
        $text=preg_replace('/^\s*(?:'.$greeting.')(?![\p{L}\p{N}_])[\s,;:!.-]*/iu','',$text);
        $pattern=implode('|',array_map(static fn($alias)=>preg_quote($alias,'/'),$aliases));
        $text=preg_replace('/^\s*@?(?:'.$pattern.')(?![\p{L}\p{N}_-])[\s,;:!.-]*/iu','',$text,1);
        // Only explicit configured bot nicknames are mentions; names in the topic remain.
        foreach($botAliases as $alias)$text=preg_replace('/(?<![\p{L}\p{N}_])@'.preg_quote(ltrim($alias,'@'),'/').'(?![\p{L}\p{N}_-])/iu',' ',$text);
        // Preserve words next to BOOLEAN operators and internal identifier hyphens (DL-6).
        $text=preg_replace('/[+*"~<>()]|(?<![\p{L}\p{N}])[-]+|@(?![\p{L}\p{N}_])/u',' ',$text);
        $text=preg_replace('/(?<![\p{L}\p{N}_])(?:доброе\s+утро|добрый\s+(?:день|вечер)|доброго\s+(?:дня|вечера))(?![\p{L}\p{N}_])/iu',' ',$text);
        $stop='а|ты|вы|у|нас|кто|что|про|там|помнишь|вспомни|расскажи|пожалуйста|опять|за|свое|своё|это|как|мне|нам|тебя|тебе|можешь|ли|бы|о|об|с|со|привет|приветик|здравствуй|здравствуйте|хай|hello|hey';
        $text=preg_replace('/(?<![\p{L}\p{N}_@])(?:'.$stop.')(?![\p{L}\p{N}_])/iu',' ',$text);
        $text=trim(preg_replace('/\s+/u',' ',$text)," \t\n\r\0\x0B,;:");
        return preg_match('/[\p{L}\p{N}][\p{L}\p{N}_-]+/u',$text)?$text:'';
    }
}
