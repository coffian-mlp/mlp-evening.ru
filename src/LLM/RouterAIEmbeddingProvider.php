<?php
namespace LLM;

use Infra\ConfigManager;

/** Embeddings have their own deadline and never enter the chat fallback chain. */
final class RouterAIEmbeddingProvider implements EmbeddingProviderInterface
{
    public const MODEL = 'openai/text-embedding-3-small';
    public const DIMENSIONS = 512;
    private const ENDPOINT = 'https://routerai.ru/api/v1/embeddings';
    private $transport;
    private $meter;

    public function __construct(private string $key, private ?string $proxy = null, ?callable $transport = null, ?callable $meter = null)
    {
        $this->transport = $transport;
        $this->meter = $meter ?? static fn($raw,$code,$error,$duration) => UsageMeter::capture('routerai', self::MODEL, $raw, $code, $error, $duration, 'memory_embedding_index');
    }

    public static function fromConfig(): self
    {
        $config = ConfigManager::getInstance();
        $proxy = (string)$config->getOption('ai_proxy_url', '');
        if (str_starts_with($proxy, 'socks5://')) $proxy = 'socks5h://' . substr($proxy, 9);
        if (str_starts_with($proxy, 'vless://')) $proxy = 'socks5h://127.0.0.1:10808';
        return new self((string)$config->getOption('ai_routerai_key', ''), $proxy === '' ? null : $proxy);
    }

    public function profile(): string
    {
        return hash('sha256', self::ENDPOINT.'|'.self::MODEL.'|512|unit-float32-v1');
    }

    public function embed(array $texts, float $deadline): array
    {
        if (!$texts || count($texts) > 20 || $this->key === '') throw new \RuntimeException('Embedding input or configuration unavailable');
        foreach ($texts as $text) if (!is_string($text) || trim($text)==='' || mb_strlen($text)>6000) throw new \InvalidArgumentException('Invalid embedding text');
        return RequestDeadline::within($deadline, function () use ($texts) {
            $timeout = RequestDeadline::milliseconds(30);
            $payload = ['model'=>self::MODEL,'input'=>array_values($texts),'dimensions'=>self::DIMENSIONS,'encoding_format'=>'float'];
            $start = hrtime(true);
            if ($this->transport !== null) {
                [$raw,$code,$error] = ($this->transport)($payload, $timeout);
            } else {
                $handle = curl_init(self::ENDPOINT);
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR),
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->key,'Content-Type: application/json'],
                    CURLOPT_CONNECTTIMEOUT_MS=>min(10000,$timeout),CURLOPT_TIMEOUT_MS=>$timeout,
                    CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
                if ($this->proxy !== null) curl_setopt($handle,CURLOPT_PROXY,$this->proxy);
                $raw = curl_exec($handle);
                $code = (int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
                $error = curl_errno($handle)!==0;
                curl_close($handle);
            }
            ($this->meter)(is_string($raw)?$raw:null, $code, $error, (int)((hrtime(true)-$start)/1e6));
            if ($error || $code!==200 || !is_string($raw) || RequestDeadline::expired()) throw new \RuntimeException('Embedding request failed');
            $body = json_decode($raw,true);
            if (!is_array($body) || isset($body['error']) || !is_array($body['data']??null) || count($body['data'])!==count($texts)) throw new \RuntimeException('Invalid embedding response');
            $vectors = [];
            foreach ($body['data'] as $item) {
                $index = $item['index']??null;
                $vector = $item['embedding']??null;
                if (!is_int($index) || $index<0 || $index>=count($texts) || isset($vectors[$index]) || !is_array($vector) || !array_is_list($vector) || count($vector)!==self::DIMENSIONS) throw new \RuntimeException('Invalid embedding dimensions or index');
                $norm = 0.0;
                foreach ($vector as $value) {
                    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) throw new \RuntimeException('Invalid embedding number');
                    $norm += $value*$value;
                }
                if ($norm<=0 || !is_finite($norm)) throw new \RuntimeException('Invalid embedding norm');
                $norm = sqrt($norm);
                foreach ($vector as $i=>$value) $vector[$i]=$value/$norm;
                $vectors[$index]=$vector;
            }
            ksort($vectors);
            return array_values($vectors);
        });
    }
}
