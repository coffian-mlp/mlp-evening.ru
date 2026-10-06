<?php
namespace LLM {
    /** External HTTP seam, loaded only by the isolated CLI live fixture. */
    function curl_init($url) { return new \stdClass(); }
    function curl_setopt($handle, $option, $value) { $GLOBALS['mlp363_options'][$option]=$value; return true; }
    function curl_exec($handle) {
        $GLOBALS['mlp363_calls']=($GLOBALS['mlp363_calls'] ?? 0)+1;
        $GLOBALS['mlp363_payload']=json_decode($GLOBALS['mlp363_options'][CURLOPT_POSTFIELDS],true);
        return json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'Жми на кнопочку под ответом — этот выбор за тобой!']]]]);
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}
