<?php
/** Every full run/server must prove that the test-only network guard was actually loaded. */
if(!is_file('/.dockerenv')||!is_file(dirname(__DIR__,2).'/.mlp456-owned'))throw new RuntimeException('Disposable environment required');
$active=in_array('curl_exec',explode(',',ini_get('disable_functions')),true)
    &&function_exists('curl_exec')&&!(new ReflectionFunction('curl_exec'))->isInternal()
    &&class_exists('ConversationBlockedNetworkStream',false)
    &&realpath(ini_get('auto_prepend_file'))===realpath(__DIR__.'/conversation_network_guard.php');
if(!$active)throw new RuntimeException('Synthetic network guard is inactive');
echo "ISOLATION GUARD ACTIVE\n";
