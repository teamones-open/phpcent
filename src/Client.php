<?php

namespace phpcent;

use Workerman\Http\Client as WorkermanHttpClient;

/**
 * Centrifugo API Client
 *
 * @package    phpcent
 * @copyright  Copyright (c) 2019 Centrifugal
 * @license    The MIT License (MIT)
 */
class Client
{
    private $url;
    private $apikey;
    private $secret;

    private $cert;
    private $caPath;

    private $connectTimeoutOption;
    private $timeoutOption;
    private $forceIpResolveV4;

    private $safety = true;
    private $useAssoc = false;

    private $workermanHttpClient = null;
    private $async = false;
    private $onSuccess;
    private $onError;

    /**
     * Construct new Client instance.
     *
     * @param string $url Centrifugo API endpoint
     * @param string $apikey Centrifugo API key
     * @param string $secret Centrifugo secret key.
     *
     */
    public function __construct($url, $apikey = '', $secret = '')
    {
        $this->url = $url;
        $this->apikey = $apikey;
        $this->secret = $secret;
    }

    /**
     * Opt into callback-based Workerman requests. Synchronous mode is the default.
     * Async requests return [] immediately; callbacks receive the decoded reply/error.
     */
    public function setAsync(bool $async, ?callable $onSuccess = null, ?callable $onError = null)
    {
        $this->async = $async;
        $this->onSuccess = $onSuccess;
        $this->onError = $onError;
        return $this;
    }

    /**
     * @param string $key
     * @return Client
     */
    public function setApiKey($key)
    {
        $this->apikey = $key;
        return $this;
    }

    /**
     * @param string $secret
     * @return Client
     */
    public function setSecret($secret)
    {
        $this->secret = $secret;
        return $this;
    }

    /**
     * @param bool $caPath
     * @return Client
     */
    public function setSafety($safety)
    {
        $this->safety = $safety;
        return $this;
    }

    /**
     * @param bool $useAssoc
     * @return Client
     */
    public function setUseAssoc($useAssoc)
    {
        $this->useAssoc = $useAssoc;
        return $this;
    }

    /**
     * @param string $cert
     * @return Client
     */
    public function setCert($cert)
    {
        $this->cert = $cert;
        return $this;
    }

    /**
     * @param string $caPath
     * @return Client
     */
    public function setCAPath($caPath)
    {
        $this->caPath = $caPath;
        return $this;
    }

    /**
     * @param int $connectTimeoutOption
     * @return Client
     */
    public function setConnectTimeoutOption($connectTimeoutOption)
    {
        $this->connectTimeoutOption = $connectTimeoutOption;
        return $this;
    }

    /**
     * @param int $timeoutOption
     * @return Client
     */
    public function setTimeoutOption($timeoutOption)
    {
        $this->timeoutOption = $timeoutOption;
        return $this;
    }

    /**
     * Forces DNS to only resolve IPv4 addresses.
     *
     * @return Client
     */
    public function forceIpResolveV4()
    {
        $this->forceIpResolveV4 = true;
        return $this;
    }

    /**
     * Publish data into channel.
     *
     * @param string $channel
     * @param array $data
     * @return mixed
     */
    public function publish($channel, $data)
    {
        return $this->send('publish', array(
            'channel' => $channel,
            'data' => $data,
        ));
    }

    /**
     * Broadcast the same data into multiple channels.
     *
     * @param array $channels
     * @param array $data
     * @return mixed
     */
    public function broadcast($channels, $data)
    {
        return $this->send('broadcast', array(
            'channels' => $channels,
            'data' => $data,
        ));
    }

    /**
     * Unsubscribe user from channel.
     *
     * @param string $channel
     * @param string $user
     * @return mixed
     */
    public function unsubscribe($channel, $user)
    {
        return $this->send('unsubscribe', array(
            'channel' => $channel,
            'user' => $user,
        ));
    }

    /**
     * Disconnect user.
     *
     * @param string $user
     * @return mixed
     */
    public function disconnect($user)
    {
        return $this->send('disconnect', array(
            'user' => $user,
        ));
    }

    /**
     * Get channel presence info.
     *
     * @param string $channel
     * @return mixed
     */
    public function presence($channel)
    {
        return $this->send('presence', array(
            'channel' => $channel,
        ));
    }

    /**
     * Get channel presence stats.
     * Deprecated: use presenceStats instead.
     *
     * @param string $channel
     * @return mixed
     */
    public function presence_stats($channel)
    {
        return $this->presenceStats($channel);
    }

    /**
     * Get channel presence stats.
     *
     * @param string $channel
     * @return mixed
     */
    public function presenceStats($channel)
    {
        return $this->send('presence_stats', array(
            'channel' => $channel,
        ));
    }

    /**
     * Get channel history.
     *
     * @param string $channel
     * @return mixed
     */
    public function history($channel)
    {
        return $this->send('history', array(
            'channel' => $channel,
        ));
    }

    /**
     * Remove channel history.
     * Deprecated: use historyRemove instead.
     *
     * @param string $channel
     * @return mixed
     */
    public function history_remove($channel)
    {
        return $this->historyRemove($channel);
    }

    /**
     * Remove channel history.
     *
     * @param string $channel
     * @return mixed
     */
    public function historyRemove($channel)
    {
        return $this->send('history_remove', array(
            'channel' => $channel,
        ));
    }

    /**
     * Get all active channels.
     *
     * @return mixed
     */
    public function channels()
    {
        return $this->send('channels');
    }

    /**
     * Get server info.
     *
     * @return mixed
     */
    public function info()
    {
        return $this->send('info');
    }

    /**
     * Generate connection JWT.
     *
     * @param string $userId
     * @param int $exp
     * @param array $info
     * @param array $channels
     * @return string
     */
    public function generateConnectionToken($userId = '', $exp = 0, $info = array(), $channels = array())
    {
        $header = array('typ' => 'JWT', 'alg' => 'HS256');
        $payload = array('sub' => (string) $userId);
        if (!empty($info)) {
            $payload['info'] = $info;
        }
        if (!empty($channels)) {
            $payload['channels'] = $channels;
        }
        if ($exp) {
            $payload['exp'] = $exp;
        }
        $segments = array();
        $segments[] = $this->urlsafeB64Encode(json_encode($header));
        $segments[] = $this->urlsafeB64Encode(json_encode($payload));
        $signing_input = implode('.', $segments);
        $signature = $this->sign($signing_input, $this->secret);
        $segments[] = $this->urlsafeB64Encode($signature);
        return implode('.', $segments);
    }

    /**
     * Generate private channel JWT.
     *
     * @param string $client
     * @param string $channel
     * @param int $exp
     * @param array $info
     * @return string
     */
    public function generatePrivateChannelToken($client, $channel, $exp = 0, $info = array())
    {
        $header = array('typ' => 'JWT', 'alg' => 'HS256');
        $payload = array('channel' => (string)$channel, 'client' => (string)$client);
        if (!empty($info)) {
            $payload['info'] = $info;
        }
        if ($exp) {
            $payload['exp'] = $exp;
        }
        $segments = array();
        $segments[] = $this->urlsafeB64Encode(json_encode($header));
        $segments[] = $this->urlsafeB64Encode(json_encode($payload));
        $signing_input = implode('.', $segments);
        $signature = $this->sign($signing_input, $this->secret);
        $segments[] = $this->urlsafeB64Encode($signature);
        return implode('.', $segments);
    }

    /*
     * Function added for backward compatibility with PHP version < 5.5
     */

    public function _json_last_error_msg() {
        if (function_exists('json_last_error_msg')) {
            return json_last_error_msg();
        }
        static $ERRORS = array(
            JSON_ERROR_NONE => 'No error',
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'State mismatch (invalid or malformed JSON)',
            JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded'
        );

        $error = json_last_error();
        return isset($ERRORS[$error]) ? $ERRORS[$error] : 'Unknown error';
    }

    private function send($method, $params = array())
    {
        if ($this->async) {
            return $this->asyncRequest($method, $params);
        }
        return $this->decodeResponse($this->request($method, $params));
    }

    private function decodeResponse($body)
    {
        return json_decode($body, $this->useAssoc, 512, JSON_THROW_ON_ERROR);
    }

    private function encodeCommand($method, $params)
    {
        // Centrifugo 3 uses /api with a method/params envelope, not /api/<method>.
        return json_encode(array('method' => $method, 'params' => (object)$params), JSON_THROW_ON_ERROR);
    }

    private function urlsafeB64Encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private function sign($msg, $key)
    {
        return hash_hmac('sha256', $msg, $key, true);
    }

    private function request($method, $params)
    {
        $body = $this->encodeCommand($method, $params);
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL');
        }
        try {
            if ($this->connectTimeoutOption !== null) {
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeoutOption);
            }
            if ($this->timeoutOption !== null) {
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutOption);
            }
            if ($this->forceIpResolveV4) {
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->safety);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->safety ? 2 : 0);
            if ($this->safety && $this->cert) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->cert);
            }
            if ($this->safety && $this->caPath) {
                curl_setopt($ch, CURLOPT_CAPATH, $this->caPath);
            }
            curl_setopt($ch, CURLOPT_USERAGENT, 'teamones-phpcent/5');
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
            curl_setopt($ch, CURLOPT_URL, $this->url);
            $data = curl_exec($ch);
            $error = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($data === false || $status !== 200) {
                throw new \RuntimeException('Centrifugo HTTP status: ' . $status . '; cURL error: ' . $error);
            }
            return $data;
        } finally {
            // PHP 8.5 releases CurlHandle automatically and deprecates curl_close().
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
        }
    }

    private function getHeaders()
    {
        return array(
            'Content-Type: application/json',
            'Authorization: apikey ' . $this->apikey,
        );
    }

    private function asyncRequest($method, $params)
    {
        $body = $this->encodeCommand($method, $params);
        if ($this->workermanHttpClient === null) {
            $options = array();
            if ($this->connectTimeoutOption !== null) $options['connect_timeout'] = $this->connectTimeoutOption;
            if ($this->timeoutOption !== null) $options['timeout'] = $this->timeoutOption;
            $ssl = array('verify_peer' => $this->safety, 'verify_peer_name' => $this->safety);
            if ($this->cert) $ssl['cafile'] = $this->cert;
            if ($this->caPath) $ssl['capath'] = $this->caPath;
            $options['context'] = array('ssl' => $ssl);
            $this->workermanHttpClient = new WorkermanHttpClient($options);
        }
        $headers = array();
        foreach ($this->getHeaders() as $header) {
            list($name, $value) = explode(':', $header, 2);
            $headers[$name] = trim($value);
        }
        $success = $this->onSuccess;
        $error = $this->onError;
        $assoc = $this->useAssoc;
        $handleError = static function (\Throwable $exception) use ($error) {
            if ($error !== null) {
                $error($exception);
                return;
            }
            throw $exception;
        };
        $this->workermanHttpClient->request($this->url, array(
            'method' => 'POST',
            'version' => '1.1',
            'headers' => $headers,
            'data' => $body,
            'success' => static function ($response) use ($success, $handleError, $assoc) {
                try {
                    if ($response->getStatusCode() !== 200) {
                        throw new \RuntimeException('Centrifugo HTTP status: ' . $response->getStatusCode());
                    }
                    $reply = json_decode((string)$response->getBody(), $assoc, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable $exception) {
                    $handleError($exception);
                    return;
                }
                if ($success !== null) $success($reply);
            },
            'error' => $handleError,
        ));
        return array();
    }
}
