<?php
namespace LLM;

use Domain\LlmUsage;

/** One record per actual completion HTTP attempt; deliberately no request/response storage. */
final class UsageMeter
{
    private static string $task='other';

    public static function task(): string {return self::$task;}

    public static function within(string $task, callable $call): mixed
    {
        $previous=self::$task;
        self::$task=preg_match('/^[a-z][a-z0-9_]{0,47}$/D',$task)?$task:'other';
        try{return $call();}finally{self::$task=$previous;}
    }

    public static function ask(string $task,LLMProviderInterface &$provider,array $context,string $prompt,?float $deadlineAt=null,int $timeoutSec=60): ?string
    {
        return self::within($task,static function() use(&$provider,$context,$prompt,$deadlineAt,$timeoutSec){
            return RequestRetry::ask($provider,$context,$prompt,$deadlineAt,$timeoutSec);
        });
    }

    public static function measurement(string $provider,string $model,array $body,int $httpCode,bool $transportError,int $durationMs,string $task): array
    {
        $usage=$body['usage']??($body['result']['usage']??[]);
        if(!is_array($usage))$usage=[];
        $number=static function($value): ?int {
            if((!is_int($value) && !is_string($value)) || !preg_match('/^\d{1,12}$/D',(string)$value))return null;
            return (int)$value;
        };
        $input=$number($usage['prompt_tokens']??$usage['input_tokens']??$usage['inputTextTokens']??null);
        $output=$number($usage['completion_tokens']??$usage['output_tokens']??$usage['completionTokens']??null);
        $total=$number($usage['total_tokens']??$usage['totalTokens']??null);
        if($total===null && $input!==null && $output!==null)$total=$input+$output;
        $status=($transportError || $httpCode<200 || $httpCode>=300 || isset($body['error']) || !$body)?'error':'ok';
        if($status==='ok' && ($body['choices'][0]['finish_reason']??'')==='length')$status='truncated';
        return ['provider'=>substr(strtolower($provider),0,40),'model'=>mb_substr($model,0,190),'task'=>$task,
            'status'=>$status,'duration_ms'=>max(0,min(2147483647,$durationMs)),
            'input_tokens'=>$input,'output_tokens'=>$output,'total_tokens'=>$total];
    }

    public static function capture(string $provider,string $model,?string $response,int $httpCode,bool $transportError,int $durationMs,?string $task=null): void
    {
        try{
            $body=json_decode($response??'',true);
            $row=self::measurement($provider,$model,is_array($body)?$body:[],$httpCode,$transportError,$durationMs,$task??self::$task);
            LlmUsage::record($row);
        }catch(\Throwable $error){ /* Measurement must not affect provider results. */ }
    }
}
