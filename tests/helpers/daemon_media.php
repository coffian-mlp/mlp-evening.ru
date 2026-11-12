<?php
/** Advancing local MediaMTX/HLS fixture, no external services. */
if(!is_file('/.dockerenv')||!is_file('/var/www/html/.mlp461-owned')){http_response_code(404);exit;}
if(str_contains($_SERVER['REQUEST_URI'],'/v3/paths/get/')){header('Content-Type: application/json');echo '{"ready":true}';return;}
header('Content-Type: application/vnd.apple.mpegurl');
echo "#EXTM3U\n#EXT-X-TARGETDURATION:2\n#EXT-X-MEDIA-SEQUENCE:".time()."\n#EXTINF:2,\nsegment_".time().".ts\n";
