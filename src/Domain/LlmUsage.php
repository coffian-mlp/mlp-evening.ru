<?php
namespace Domain;

use Infra\Database;
use Infra\ConfigManager;
use Infra\Env;

/** Anonymous operational measurements, independent of debug logging and its retention. */
final class LlmUsage
{
    private static ?\mysqli $connection=null;
    private static bool $reportedFailure=false;

    /** Use a separate connection so caller rollbacks cannot erase billed attempts. */
    public static function record(array $row): void
    {
        try{
            $row+=self::price($row,self::configuredRates());
            if(self::$connection===null){
                $db=\mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,1);
                $db->real_connect(Env::get('DB_HOST'),Env::get('DB_USER'),Env::get('DB_PASS'),Env::get('DB_NAME'));
                $db->set_charset('utf8mb4');self::$connection=$db;
            }
            $s=self::$connection->prepare('INSERT INTO llm_usage(created_at,provider,model,task,status,duration_ms,input_tokens,output_tokens,total_tokens,estimated_cost,currency) VALUES(UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?)');
            $values=[$row['provider'],$row['model'],$row['task'],$row['status'],$row['duration_ms'],$row['input_tokens'],$row['output_tokens'],$row['total_tokens'],$row['estimated_cost'],$row['currency']];
            $s->bind_param('ssssiiiids',...$values);$s->execute();
        }catch(\Throwable $error){
            self::$connection=null;
            if(!self::$reportedFailure){error_log('LLM usage accounting unavailable');self::$reportedFailure=true;}
        }
    }

    public function purgeExpired(): void
    {
        Database::getInstance()->getConnection()->query('DELETE FROM llm_usage WHERE created_at<UTC_TIMESTAMP()-INTERVAL 90 DAY LIMIT 500');
    }

    public static function rates(array $rows): array
    {
        if (count($rows) > 100) throw new \Core\UserError('Можно задать не больше 100 тарифов.');
        $result=[];$seen=[];
        foreach($rows as $row){
            if(!is_array($row)) throw new \Core\UserError('Проверь формат тарифов.');
            $provider=strtolower(trim((string)($row['provider']??'')));
            $model=trim((string)($row['model']??''));
            $currency=$row['currency']??'USD';
            if(!in_array($provider,['routerai','openai','openrouter','yandexgpt','gigachat','cli'],true)
                || $model==='' || strlen($model)>190 || preg_match('/[\x00-\x1f\x7f]/',$model)
                || !in_array($currency,['USD','RUB','EUR'],true)) throw new \Core\UserError('Проверь провайдера, модель и валюту тарифа.');
            $key=$provider.'|'.$model;
            if(isset($seen[$key])) throw new \Core\UserError('Для одной модели можно задать только один тариф.');
            $seen[$key]=true;
            $rate=['provider'=>$provider,'model'=>$model,'currency'=>$currency];
            foreach(['input','output','request'] as $field){
                $value=$row[$field]??null;
                if($value===null || $value===''){$rate[$field]=null;continue;}
                if(!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value<0 || (float)$value>1000000)
                    throw new \Core\UserError('Цена должна быть числом от 0 до 1 000 000.');
                $rate[$field]=(float)$value;
            }
            $result[]=$rate;
        }
        return $result;
    }

    public static function price(array $measurement, array $rates): array
    {
        foreach($rates as $rate){
            if($rate['provider']!==$measurement['provider'] || $rate['model']!==$measurement['model'])continue;
            if($measurement['task']==='image' && $measurement['status']==='ok' && $rate['request']!==null)
                return ['estimated_cost'=>$rate['request'],'currency'=>$rate['currency']];
            if($measurement['input_tokens']!==null && $measurement['output_tokens']!==null && $rate['input']!==null && $rate['output']!==null)
                return ['estimated_cost'=>($measurement['input_tokens']*$rate['input']+$measurement['output_tokens']*$rate['output'])/1000000,'currency'=>$rate['currency']];
            break;
        }
        return ['estimated_cost'=>null,'currency'=>null];
    }

    public static function configuredRates(): array
    {
        $rows=json_decode((string)ConfigManager::getInstance()->getOption('ai_usage_rates','[]'),true);
        return self::rates(is_array($rows)?$rows:[]);
    }

    public function saveRates(array $rows): void
    {
        $rates=self::rates($rows);
        if(!ConfigManager::getInstance()->setOption('ai_usage_rates',json_encode($rates,JSON_THROW_ON_ERROR)))
            throw new \RuntimeException('Unable to save usage rates');
    }

    public function summary(int $days=30): array
    {
        $days=in_array($days,[7,30,90],true)?$days:30;
        $db=Database::getInstance()->getConnection();
        $rows=$db->query('SELECT provider,model,task,COUNT(*) attempts,SUM(status="error") errors,SUM(status="truncated") truncated,
            SUM(input_tokens) input_tokens,SUM(output_tokens) output_tokens,SUM(total_tokens) total_tokens,
            SUM(input_tokens IS NULL OR output_tokens IS NULL) unknown_usage,SUM(estimated_cost IS NULL) unknown_cost,
            SUM(estimated_cost) estimated_cost,currency
            FROM llm_usage WHERE created_at>=UTC_TIMESTAMP()-INTERVAL '.$days.' DAY
            GROUP BY provider,model,task,currency ORDER BY attempts DESC,provider,model,task')->fetch_all(MYSQLI_ASSOC);
        return ['days'=>$days,'rows'=>$rows,'rates'=>self::configuredRates()];
    }
}
