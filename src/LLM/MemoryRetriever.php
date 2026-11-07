<?php
namespace LLM;

use Domain\MemoryEpisodeManager;
use Domain\ChatManager;
use Domain\UserManager;

/** One-second hybrid recall, failing back atomically to the original context. */
final class MemoryRetriever
{
    public const MIN_COSINE = 0.38;
    public const MIN_LEXICAL_COSINE = 0.15;
    private MemoryVectorMatrix $matrix;
    private $query;

    public function __construct(private MemoryEpisodeManager $store, private MemorySourceReader $sources, private string $profile, private int $botId, ?callable $query = null)
    {
        $this->matrix = new MemoryVectorMatrix();
        $this->query = $query ?? new MemoryRecallQuery();
    }

    public function recall(array $context, string $query, int $budget, bool $expand = false, ?int $actorId = null): array
    {
        $start = microtime(true); $deadline = $start + 1.0; $queryDeadline = $deadline; $sql = null;
        $meta = ['reason'=>'', 'stages_ms'=>[], 'sources'=>[], 'scores'=>[], 'expansion'=>$expand];
        try {
            $sql = new MemoryIndexBudget($this->store->connection(), $deadline - 0.02);
            $check = $sql->check(...); $prepare = $sql->prepare(...);
            if($actorId!==null){
                $prepare();$exists=(new UserManager())->memoryIdentityIds([$actorId]);$prepare();
                if(!$exists||\Domain\Privacy::isWithdrawn($actorId)){$meta['reason']='owner_unavailable';return ['context'=>$context,'metadata'=>$meta+['total_ms'=>(microtime(true)-$start)*1000]];}
            }
            $prepare();$botAliases=(new UserManager())->getUsersByIds([$this->botId]);
            $aliases=array_merge(explode(',',(string)\Infra\ConfigManager::getInstance()->getOption('ai_aliases','')),array_values($botAliases[$this->botId]??[]));
            $query = self::queryText($query, $context, $aliases); $check();
            if($query===''){$meta['reason']='no_query_topic';return ['context'=>$context,'metadata'=>$meta+['total_ms'=>(microtime(true)-$start)*1000]];}
            $stage = microtime(true);
            $queryDeadline = min($deadline - 0.15, microtime(true) + 0.75);
            $response = ($this->query)($query, $queryDeadline, $expand);
            $check();$meta['query_usage']=$response['usage']??[];$meta['stages_ms']['query'] = (microtime(true)-$stage)*1000;
            $stage = microtime(true);
            $vector = $this->matrix->top($response['vector'], function () use ($prepare) { $prepare();return $this->store->recallVectors($this->profile); }, $check, 20);
            $cosines=$this->matrix->similarities();
            $meta['stages_ms']['vector'] = (microtime(true)-$stage)*1000;
            $stage = microtime(true);
            $terms = self::terms($query);$prepare();
            $strong=$this->store->recallStrongAssociationIds($terms,$this->profile);$prepare();
            $association = $this->store->recallAssociationIds($query . ' ' . mb_substr($response['tags']??'',0,1000), $this->profile, $cosines, $strong);
            $prepare();$userIds=(new UserManager())->memoryRecallIdentityIds($terms);
            $rare = $terms;usort($rare,static fn($a,$b)=>(mb_strlen($b)<=>mb_strlen($a))?:strcmp($a,$b));
            $prepare();$sourceIds=(new ChatManager())->memoryRecallIds($rare[0]??'');
            $date=self::date($query);
            $prepare();$exact=$this->store->recallSourceIds($sourceIds,$userIds,$date,$this->profile);
            $prepare();$structural=$this->store->recallSourceIds([],$userIds,$date,$this->profile);
            $admit=static fn($id)=>($cosines[$id]??-1)>=self::MIN_COSINE||(in_array($id,$strong,true)&&($cosines[$id]??-1)>=self::MIN_LEXICAL_COSINE)||in_array($id,$structural,true);
            $vector=array_filter($vector,static fn($score,$id)=>$admit($id),ARRAY_FILTER_USE_BOTH);
            $association=array_values(array_filter($association,$admit));$exact=array_values(array_filter($exact,$admit));
            $scores=self::fuse([array_keys($vector),$association,$exact]);$check();
            $meta['stages_ms']['candidates']=(microtime(true)-$stage)*1000;
            $stage=microtime(true);$ids=array_keys($scores);$prepare();$records=$this->store->recallRecords($ids,$this->profile);
            foreach($vector as $id=>$_){$version=$this->matrix->version((int)$id);if($version!==null&&(!isset($records[$id])||(int)$records[$id]['revision']!==$version['revision']||$records[$id]['source_hash']!==$version['source_hash']))unset($vector[$id]);}
            $scores=self::fuse([array_keys($vector),$association,$exact]);
            $prepare();$members=$this->store->recallMembers(array_keys($records));
            $hydrated=$this->sources->recall($records,$members,$this->profile,$this->botId,$check,$prepare);
            // Source invalidation and privacy erasure commit with the ready->pending/delete transition.
            $prepare();$current=$this->store->recallRecords(array_keys($hydrated),$this->profile);
            $selected=[];$seen=[];
            foreach($scores as $id=>$score){
                $check();if(!isset($hydrated[$id],$current[$id],$records[$id])||$current[$id]['revision']!==$records[$id]['revision']||$current[$id]['source_hash']!==$records[$id]['source_hash'])continue;
                $episode=$hydrated[$id];if(array_intersect_key($seen,array_fill_keys($episode['source_ids'],true)))continue;
                $seen+=array_fill_keys($episode['source_ids'],true);$selected[]=$episode;$meta['scores'][$id]=['rrf'=>$score,'cosine'=>$vector[$id]??null];$meta['sources'][$id]=$episode['source_ids'];
                if(count($selected)>=5)break;
            }
            $meta['stages_ms']['hydration']=(microtime(true)-$stage)*1000;$check();
            $output=MemoryRecallBlock::apply($context,$selected,$budget);$check();
            $meta['reason']=$output===$context?($selected?'memory_budget':'no_current_sources'):'ok';
            return ['context'=>$output,'metadata'=>$meta+['total_ms'=>(microtime(true)-$start)*1000]];
        } catch (\Throwable $error) {
            $meta['reason']=microtime(true)>=$queryDeadline||$error instanceof MemoryIndexDeadline?'deadline':'search_error';
            $meta['error_class']=get_class($error);$meta['sources']=[];$meta['scores']=[];
            return ['context'=>$context,'metadata'=>$meta+['total_ms'=>(microtime(true)-$start)*1000]];
        } finally {
            if($sql!==null)$sql->restore();
        }
    }

    public static function queryText(string $query, array $context, array $botAliases = []): string
    {
        $original=trim($query);$query=MemoryQueryText::clean($query,$botAliases);
        if(mb_strlen($query)<3){
            $previous=[];
            foreach(array_reverse($context) as $message){
                if(!isset($message['chat_message_id'])||($message['role']??'')!=='user')continue;
                $content=(string)($message['content']??'');if($original!==''&&str_contains($content,$original))continue;
                $content=preg_replace('/^\[\d{1,2}:\d{2}\]\s*[^:\n]{1,80}:\s*/u','',$content);
                $content=MemoryQueryText::clean($content,$botAliases);if($content==='')continue;
                $previous[]=mb_substr($content,0,300);if(count($previous)>=3)break;
            }
            if($previous)$query=trim($query."\n".implode("\n",array_reverse($previous)));
        }
        return mb_substr($query,0,2000);
    }

    public static function date(string $query): ?string
    {
        if(!preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u',$query,$match))return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$match[1],new \DateTimeZone('UTC'));
        return $date!==false&&$date->format('Y-m-d')===$match[1]?$match[1]:null;
    }

    public static function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_-]{1,79}/u',mb_strtolower($query),$matches);
        $stop=array_flip(explode(' ','помнишь вспомни расскажи опять своё свое это что как где когда почему реплики предыдущие для разрешения неоднозначности'));
        return array_slice(array_values(array_unique(array_filter($matches[0],static fn($t)=>!isset($stop[$t])))),0,12);
    }

    public static function fuse(array $channels): array
    {
        $scores=[];foreach($channels as $ids)foreach(array_values(array_unique($ids)) as $rank=>$id)$scores[$id]=($scores[$id]??0)+1/(60+$rank+1);
        uksort($scores,static fn($a,$b)=>($scores[$b]<=>$scores[$a])?:($a<=>$b));return array_slice($scores,0,24,true);
    }
}
