<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});
require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/WebSocketProbe.php';

use phpcent\Client;
use phpcent\Integration\WebSocketProbe;

try {
    $url = getenv('PHPCENT_TEST_API_URL');
    $configFile = getenv('PHPCENT_TEST_CONFIG');
    if (!$url || !$configFile) { throw new RuntimeException('Set PHPCENT_TEST_API_URL and PHPCENT_TEST_CONFIG explicitly; this test publishes only to fresh random channels.'); }
    $config = json_decode(file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR);
    $key = $config['api_key'];
    $secret = $config['token_hmac_secret_key'];
    $client = (new Client($url, $key, $secret))->setUseAssoc(true)->setConnectTimeoutOption(2)->setTimeoutOption(3);
    $run = 'phpcent_test_'.bin2hex(random_bytes(10));
    $channels = [$run.'_a', $run.'_b'];
    $checks = [];
    $check = static function (bool $ok, string $name) use (&$checks): void { $checks[] = ['name'=>$name,'passed'=>$ok]; if (!$ok) { throw new RuntimeException($name); } };
    $accepted = static fn($reply): bool => is_array($reply) && !isset($reply['error']) && array_key_exists('result', $reply);
    // Independent server control: the existing SDK history(channel) has no limit option.
    $publications=static function(string $channel)use($url,$key):array{
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: apikey '.$key],
            CURLOPT_POSTFIELDS=>json_encode(['method'=>'history','params'=>['channel'=>$channel,'limit'=>100]],JSON_THROW_ON_ERROR),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3]);
        $raw=curl_exec($ch);if($raw===false||curl_getinfo($ch,CURLINFO_HTTP_CODE)!==200)throw new RuntimeException('History control request failed');
        $reply=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(isset($reply['error']))throw new RuntimeException('History control rejected');
        return $reply['result']['publications']??[];
    };
    $info = $client->info();
    $versions = array_values(array_unique(array_column($info['result']['nodes'] ?? [], 'version')));
    $check($accepted($info) && $versions && !array_filter($versions, static fn($v)=>!str_starts_with($v,'3.')), 'Centrifugo 3 server info');
    try { (new Client($url, 'invalid-test-key'))->info(); throw new LogicException('Invalid API key was accepted'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(), '401'), 'invalid API key rejected'); }
    $badNamespace = $client->publish($run.':missing_namespace', ['test_marker'=>$run]);
    $check(($badNamespace['error']['code'] ?? null) === 102, 'API error preserved rather than treated as delivery');
    $wsUrl = preg_replace('~^http~', 'ws', preg_replace('~/api/?$~', '/connection/websocket', $url));
    $token = $client->generateConnectionToken($run, time()+60, [], $channels);
    $syncMarker=$run.'_publish';$broadcastMarker=$run.'_broadcast';$asyncMarker=$run.'_async';
    $expected=[
        'publish'=>['channel'=>$channels[0],'marker'=>$syncMarker],
        'broadcast_a'=>['channel'=>$channels[0],'marker'=>$broadcastMarker],
        'broadcast_b'=>['channel'=>$channels[1],'marker'=>$broadcastMarker],
        'async_publish'=>['channel'=>$channels[0],'marker'=>$asyncMarker],
    ];
    $asyncAccepted=false;$asyncFailure=null;
    $received=WebSocketProbe::receive($wsUrl,$token,$expected,static function()use($client,$channels,$check,$accepted,$syncMarker,$broadcastMarker,$asyncMarker,&$asyncAccepted,&$asyncFailure):void{
        $presence=$client->presence($channels[0]);$stats=$client->presenceStats($channels[0]);
        $check($accepted($presence)&&count($presence['result']['presence']??[])===1,'isolated receiver is present');
        $check(($stats['result']['num_clients']??0)===1&&($stats['result']['num_users']??0)===1,'isolated receiver presence stats');
        $check($accepted($client->publish($channels[0],['test_marker'=>$syncMarker])),'sync publish accepted');
        $check($accepted($client->broadcast($channels,['test_marker'=>$broadcastMarker])),'broadcast accepted');
        $client->setAsync(true,static function($reply)use(&$asyncAccepted,$accepted):void{$asyncAccepted=$accepted($reply);},static function($e)use(&$asyncFailure):void{$asyncFailure=$e;});
        $check($client->publish($channels[0],['test_marker'=>$asyncMarker])===[],'async immediate return contract');
    },static function()use(&$asyncAccepted,&$asyncFailure):bool{return $asyncAccepted||$asyncFailure!==null;});
    $check($asyncFailure===null&&$asyncAccepted,'async provider reply accepted');
    $check(count($received['received'])===4,'all four publications received over real WebSocket');
    $client->setAsync(false);
    foreach($channels as$channel){
        // Centrifugo 3 history without limit intentionally returns stream position only.
        $history=$client->history($channel);$check($accepted($history)&&($history['result']['offset']??0)>0&&is_string($history['result']['epoch']??null),'history stream position matches the existing SDK contract');
        $check(count($publications($channel))>0,'explicit limit control confirms isolated publications retained');
        $removed=$client->historyRemove($channel);
        $check($removed===[]||$accepted($removed),'empty successful history_remove reply preserved');
        $check($publications($channel)===[],'isolated test history actually cleared');
    }
    echo json_encode(['php'=>PHP_VERSION,'mask'=>error_reporting(),'server_versions'=>$versions,'checks'=>$checks,'websocket'=>$received,
        'channel_policy'=>'fresh random channels; no business users','passed'=>true],JSON_THROW_ON_ERROR),PHP_EOL;
} catch(Throwable $e) {
    $cleanupFailures=[];
    if(isset($client,$channels)){
        $client->setAsync(false);
        foreach($channels as$channel){try{$client->historyRemove($channel);}catch(Throwable $cleanupError){$cleanupFailures[]=['class'=>get_class($cleanupError),'error'=>$cleanupError->getMessage()];}}
    }
    fwrite(STDERR,json_encode(['passed'=>false,'checks'=>$checks??[],'error'=>$e->getMessage(),'class'=>get_class($e),'cleanup_failures'=>$cleanupFailures],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
