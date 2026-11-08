<?php
namespace LLM;

/** Query-only Snowball topics; SQL prefixes discover candidates, PHP stem equality confirms them. */
final class MemoryRecallText
{
    // General stop words: https://snowballstem.org/algorithms/russian/stop.txt (BSD-3-Clause).
    private const FRAME = 'и в во не что он на я с со как а то все она так его но да ты к у же вы за бы по только ее мне было вот от меня еще нет о из ему теперь когда даже ну вдруг ли если уже или ни быть был него до вас нибудь опять уж вам сказал ведь там потом себя ничего ей может они тут где есть надо ней для мы тебя их чем была сам чтоб без будто человек чего раз тоже себе под жизнь будет ж тогда кто этот говорил того потому этого какой совсем ним здесь этом один почти мой тем чтобы нее кажется сейчас были куда зачем сказать всех никогда сегодня можно при наконец два об другой хоть после над больше тот через эти нас про всего них какая много разве сказала три эту моя впрочем хорошо свою этой перед иногда лучше чуть том нельзя такой им более всегда конечно всю между помнишь помните вспомни вспоминали обсуждали говорили рассказывали расскажи рассказать объясни пожалуйста можешь могли откуда почему сколько взялась взялся взялось появился появилась появилось появились называли назвали разговор разговоры разговорах история истории шутка шутки сюжетные реплики предыдущие разрешения неоднозначности снова просто давай наша наши нашем ваш ваша вашей ваши один одна две четыре пять плюс минус будет очень кто кого кому кем ком что чего чему чем какой какая какие каких каким какими какую какое чей чья чье чьи чьего чьей чьим который которого которой которую всплывать всплывал оказаться оказался оказываться оказались сравнивать сравнивали запомниться запомнились упоминать упоминали вспоминать вспомнить';
    private static ?array $frameStems = null;

    public static function fold(string $text): string
    {
        return str_replace('ё', 'е', mb_strtolower($text));
    }

    /** Preserve explicit quoted topics independently of the embedding query cleaner. */
    public static function queryText(string $raw, string $clean, array $aliases = []): string
    {
        $raw = mb_substr($raw, 0, 2000);
        $quoted = [];$prefix = 'quotedtopic';
        while (str_contains(mb_strtolower($raw), $prefix)) $prefix .= 'q';
        $protected = preg_replace_callback('/«[^»]+»|"[^"\n]+"/u', static function ($m) use (&$quoted, $prefix) {
            $key = $prefix.count($quoted).'zz';$quoted[$key] = $m[0];return $key;
        }, $raw);
        $protected=preg_replace('/(?<![\p{L}\p{N}_])(?:в|во|из)\s+(?:(?:нашем|нашего|этом|этого)\s+)?чат(?:е|а|ике|ика)(?![\p{L}\p{N}_])/iu',' ',$protected);
        if ($quoted) return strtr(MemoryQueryText::clean($protected, $aliases), $quoted);
        $locationClean=MemoryQueryText::clean($protected,$aliases);
        if($locationClean!==MemoryQueryText::clean($raw,$aliases))$clean=$locationClean;
        $tokens = self::tokens($raw);
        $addresses = array_map(self::fold(...), array_merge(['лира','лиру','лире','лиры','лирой','lyra','привет','приветик','здравствуй','здравствуйте','hello','hey'], $aliases));
        if (count($tokens) === 1 && mb_strlen($tokens[0]['word']) > 1 && !in_array($tokens[0]['word'], $addresses, true)) return $raw;
        return $clean;
    }

    /** Token offsets refer to the folded UTF-8 string. Structured numeric IDs stay atomic. */
    public static function tokens(string $text): array
    {
        $text = self::fold($text);$tokens = [];
        preg_match_all('/[\p{L}\p{N}_]+(?:-[\p{L}\p{N}_]+)*/u', $text, $words, PREG_OFFSET_CAPTURE);
        foreach ($words[0] as [$word, $offset]) {
            if (mb_strlen($word) > 80) continue;
            if (str_contains($word, '-') && preg_match('/[0-9]/', $word)) {
                $tokens[] = ['word'=>$word,'stem'=>$word,'offset'=>$offset];continue;
            }
            preg_match_all('/[\p{L}\p{N}_]+/u', $word, $parts, PREG_OFFSET_CAPTURE);
            foreach ($parts[0] as [$part, $start]) $tokens[] = ['word'=>$part,'stem'=>RussianStemmer::stem($part),'offset'=>$offset+$start];
        }
        return $tokens;
    }

    public static function roots(string $text, bool $standalone = true): array
    {
        $folded = self::fold($text);$tokens = self::tokens($folded);$explicit = [];
        preg_match_all('/«[^»]+»|"[^"\n]+"/u', $folded, $quotes, PREG_OFFSET_CAPTURE);
        foreach ($quotes[0] as [$quote, $start]) $explicit[] = [$start, $start+strlen($quote)];
        if (self::$frameStems === null) {
            self::$frameStems = [];
            foreach (explode(' ', self::FRAME) as $word) self::$frameStems[RussianStemmer::stem($word)] = true;
        }
        $roots = [];
        foreach ($tokens as $token) {
            $forced = $standalone && count($tokens) === 1 && mb_strlen($token['word']) > 1;
            foreach ($explicit as [$start,$end]) if ($token['offset'] >= $start && $token['offset'] < $end) $forced = true;
            if (!$forced && isset(self::$frameStems[$token['stem']])) continue;
            $roots[$token['stem']] = true;
            if (count($roots) >= 12) break;
        }
        return array_keys($roots);
    }

    public static function identifiers(string $text): array
    {
        return array_values(array_filter(self::roots($text), static fn($term)=>str_contains($term,'-')&&preg_match('/[\p{L}]/u',$term)&&preg_match('/[0-9]/',$term)));
    }

    public static function boolean(string $text, bool $required = false): string
    {
        $words=[];
        foreach(self::roots($text)as$root)foreach(preg_split('/[^\p{L}\p{N}_]+/u',$root,-1,PREG_SPLIT_NO_EMPTY)as$word)if(mb_strlen($word)>=3)$words[$word]=true;
        return implode(' ',array_map(static fn($word)=>($required?'+':'').$word.'*',array_keys($words)));
    }

    /** This prefix is a candidate predicate only, never evidence of a morphological match. */
    public static function pattern(string $root): string
    {
        return '(?<![\p{L}\p{N}_])'.preg_quote($root,'/');
    }

    public static function matches(string $text, array $roots): array
    {
        if(!$roots)return [];
        $wanted=array_fill_keys($roots,true);$matches=[];$text=self::fold($text);
        // A Snowball stem is a prefix of its word. This native prefilter avoids stemming
        // unrelated words; exact stem equality below remains the only confirmation.
        $prefix=implode('|',array_map(static fn($root)=>preg_quote((string)$root,'/'),$roots));
        preg_match_all('/(?<![\p{L}\p{N}_-])(?:[\p{L}\p{N}_]+-)*(?:'.$prefix.')[\p{L}\p{N}_]*(?:-[\p{L}\p{N}_]+)*/u',$text,$words,PREG_OFFSET_CAPTURE);
        foreach($words[0]as[$word,$offset])foreach(self::tokens($word)as$token)if(isset($wanted[$token['stem']])&&!isset($matches[$token['stem']]))$matches[$token['stem']]=$offset+$token['offset'];
        return $matches;
    }

    public static function mask(string $text, array $roots): int
    {
        $mask=0;foreach(self::matches($text,$roots)as$root=>$_)$mask|=1<<array_search($root,$roots,true);return $mask;
    }

    public static function score(int $mask, array $roots, array $weights = []): float
    {
        $score=0.0;foreach($roots as$i=>$root)if($mask&(1<<$i))$score+=(float)($weights[$root]??1.0);return $score;
    }

    private static function excerpt(array $source, array $roots, int $limit, array $weights): array
    {
        $matches=self::matches($source['text'],$roots);
        if(!$matches){$source['text']=mb_substr($source['text'],0,$limit);return $source;}
        $folded=self::fold($source['text']);$positions=[];
        foreach($matches as$root=>$offset)$positions[$root]=mb_strlen(substr($folded,0,$offset));
        $start=0;$best=-1;
        foreach($positions as$position){
            $candidate=max(0,$position-20);$end=$candidate+$limit-($candidate?1:0);$score=0.0;
            foreach($positions as$root=>$p)if($p>=$candidate&&$p<$end)$score+=(float)($weights[$root]??1.0);
            if($score>$best||($score===$best&&$candidate<$start)){$best=$score;$start=$candidate;}
        }
        $source['text']=($start?'…':'').mb_substr($source['text'],$start,$limit-($start?1:0));return $source;
    }

    public static function window(array $lines, string $query, array $weights = []): array
    {
        $roots=self::roots($query);$best=null;$score=0.0;$firstTopic=PHP_INT_MAX;
        foreach($lines as$i=>$line){
            $matches=self::matches($line['text'],$roots);$value=0.0;
            foreach($matches as$root=>$_)$value+=(float)($weights[$root]??1.0);
            $first=$matches?min(array_map(static fn($root)=>array_search($root,$roots,true),array_keys($matches))):PHP_INT_MAX;
            if($value>$score||($value>0&&$value===$score&&$first<$firstTopic)){$score=$value;$best=$i;$firstTopic=$first;}
        }
        if($best===null)return $lines;
        $result=[self::excerpt($lines[$best],$roots,300,$weights)];
        foreach([$best-1,$best+1]as$i)if(isset($lines[$i]))$result[]=self::excerpt($lines[$i],$roots,80,$weights);
        return $result;
    }
}
