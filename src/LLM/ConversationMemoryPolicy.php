<?php
namespace LLM;

/** Pure conversation boundaries and prompt limits. Source material never grants instructions. */
final class ConversationMemoryPolicy
{
    public const IDLE = 5400;
    public const MAX_SESSION = 64800;
    public const SETTLE = 600;
    public const SUMMARY_MARKER = '[Конспект беседы]';
    public const RETURN_MARKER = '[Возвращение участника]';

    public static function session(array $rows, array $events, int $bot, int $now): ?array
    {
        $start=null;$last=null;$first=0;$end=0;
        $expiry=static function(int $at)use($events):int{
            $end=$at+self::IDLE;
            foreach($events as $e){$s=(int)$e['real_start_time'];$to=$s+(int)$e['duration_minutes']*60;
                if($s<=$end&&$to>=$at)$end=max($end,$to);
            }
            return $end;
        };
        foreach($rows as $r){
            // Tombstones preserve the original session boundary; eligibility is checked separately.
            if((int)$r['user_id']===$bot)continue;
            $at=strtotime($r['created_at'].' UTC');if($at>$now)continue;
            if($start===null||$at>=$end){$start=$at;$first=(int)$r['id'];}
            $last=$at;$end=min($start+self::MAX_SESSION,$expiry($at));
        }
        if($start===null||$now>=$end)return null;
        return ['key'=>hash('sha256',$first.'|'.$start),'first_id'=>$first,'started'=>$start,'last_human'=>$last,'expires'=>$end];
    }

    public static function batch(array $rows,int $cursor,int $bot,int $now): array
    {
        $out=[];$chars=0;$humans=0;$last=$cursor;
        foreach($rows as $r){
            if((int)$r['id']<=$cursor)continue;
            if(strtotime($r['created_at'].' UTC')>$now-self::SETTLE)break;
            if(!empty($r['is_deleted'])){$last=(int)$r['id'];continue;}
            $text=mb_substr(\Domain\BotMemoryManager::normalizeText((string)($r['message']??'')),0,300);
            $line=['id'=>(int)$r['id'],'user_id'=>(int)$r['user_id'],'role'=>(int)$r['user_id']===$bot?'bot':'user','text'=>$text];
            $size=mb_strlen(json_encode($line,JSON_UNESCAPED_UNICODE));
            if(count($out)>=50||$chars+$size>5000)break;
            $out[]=$line;$chars+=$size;$last=(int)$r['id'];if((int)$r['user_id']!==$bot)$humans++;
        }
        return ['rows'=>$out,'cursor'=>$last,'humans'=>$humans];
    }

    /** Only vocabulary actually supplied to this summary may extend the nickname allowlist. */
    public static function summaryLatin(array $nicknames,string $previous,array $messages): array
    {
        $allowed=array_merge($nicknames,ResponseSanitizer::latinWords($previous));
        foreach($messages as $message)$allowed=array_merge($allowed,ResponseSanitizer::latinWords((string)($message['text']??'')));
        return array_values(array_unique($allowed));
    }

    /** Small foreign titles are allowed; sustained foreign prose is not. */
    private static function excessiveLatin(string $text,array $allowedLatin): bool
    {
        $foreign=array_flip(ResponseSanitizer::foreignLatin($text,$allowedLatin));
        // Preserve every fix8 acceptance, including its URL/mention/abbreviation exemptions.
        if(!$foreign)return false;
        $text=(string)preg_replace('~https?://\\S+~u',' ',$text);
        preg_match_all("~[@/]?\\p{L}+(?:['’]\\p{L}+)*~u",$text,$words);
        $count=0;$run=0;
        foreach($words[0]as$word){
            $unknown=false;
            foreach(ResponseSanitizer::latinWords($word)as$part)if(isset($foreign[$part])){$unknown=true;break;}
            if($unknown){$count++;$run++;}else{$run=0;}
            if($run>=6)return true;
        }
        // Integer arithmetic keeps exactly 30 percent admissible.
        return $count*10>count($words[0])*3;
    }

    public static function clean(string $text,array $allowedLatin=[]): ?string
    {
        $text=ResponseSanitizer::clean(CjkGuard::normalize($text));
        if(!$text||CjkGuard::foreign($text)||self::excessiveLatin($text,$allowedLatin)||ResponseSanitizer::hasImage($text))return null;
        return self::truncateSentences($text,2000);
    }

    /** The caller supplies the final available body budget, excluding any header. */
    public static function truncateSentences(string $text,int $limit): ?string
    {
        if($limit<1)return null;
        if(mb_strlen($text)>$limit){
            $prefix=mb_substr($text,0,$limit+1);
            // A complete sentence ends before whitespace/end, optionally inside closing quotes.
            preg_match_all('/[.!?…][»”"\\)\\]]*(?=\\s|$)/u',$prefix,$ends,PREG_OFFSET_CAPTURE);
            $ends=array_filter($ends[0],static function($end)use($prefix,$limit){
                $before=substr($prefix,0,$end[1]);
                if(mb_strlen($before.$end[0])>$limit)return false;
                // Conservatively skip initials, list numbers and common Russian abbreviations.
                if($end[0][0]==='.'&&preg_match('/(?:^|[^\\p{L}])(?:[\\p{L}]{1,2}|[0-9]+|напр|стр|рис|им|проф|тов|руб|мин|сек|гг|др|пр|см|табл|разд|гл|прибл|ред|пер|сост|акад|доц|обл|ул|дом|кв|коп|тыс|млн|млрд)$/iu',$before))return false;
                return true;
            });
            if(!$ends)return null;
            $end=end($ends);$text=trim(substr($prefix,0,$end[1]+strlen($end[0])));
        }
        return ResponseSanitizer::isMeaningful($text)?$text:null;
    }

    public static function memoryChars(array $context): int
    {
        $n=0;foreach($context as$m)if(self::isMemory((string)($m['content']??'')))$n+=mb_strlen($m['content']);return $n;
    }
    private static function isMemory(string $s): bool
    {
        foreach([LyraMemory::BLOCK_MARKER,MemoryRecallBlock::MARKER,MemoryRecallBlock::FOCUS,self::SUMMARY_MARKER,self::RETURN_MARKER]as$p)if(str_starts_with($s,$p))return true;
        return false;
    }
    public static function apply(array $context,array $blocks,int $budget): array
    {
        if(!$blocks||$budget<500)return $context;
        $addition=implode("\n",$blocks);if(mb_strlen($addition)>$budget)return $context;
        $remaining=$budget-mb_strlen($addition);$result=$context;$keys=array_keys($result);
        // Preserve the addressed focus first; allocation does not reorder existing messages.
        $isFocus=static fn($i)=>is_string($result[$i]['content']??null)&&str_starts_with($result[$i]['content'],MemoryRecallBlock::FOCUS);
        usort($keys,static fn($a,$b)=>(int)$isFocus($b)<=>(int)$isFocus($a));
        foreach($keys as$i){
            $content=$result[$i]['content']??'';if(!is_string($content)||!self::isMemory($content))continue;
            if($remaining<200){unset($result[$i]);continue;}
            $result[$i]['content']=mb_substr($content,0,$remaining);$remaining-=mb_strlen($result[$i]['content']);
        }
        $result=array_values($result);$insert=0;
        foreach($result as$m){$c=$m['content']??'';
            if(!is_string($c)||str_starts_with($c,MemoryRecallBlock::FOCUS)||(!self::isMemory($c)&&!str_starts_with($c,'[Закреплено в чате')))break;
            $insert++;
        }
        array_splice($result,$insert,0,[['role'=>'user','content'=>$addition]]);return $result;
    }
}
