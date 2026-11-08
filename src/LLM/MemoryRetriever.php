<?php
namespace LLM;

use Domain\MemoryEpisodeManager;
use Domain\ChatManager;
use Domain\UserManager;

/** One-second hybrid recall, failing back atomically to the original context. */
final class MemoryRetriever
{
    public const MIN_COSINE = 0.41;
    public const MIN_LEXICAL_COSINE = 0.15;
    public const MIN_MIXED_VECTOR_COSINE = 0.56;
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
            $rawQuery=$query;$query = self::queryText($query, $context, $aliases); $check();
            $topicQuery=MemoryRecallText::queryText($rawQuery,$query,$aliases);$roots=MemoryRecallText::roots($topicQuery,count(MemoryRecallText::tokens($rawQuery))===1);
            if($query===''&&$roots)$query=$topicQuery;
            $terms=self::terms($query);
            $prepare();$userIds=(new UserManager())->memoryRecallIdentityIds($terms);
            if($query===''||(!$roots&&!$userIds)){$meta['reason']='no_query_topic';return ['context'=>$context,'metadata'=>$meta+['total_ms'=>(microtime(true)-$start)*1000]];}
            $prepare();$identities=(new UserManager())->getUsersByIds($userIds);$identityWords=[];
            // Exact identity words remain structural even when a nickname is also a stopword.
            foreach($identities as$identity)$identityWords=array_merge($identityWords,array_column(MemoryRecallText::tokens(implode(' ',$identity)),'stem'));
            $date=self::date($query);$structuralOnly=!array_diff($roots,$identityWords,$date===null?[]:[$date]);
            $stage = microtime(true);
            $queryDeadline = min($deadline - 0.15, microtime(true) + 0.75);
            $response = ($this->query)($query, $queryDeadline, $expand);
            $check();$meta['query_usage']=$response['usage']??[];$meta['stages_ms']['query'] = (microtime(true)-$stage)*1000;
            $stage = microtime(true);
            $vector = $this->matrix->top($response['vector'], function () use ($prepare) { $prepare();return $this->store->recallVectors($this->profile); }, $check, 20);
            $cosines=$this->matrix->similarities();
            $meta['stages_ms']['vector'] = (microtime(true)-$stage)*1000;
            $stage = microtime(true);
            $fullMask=(1<<count($roots))-1;
            $confirm=static function($text,$topics)use($check){$check();return MemoryRecallText::mask($text,$topics);};
            $prepare();$weights=$this->store->recallTopicWeights($roots,$this->profile,$confirm);$totalWeight=max(1e-9,array_sum($weights));
            $weight=static fn($mask)=>MemoryRecallText::score($mask,$roots,$weights);
            $prepare();$evidence=$this->store->recallTopicMasks($roots,$this->profile,$weights,$confirm);
            $associationEvidence=$evidence;
            $prepare();$sourceMasks=(new ChatManager())->memoryRecallTopicMasks($roots,$weights,$confirm);
            $prepare();foreach($this->store->recallSourceTopicMasks($sourceMasks,$this->profile,array_values($weights))as$id=>$mask)$evidence[$id]=($evidence[$id]??0)|$mask;
            $prepare();$structural=$this->store->recallSourceIds([],$userIds,$date,$this->profile);
            $admit=static fn($id)=>self::candidateAllowed($cosines[$id]??-1,$evidence[$id]??0,$fullMask,false,$structuralOnly&&in_array($id,$structural,true),0,$weight($evidence[$id]??0)/$totalWeight);
            $vector=array_filter($vector,static fn($score,$id)=>$admit($id),ARRAY_FILTER_USE_BOTH);
            $evidence=array_filter($evidence,static fn($mask,$id)=>$admit($id),ARRAY_FILTER_USE_BOTH);
            uksort($evidence,static fn($a,$b)=>($weight($evidence[$b])<=>$weight($evidence[$a]))?:(($cosines[$b]??-1)<=>($cosines[$a]??-1))?:($a<=>$b));
            $scores=self::fuse([array_keys($evidence),array_keys($vector),$structural]);$check();
            $meta['stages_ms']['candidates']=(microtime(true)-$stage)*1000;
            $stage=microtime(true);$ids=array_keys($scores);$prepare();$records=$this->store->recallRecords($ids,$this->profile);
            $prepare();$associationEvidence=$this->store->recallTopicMasksForIds(array_keys($records),$roots,$this->profile,$confirm)+$associationEvidence;
            foreach($scores as $id=>$_){$version=$this->matrix->version((int)$id);if($version!==null&&(!isset($records[$id])||(int)$records[$id]['revision']!==$version['revision']||$records[$id]['source_hash']!==$version['source_hash']))unset($vector[$id],$evidence[$id],$cosines[$id]);}
            $scores=self::fuse([array_keys($evidence),array_keys($vector),$structural]);
            $prepare();$members=$this->store->recallMembers(array_keys($records));
            $hydrated=$this->sources->recall($records,$members,$this->profile,$this->botId,$check,$prepare);
            // Source invalidation and privacy erasure commit with the ready->pending/delete transition.
            $prepare();$current=$this->store->recallRecords(array_keys($hydrated),$this->profile);
            // Associations discover candidates; current source words determine what may be shown.
            foreach($hydrated as$id=>$episode){
                $mask=0;foreach(MemoryRecallBlock::sourceLines($episode['lines'],$topicQuery,$weights)as$line)foreach(MemoryRecallText::matches($line['text'],$roots)as$root=>$_)$mask|=1<<array_search($root,$roots,true);
                // A current dossier is an atomic fact about its exact subject, not a chat mention.
                // Its validated associations may supply terminology absent from the short fact.
                if($records[$id]['source_kind']==='memory'&&in_array($episode['lines'][0]['user_id']??null,$userIds,true)&&($associationEvidence[$id]??0)===$fullMask&&($cosines[$id]??-1)>=self::MIN_COSINE)$mask|=$associationEvidence[$id]??0;
                $evidence[$id]=$mask;
            }
            $valid=static fn($id)=>isset($hydrated[$id],$current[$id],$records[$id])&&$current[$id]['revision']===$records[$id]['revision']&&$current[$id]['source_hash']===$records[$id]['source_hash'];
            $grounded=[];
            foreach($hydrated as$id=>$_)if($valid($id)&&($evidence[$id]??0)>0&&self::candidateAllowed($cosines[$id]??-1,$evidence[$id],$fullMask,false,false,$associationEvidence[$id]??0,$weight($evidence[$id])/$totalWeight))$grounded[$id]=$weight($evidence[$id]);
            $hasLexical=(bool)$grounded;$bestWeight=$grounded?max($grounded):0.0;
            uksort($scores,static fn($a,$b)=>($weight($evidence[$b]??0)<=>$weight($evidence[$a]??0))?:(($cosines[$b]??-1)<=>($cosines[$a]??-1))?:($scores[$b]<=>$scores[$a])?:($a<=>$b));
            $selected=[];$seen=[];
            foreach($scores as $id=>$score){
                $check();if(!isset($hydrated[$id],$current[$id],$records[$id])||$current[$id]['revision']!==$records[$id]['revision']||$current[$id]['source_hash']!==$records[$id]['source_hash'])continue;
                if(($evidence[$id]??0)>0&&$weight($evidence[$id])+1e-9<$bestWeight)continue;
                if(!self::candidateAllowed($cosines[$id]??-1,$evidence[$id]??0,$fullMask,$hasLexical,$structuralOnly&&in_array($id,$structural,true),$associationEvidence[$id]??0,$weight($evidence[$id]??0)/$totalWeight))continue;
                $episode=$hydrated[$id];if(array_intersect_key($seen,array_fill_keys($episode['source_ids'],true)))continue;
                $seen+=array_fill_keys($episode['source_ids'],true);$selected[]=$episode;$meta['scores'][$id]=['rrf'=>$score,'cosine'=>$cosines[$id]??null,'topic_matches'=>substr_count(decbin($evidence[$id]??0),'1'),'topic_weight'=>$weight($evidence[$id]??0),'association_fallback'=>!$hasLexical&&($evidence[$id]??0)===0&&$fullMask>0&&($associationEvidence[$id]??0)===$fullMask];$meta['sources'][$id]=$episode['source_ids'];
                if(count($selected)>=5)break;
            }
            $meta['stages_ms']['hydration']=(microtime(true)-$stage)*1000;$check();
            $output=MemoryRecallBlock::apply($context,$selected,$budget,$topicQuery,$weights);$check();
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

    /** Grounded coverage or complete associations permit low cosine; mixed fallback is stricter. */
    public static function candidateAllowed(float $cosine, int $topicMask, int $fullMask, bool $hasLexical, bool $structural = false, int $associationMask = 0, ?float $coverage = null): bool
    {
        if($structural)return true;
        if($topicMask>0){$count=substr_count(decbin($topicMask),'1');$total=max(1,substr_count(decbin($fullMask),'1'));$coverage??=$count/$total;$supported=$associationMask===$fullMask||($count>=min(2,$total)&&$coverage>0.5);return $cosine>=($supported?self::MIN_LEXICAL_COSINE:self::MIN_COSINE);}
        if(!$hasLexical&&$fullMask>0&&$associationMask===$fullMask)return $cosine>=self::MIN_LEXICAL_COSINE;
        return $cosine>=($hasLexical?self::MIN_MIXED_VECTOR_COSINE:self::MIN_COSINE);
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
