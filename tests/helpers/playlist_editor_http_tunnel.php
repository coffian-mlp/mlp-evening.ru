<?php
if(PHP_SAPI!=='cli'||!is_file(dirname(__DIR__,2).'/.mlp472-owned')||!is_file('/.dockerenv'))exit(2);
$request=stream_get_contents(STDIN,16777217);
if(strlen($request)>16777216||!preg_match('~^(?:GET|POST|HEAD) /[^\r\n]* HTTP/1\.[01]\r\n~',$request))exit(2);
$socket=fsockopen('127.0.0.1',8173,$code,$message,5);
if(!$socket)exit(3);stream_set_timeout($socket,100);
$offset=0;while($offset<strlen($request)){$sent=fwrite($socket,substr($request,$offset));if(!$sent)exit(4);$offset+=$sent;}
while(!feof($socket)){$chunk=fread($socket,65536);if($chunk===false)break;echo$chunk;}
fclose($socket);
