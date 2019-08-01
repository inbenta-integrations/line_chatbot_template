<?php
namespace Inbenta\LineConnector\ExternalAPI;

use Exception;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

class LineAPIClient
{
    /**
     * The line API URL.
     *
     * @var string
     */
    protected $line = 'https://api.line.me/v2/';

    /**
     * The Line's channel id.
     *
     * @var string|null
     */
    protected $channel_id;

    /**
     * The Line's channel secret.
     *
     * @var string|null
     */
    protected $channel_secret;

    /**
     * The Line destination id.
     *
     * @var string|null
     */
    public $switcher_destination;

    /**
     * The Line's switcher channel secret.
     *
     * @var string|null
     */
    protected $switcher_secret;

    /**
     * The Line service code.
     *
     * @var string|null
     */
    public $service_code;

    /**
     * The Line's signature header.
     *
     * @var string|null
     */
    protected $signature;

    /**
     * The Line's accessToken.
     *
     * @var string|null
     */
    protected $accessToken;

    /**
     * Line's accessToken time to live.
     *
     * @var string|null
     */
    protected $ttl;

    /**
     * Either a Line's roomId, groupId or userId key + it's value.
     *
     * @var array|null
     */
    public $replyId;

    /**
     * The Line app id.
     *
     * @var string|null
     */
    public $lineReplyToken;

    /**
     * To construct path to cache files.
     *
     * @var string|null
     */
    protected $cachePath;

    /**
     * Offset time in seconds before asking for an access token refresh
     *
     * @var string|ingeter
     */
    const TOKEN_REFRESH_OFFSET  = 180;

    /**
     * Create a new instance.
     *
     * @param string|null $jwt
     * @param string|null $request
     */

    public function __construct($channel_id = null, $channel_secret = null, $switcher_destination = null, $switcher_secret = null, $service_code = null, $request = null, $signature = null)
    {
        $this->channel_id = $channel_id;
        $this->channel_secret = $channel_secret;
        $this->switcher_destination = $switcher_destination;
        $this->switcher_secret = $switcher_secret;
        $this->service_code = $service_code;
        // Messages from Line are json strings
        if (isset($request->events)) {
            // If the request comes form a room, reply to the room, else if it comes to a group, reply to the group, else reply to the single user
            $sourceType = $request->events[0]->source->type . 'Id';
            $sourceId = $request->events[0]->source->$sourceType;
            $this->replyId = array('key' => $sourceType, 'value' => $sourceId);

            $this->lineReplyToken = $request->events[0]->replyToken;

            // Set access token cache file
            $this->cachePath = rtrim(sys_get_temp_dir(), '/') . '/';
            $this->cachedLineAccessTokenFile = $this->cachePath . "cached-line-accesstoken-" . preg_replace("/[^A-Za-z0-9 ]/", '', $this->replyId['value']);
            // Update or create access token
            $this->updateLineAccessToken();
        } else {
            return;
        }
    }

    /**
     * Send a request to the Line API.
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    protected function line($method, $uri, array $options = [])
    {
        if (is_null($this->accessToken)) {
            throw new Exception('No line access token');
        }

        $guzzle = new Guzzle([
            'base_uri' => $this->line,
        ]);
        $headers = array('Authorization' => "Bearer " . $this->accessToken);

        if ($this->switcher_secret) $headers['X-Line-SwitcherSecret'] = $this->switcher_secret;
        if ($this->service_code) $headers['X-Line-ServiceCode'] = $this->service_code;

        $response = $guzzle->request($method, $uri, array_merge_recursive($options, [
            'headers' => $headers
        ]));
        return $response;
    }

    /**
     * Send an outgoing message.
     *
     * @param array $message
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function send($message)
    {
        $answer['to'] = $this->replyId['value'];
        $messageList = array_chunk($message,5);
        foreach ($messageList as $messages) {
            $answer['messages'] = $messages;
            $response = $this->line('POST', 'bot/message/push', [
                'json' => $answer,
            ]);
        }
    }

    /**
     * Send switcher-switch request to manual reply partner
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendSwitcherEvent()
    {
        $switchParams = array('destinationId' => $this->switcher_destination);

        $switchParams[$this->replyId['key']] = $this->replyId['value'];

        $response = $this->line('POST', 'bot/admin/switcher/switch', [
            'json' => $switchParams,
        ]);
        return $response;
    }

    /**
     * Send switcher-notify request to manual reply partner
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendSwitcherNotice()
    {
        $switchParams = array('destinationId' => $this->switcher_destination);

        $switchParams[$this->replyId['key']] = $this->replyId['value'];

        $response = $this->line('POST', 'bot/admin/switcher/notice', [
            'json' => $switchParams,
        ]);
        return $response;
    }

    /**
    *   Generates the external id used by HyperChat to identify one user as external.
    *   This external id will be used by HyperChat adapter to instance this client class from the external id
    */
    public function getExternalId()
    {
        return 'line-' . $this->replyId['value'];
    }

    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'));
        if (isset($request->events) && isset($request->events[0]) && isset($request->events[0]->source)) {
            $sourceType = $request->events[0]->source->type . 'Id';
            $sourceId = $request->events[0]->source->$sourceType;
            return 'line-' . $sourceId;
        }
        return null;
    }

    /**
    *   Sends a flag to Line to display a notification alert as the bot is 'writing'
    *   This method can be used to disable the notification if a 'false' parameter is received
    */
    public function showBotTyping($show = true)
    {
        // Line does not support bot typing
    }

    /**
    *   Sends a message to Line. Needs a message formatted with the Line notation
    */
    public function sendMessage($message)
    {
        $messageSend = $this->send($message);
        return $messageSend;
    }


    /********************** LINE ACCESS TOKEN MANAGEMENT **********************/

    /**
     *  Update accessToken
     */
    protected function updateLineAccessToken()
    {
        // Update access token if needed
        if (!$this->validLineAccessToken()) {
            // Get the access token from cache
            $this->getLineAccessTokenFromCache();
            // Get a new access token from API if it doesn't exists or it's expired
            if (!$this->validLineAccessToken()) {
                $this->getLineAccessTokenFromAPI();
            }
        }
    }

    /**
     *  Validate accessToken
     */
    protected function validLineAccessToken() {
        return !is_null($this->accessToken) && !is_null($this->ttl) && $this->ttl > time() * 1000;
    }

    /**
     *  Get the accessToken information from cache
     */
    protected function getLineAccessTokenFromCache()
    {
        $cachedLineAccessToken          = file_exists($this->cachedLineAccessTokenFile) ? json_decode(file_get_contents($this->cachedLineAccessTokenFile)) : null;
        $cachedLineAccessTokenExpired   = is_object($cachedLineAccessToken) && !empty($cachedLineAccessToken) ? $cachedLineAccessToken->expiration < time() * 1000 : true;
        if (is_object($cachedLineAccessToken) && !empty($cachedLineAccessToken) && !$cachedLineAccessTokenExpired) {
            $this->accessToken = $cachedLineAccessToken->access_token;
            $this->ttl = $cachedLineAccessToken->expiration;
        }
    }

    /**
     *  Get the accessToken information from Line's API
     */
    protected function getLineAccessTokenFromAPI()
    {
        $headers = array("Content-Type: application/x-www-form-urlencoded");
        $params = "grant_type=client_credentials" .
            "&client_id=" . $this->channel_id .
            "&client_secret=" . $this->channel_secret;
        $accessInfo = $this->call("oauth/accessToken","POST", $headers, $params);
        // Expiration date added instead ttl
        $accessInfo->expiration = (time() + $accessInfo->expires_in) * 1000;
        if (isset($accessInfo->messsage) && $accessInfo->message == 'Unauthorized') {
          throw new Exception("Invalid key/secret");
        }
        $this->accessToken  = $accessInfo->access_token;
        $this->ttl          = $accessInfo->expiration;
        file_put_contents($this->cachedLineAccessTokenFile, json_encode($accessInfo));
    }


    protected function call($path, $method, $headers = array(), $params = '')
    {
        $curl = curl_init();
        $opts = array(
            CURLOPT_URL => $this->line.$path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POST => ($method === "POST") ? 1 : 0,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_HTTPHEADER => $headers,
        );

        curl_setopt_array($curl,$opts);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return $err;
        } else {
            return json_decode($response);
        }
    }

}
