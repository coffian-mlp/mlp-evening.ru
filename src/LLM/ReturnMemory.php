<?php
namespace LLM;

use Domain\BotMemoryManager;
use Domain\MemoryEpisodeManager;
use Domain\Privacy;
use Domain\UserManager;

/** One current, consented source fragment; selection never calls a model. */
final class ReturnMemory
{
    public function block(LLMManager $llm,int $uid,int $before,MemoryIndexBudget $budget): string
    {
        $anchor=$this->anchor($llm,$uid,$before,$budget);
        return ConversationMemoryPolicy::RETURN_MARKER."\n".json_encode(['anchor'=>$anchor['text']??null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    public function anchor(LLMManager $llm,int $uid,int $before,MemoryIndexBudget $budget): ?array
    {
        if(!Privacy::isActive($uid))return null;
        $bot=$llm->getBotUserId();$active=[$uid=>true,$bot=>true];
        $consented=static function(int$id)use(&$active):bool{if($id<1)return true;return $active[$id]??=Privacy::isActive($id);};
        $directory=(new UserManager())->mentionDirectory();$other=[];$all=[];
        foreach($directory as$user)foreach(['login','nickname']as$key)if($user[$key]??''){$all[]=$user[$key];if(!in_array((int)$user['id'],[$uid,$bot,19],true))$other[]=$user[$key];}
        $reader=new MemorySourceReader();$memory=new BotMemoryManager();$candidates=[];$rows=$memory->getDossiers([$uid])[$uid]??[];
        $db=\Infra\Database::getInstance()->getConnection();$budget->prepare();
        $s=$db->prepare("SELECT * FROM bot_memory WHERE kind='meme' AND is_archived=0 AND (user_id=? OR created_by=?) ORDER BY updated_at DESC,id DESC LIMIT 32");$s->bind_param('ii',$uid,$uid);$s->execute();$rows=array_merge($rows,$s->get_result()->fetch_all(MYSQLI_ASSOC));
        $ids=array_column(array_filter($rows,static fn($r)=>$r['source']!=='oc'),'id');
        foreach($ids?$reader->conversationDossiers($ids,$budget->prepare(...)):[]as$row){
            if(!$consented((int)($row['created_by']??0))||!$consented((int)($row['user_id']??0)))continue;
            if((int)($row['user_id']??0)!==$uid&&(int)($row['created_by']??0)!==$uid)continue;
            $candidates[]=['text'=>$row['text'],'kind'=>$row['kind'],'id'=>'memory:'.$row['id'],'at'=>strtotime(($row['updated_at']??$row['created_at']).' UTC'),'priority'=>$row['kind']==='meme'?0:2];
        }
        try{
            $profile=$llm->conversationRecallProfile();$store=new MemoryEpisodeManager();$budget->prepare();$ids=$store->conversationParticipantIds($uid,$before,$profile);$budget->prepare();$records=$store->recallRecords($ids,$profile);$budget->prepare();$members=$store->recallMembers($ids);
            foreach($reader->recall($records,$members,$profile,$bot,$budget->check(...),$budget->prepare(...))as$episode){
                if(array_filter($episode['lines'],static fn($l)=>!$consented((int)($l['user_id']??0))))continue;
                foreach($episode['lines']as$line)if($line['role']==='user'&&(int)$line['user_id']===$uid)$candidates[]=['text'=>$line['text'],'kind'=>'episode','id'=>$line['source'],'at'=>strtotime($line['date_utc'].' UTC'),'priority'=>preg_match('/шут|мем|ахах|хаха|смешн|прикол/iu',$line['text'])?1:3,'episode_id'=>$episode['id']];
            }
        }catch(\InvalidArgumentException$e){/* A dossier remains usable without an embedding profile. */}
        $picked=self::select($candidates,$other,$all);$budget->check();
        if(isset($picked['episode_id'])){$budget->prepare();$fresh=$store->recallRecords([(int)$picked['episode_id']],$profile);if(($fresh[$picked['episode_id']]??null)!==($records[$picked['episode_id']]??null))return null;}
        return $picked;
    }

    /** Pure ranking: attributed meme, explicit joke, dossier fact, other own episode; then newest. */
    public static function select(array $candidates,array $otherNames=[],array $allNames=[]): ?array
    {
        $eligible=[];
        foreach($candidates as$candidate){
            if(!mb_check_encoding($candidate['text'],'UTF-8'))continue;
            $parts=preg_split('/(?<=[.!?;])\s+|[\r\n]+/u',BotMemoryManager::normalizeText($candidate['text']));
            foreach($parts as$part=>$text){
                $text=preg_replace('/^[\s\p{Z}\x00-\x1F\x7F•\-«»“”„"]+|[\s\p{Z}\x00-\x1F\x7F•\-«»“”„"]+$/u','',$text);
                if($text===null)continue;
                if(mb_strlen($text)<16||mb_strlen($text)>160||preg_match('~https?://|[\w.+-]+@[\w.-]+|[0-9]{4}|телефон|адрес|паспорт|диагноз|болезн|зарплат|парол|жена|муж\b|дет[ьи]|семь[яи]~iu',$text))continue;
                $hasOther=false;foreach($otherNames as$name)if(preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($name,'/').'(?![\p{L}\p{N}_])/iu',$text)){$hasOther=true;break;}if($hasOther)continue;
                $topic=$text;foreach($allNames as$name)$topic=preg_replace('/(?<![\p{L}\p{N}_])'.preg_quote($name,'/').'(?![\p{L}\p{N}_])/iu',' ',$topic);
                if(preg_match('/[a-z\x{3400}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/iu',$topic))continue;
                $roots=array_values(array_filter(MemoryRecallText::roots($topic),static fn($r)=>mb_strlen($r)>=3&&!is_numeric($r)&&!in_array($r,['привет','спасиб','люб','игра','чита','смотр','хорош','сегодн','сейчас','участник','человек'],true)));
                if(!$roots)continue;
                $eligible[]=$candidate+['part'=>$part,'keywords'=>$roots,'original_text'=>$candidate['text']];$eligible[array_key_last($eligible)]['text']=$text;
            }
        }
        usort($eligible,static fn($a,$b)=>[$a['priority'],-$a['at'],$a['id'],$a['part']]<=>[$b['priority'],-$b['at'],$b['id'],$b['part']]);
        return $eligible[0]??null;
    }

    /** Parse a standalone code-created return segment, never a suffix of summary data. */
    public static function returnBlock(string $content): ?string
    {
        $marker=ConversationMemoryPolicy::RETURN_MARKER;
        if(!str_starts_with($content,$marker))return null;
        $data=json_decode(trim(substr($content,strlen($marker))),true);
        if(!is_array($data)||!array_key_exists('anchor',$data)||($data['anchor']!==null&&!is_string($data['anchor'])))return null;
        return $marker."\n".json_encode(['anchor'=>$data['anchor']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    /** Segments are out-of-band provenance from this preparation, not model/source text. */
    private static function returned(array $context,array $segments): ?array
    {
        $block=self::returnBlock($segments['return']??'');if($block===null)return null;
        $composite=implode("\n",$segments);
        foreach($context as$m)if(($m['role']??null)==='user'&&in_array($m['content']??null,[$composite,$block],true))return ['role'=>'user','content'=>$block];
        return null;
    }

    /** An arrival greeting does not need the old chat or competing background dossiers. */
    public static function focus(array $context,bool $greeting,array $segments=[]): array
    {
        $returned=self::returned($context,$segments);if($returned===null)return $context;
        if($greeting)return[$returned];
        $out=[];$added=false;
        foreach($context as$m){
            $text=$m['content']??'';
            if(!is_string($text)){$out[]=$m;continue;}
            if($text===implode("\n",$segments)||$text===$returned['content']){if(!$added){$out[]=$returned;$added=true;}continue;}
            $memory=false;foreach([LyraMemory::BLOCK_MARKER,MemoryRecallBlock::MARKER,MemoryRecallBlock::FOCUS,ConversationMemoryPolicy::SUMMARY_MARKER,'[Закреплено в чате']as$marker)if(str_starts_with($text,$marker)){$memory=true;break;}
            if(!$memory)$out[]=$m;
        }
        return $out;
    }

    public static function instruction(array $context,bool $greeting,array $segments=[]): string
    {
        $returned=self::returned($context,$segments);if($returned===null)return '';
        $data=json_decode(substr($returned['content'],strlen(ConversationMemoryPolicy::RETURN_MARKER)),true);$text=$data['anchor']??null;
        if(!$greeting){
            $start='Сначала ответь на текущий вопрос участника. Ответь полностью по обычным правилам ответа и языка; длину ответа определяет вопрос.';
            if(!$text)return $start.' Исторической зацепки нет: не добавляй воспоминания или отсылки к прошлому.';
            return $start.' Добавь не больше одного лёгкого исторического намёка на выбранную зацепку. Только к этому намёку относятся ограничения: сохрани хотя бы одно значимое слово зацепки (можно изменить падеж), все тематические слова намёка бери из неё, не добавляй новых образов, занятий или предметов, не пересказывай факт целиком и не называй даты или сроки. Эти ограничения не ограничивают содержание ответа на текущий вопрос.';
        }
        $start='Поздоровайся кратко и приветливо. Ответ строго 60–200 символов.';
        if(!$text)return $start.' Исторической зацепки нет: не добавляй воспоминания или отсылки к прошлому.';
        return $start.' Короткое обращение и один лёгкий намёк на выбранную зацепку. В намёке сохрани хотя бы одно её значимое слово, можно изменить падеж. Все тематические слова бери только из самой зацепки; не добавляй близких новых образов, занятий или предметов. Остальное — только нейтральное приветствие и пожелание хорошего дня. Не пересказывай факт целиком, не перечисляй прошлое, не называй даты или сроки. Пиши только русскими буквами, кроме точного ника адресата. Не используй смайлы с буквами или иностранные вставки.';
    }
}
