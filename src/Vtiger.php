<?php
namespace Clystnet\Vtiger;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;
use Mockery\CountValidator\Exception;
use Psr\Http\Message\ResponseInterface;
use Storage;
use Config;
use Redis;

/**
 * Laravel wrapper for the VTgier API
 *
 * Class Vtiger
 * @package Clystnet\Vtiger
 */
class Vtiger
{

    /** @var string */
    protected $url, $username, $accesskey, $sessionDriver, $persistConnection;

    /** @var Client */
    protected $client;

    /** @var int */
    protected $maxRetries;

    /**
     * Vtiger constructor.
     */
    public function __construct()
    {
        // set the API url and username
        $this->url = Config::get('vtiger.url');
        $this->username = Config::get('vtiger.username');
        $this->accesskey = Config::get('vtiger.accesskey');
        $this->sessionDriver = Config::get('vtiger.sessiondriver');
        $this->persistConnection = Config::get('vtiger.persistconnection');
        $this->maxRetries = Config::get('vtiger.max_retries');

        $this->client = new Client(['http_errors' => false, 'verify' => false]); //GuzzleHttp\Client
    }

    /**
     * Call this function if you wish to override the default connection
     *
     * @param string $url
     * @param string $username
     * @param string $accesskey
     *
     * @return $this
     */
    public function connection($url, $username, $accesskey)
    {
        $this->url = $url;
        $this->username = $username;
        $this->accesskey = $accesskey;

        return $this;
    }

    /**
     * Get the session id for a login either from a stored session id or fresh from the API
     *
     * @return string
     * @throws GuzzleException
     * @throws VtigerError
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function sessionId()
    {

        // Check the session file exists
        switch ($this->sessionDriver) {
            case "file":
                if (Storage::disk('local')->exists('session.json')) {
                    $sessionData = json_decode(Storage::disk('local')->get('session.json'));
                }
                break;
            case "redis":
                $sessionData = json_decode(Redis::get('clystnet_vtiger'));
                break;
            default:
                throw new VtigerError("Session driver type of ".$this->sessionDriver." is not supported", 4);
        }

        if (isset($sessionData)) {
            if (isset($sessionData) && property_exists($sessionData, 'expireTime') && property_exists($sessionData, 'token')) {
                if ($sessionData->expireTime < time() || empty($sessionData->token)) {
                    $sessionData = $this->storeSession();
                }
            } else {
                $sessionData = $this->storeSession();
            }
        } else {
            $sessionData = $this->storeSession();
        }

        if (isset($sessionData->sessionid)) {
            $sessionId = $sessionData->sessionid;
        } else {
            $sessionId = $this->login($sessionData);
        }

        return $sessionId;

    }

    /**
     * Login to the VTiger API to get a new session
     *
     * @param object $sessionData
     *
     * @return string
     * @throws GuzzleException
     * @throws VtigerError
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function login($sessionData)
    {

        $sessionId = null;
        $token = $sessionData->token;

        // Create unique key using combination of challengetoken and accesskey
        $generatedKey = md5($token . $this->accesskey);

        $tryCounter = 1;
        do {
            // login using username and accesskey
            /** @var ResponseInterface $response */
            $response = $this->client->request('POST', $this->url, [
                'form_params' => [
                    'operation' => 'login',
                    'username' => $this->username,
                    'accessKey' => $generatedKey
                ]
            ]);

            // decode the response
            $loginResult = $this->_processResponse($response);
            $tryCounter++;
        } while (!isset($loginResult->success) && $tryCounter <= $this->maxRetries);

        if ($tryCounter >= $this->maxRetries) {
            throw new VtigerError("Could not complete login request within ".$this->maxRetries." tries", 5);
        }

        // If api login failed
        if ($response->getStatusCode() !== 200 || !$loginResult->success) {
            if (!$loginResult->success) {
                if ($loginResult->error->code == "INVALID_USER_CREDENTIALS" || $loginResult->error->code == "INVALID_SESSIONID") {
                    if ($this->sessionDriver == 'file') {
                        if (Storage::disk('local')->exists('session.json')) {
                            Storage::disk('local')->delete('session.json');
                        }
                    } elseif ($this->sessionDriver == 'redis') {
                        Redis::del('clystnet_vtiger');
                    }
                } else {
                    $this->_processResult($response);
                }
            } else {
                $this->_checkResponseStatusCode($response);
            }
        } else {
            // login ok so get sessionid and update our session
            $sessionId = $loginResult->result->sessionName;

            switch ($this->sessionDriver) {
                case "file":
                    if (Storage::disk('local')->exists('session.json')) {
                        $json = json_decode(Storage::disk('local')->get('session.json'));
                        $json->sessionid = $sessionId;
                        Storage::disk('local')->put('session.json', json_encode($json));
                    }
                    break;
                case "redis":
                    Redis::incr('loggedin');
                    $json = json_decode(Redis::get('clystnet_vtiger'));
                    $json->sessionid = $sessionId;
                    Redis::set('clystnet_vtiger', json_encode($json));
                    break;
                default:
                    throw new VtigerError("Session driver type of ".$this->sessionDriver." is not supported", 4);
            }
        }

        return $sessionId;

    }

    /**
     * Store a new session if needed
     *
     * @return object
     * @throws GuzzleException
     * @throws VtigerError
     */
    protected function storeSession()
    {

        $updated = $this->getToken();

        $output = (object)$updated;
        if ($this->sessionDriver == 'file') {
            Storage::disk('local')->put('session.json', json_encode($output));
        } elseif ($this->sessionDriver == 'redis') {
            Redis::set('clystnet_vtiger', json_encode($output));
        }

        return $output;

    }

    /**
     * Get a new access token from the VTiger API
     *
     * @return array
     * @throws GuzzleException
     * @throws VtigerError
     */
    protected function getToken()
    {

        // perform API GET request
        $tryCounter = 1;
        do {
            $response = $this->client->request('GET', $this->url, [
                'query' => [
                    'operation' => 'getchallenge',
                    'username' => $this->username
                ]
            ]);

            $tryCounter++;
        } while (!isset($this->_processResponse($response)->success) && $tryCounter <= $this->maxRetries);

        if ($tryCounter >= $this->maxRetries) {
            throw new VtigerError("Could not complete get token request within ".$this->maxRetries." tries", 6);
        }

        // decode the response
        $challenge = $this->_processResult($response);

        // Everything ok so create a token from response
        $output = array(
            'token' => $challenge->result->token,
            'expireTime' => $challenge->result->expireTime,
        );

        return $output;

    }

    /**
     * Logout from the VTiger API
     *
     * @param string $sessionId
     *
     * @return object
     * @throws GuzzleException
     * @throws VtigerError
     */
    protected function close($sessionId)
    {

        if ($this->persistConnection) {
            return true;
        }

        // send a request to close current connection
        $response = $this->client->request('POST', $this->url, [
            'query' => [
                'operation' => 'logout',
                'sessionName' => $sessionId
            ]
        ]);

        return $this->_processResult($response);

    }

    /**
     * Query the VTiger API with the given query string
     *
     * @param string $query
     *
     * @return object
     * @throws GuzzleException
     * @throws VtigerError
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function query($query)
    {

        $sessionId = $this->sessionId();

        // send a request using a database query to get back any matching records
        $response = $this->client->request('GET', $this->url, [
            'query' => [
                'operation' => 'query',
                'sessionName' => $sessionId,
                'query' => $query
            ]
        ]);

        $this->close($sessionId);

        return $this->_processResult($response);

    }

    /**
     * Retreive a record from the VTiger API
     * Format of id must be {moudler_code}x{item_id}, e.g 4x12
     *
     * @param string $id
     *
     * @return object
     * @throws GuzzleException
     * @throws VtigerError
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function retrieve($id)
    {

        $sessionId = $this->sessionId();

        // send a request to retrieve a record
        $response = $this->client->request('GET', $this->url, [
            'query' => [
                'operation' => 'retrieve',
                'sessionName' => $sessionId,
                'id' => $id
            ]
        ]);

        $this->close($sessionId);

        return $this->_processResult($response);

    }

    /**
     * Create a new entry in the VTiger API
     *
     * To insert a record into the CRM, first create an array of data to insert.
     * Don't forget the added the id of the `assigned_user_id` (i.e. '4x12') otherwise the insert will fail
     * as `assigned_user_id` is a mandatory field.
     *
     * $data = array(
     *     'assigned_user_id' => '',
     * );
     *
     * To do the actual insert, pass the module name along with the json encoded array.
     *
     * @param string $elem
     * @param string $data
     *
     * @return object
     * @throws GuzzleException
     * @throws VtigerError
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function create($elem, $data)
    {

        $sessionId = $this->sessionId();

        // send a request to create a record
        $response = $this->client->request('POST', $this->url, [
            'form_params' => [
                'operation' => 'create',
                'sessionName' => $sessionId,
                'element' => $data,
                'elementType' => $elem
            ]
        ]);

        $this->close($sessionId);

        return $this->_processResult($response);

    }

    /**
     * Update an entry in the database from the given object
     *
     * The object should be an object retreived from the database and then altered
     *
     * @param $object
     *
     * @return object
     * @throws GuzzleException
     * @throws VtigerError
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function update($object)
    {

        $sessionId = $this->sessionId();

        // send a request to update a record
        $response = $this->client->request('POST', $this->url, [
            'form_params' => [
                'operation' => 'update',
                'sessionName' => $sessionId,
                'element' => json_encode($object),
            ]
        ]);

        $this->close($sessionId);

        return $this->_processResult($response);

    }

    /**
     * Delete from the database using the given id
     * Format of id must be {moudler_code}x{item_id}, e.g 4x12
     *
     * @param $id
     *
     * @return object
     * @throws GuzzleException
     * @throws VtigerError
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function delete($id)
    {

        $sessionId = $this->sessionId();

        // send a request to delete a record
        $response = $this->client->request('GET', $this->url, [
            'query' => [
                'operation' => 'delete',
                'sessionName' => $sessionId,
                'id' => $id
            ]
        ]);

        $this->close($sessionId);

        return $this->_processResult($response);

    }

    /**
     * Describe an element from the vTiger API from the given element name
     *
     * @param string $elementType
     *
     * @return object
     * @throws GuzzleException
     * @throws VtigerError
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function describe($elementType)
    {

        $sessionId = $this->sessionId();

        // send a request to describe a module (which returns a list of available fields) for a Vtiger module
        $response = $this->client->request('GET', $this->url, [
            'query' => [
                'operation' => 'describe',
                'sessionName' => $sessionId,
                'elementType' => $elementType
            ]
        ]);

        $this->close($sessionId);

        return $this->_processResult($response);

    }

    /**
     * Process the response from the API for errors
     *
     * @param mixed|ResponseInterface $response
     *
     * @return object
     * @throws VtigerError
     */
    protected function _processResult($response)
    {

        $this->_checkResponseStatusCode($response);

        $data = $this->_processResponse($response);

        if (!isset($data->success)) {
            throw new VtigerError("Success property not set on VTiger response", 2);
        }

        if ($data->success == false) {
            $this->_processResponseError($data);
        }

        return $data;

    }

    /**
     * Get the json decoded response from either the body or the contents
     *
     * @param ResponseInterface $response
     *
     * @return object
     */
    protected function _processResponse($response)
    {

        // decode the response
        if (!empty($response->getBody()->getContents())) {
            $response->getBody()->rewind();
            $data = json_decode($response->getBody()->getContents());
        } else {
            $data = json_decode($response->getBody());
        }

        return $data;

    }

    /**
     * Check the response code to make sure it isn't anything but 200
     *
     * @param mixed|ResponseInterface $response
     *
     * @throws VtigerError
     */
    protected function _checkResponseStatusCode($response)
    {

        if ($response->getStatusCode() !== 200) {
            throw new VtigerError("API request did not complete correctly - Response code: ".$response->getStatusCode(), 1);
        }

    }

    /**
     * Process any errors that we have got back
     *
     * @param object $processedData
     *
     * @throws VtigerError
     */
    protected function _processResponseError($processedData)
    {

        if (!isset($processedData->error)) {
            throw new VtigerError("Error property not set on VTiger response when success is false", 3);
        }

        throw new VtigerError($processedData->error->message, 4, $processedData->error->code);

    }

}
