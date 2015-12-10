<?php
/**
 * Truenth strategy for Opauth
 * based on https://developers.google.com/accounts/docs/OAuth2
 *
 * More information on Opauth: http://opauth.org
 *
 * @copyright    Copyright © 2015 University of Washington
 * @package      Opauth.TruenthStrategy
 * @license      MIT License
 */

/**
 * Truenth strategy for Opauth
 * based on https://developers.google.com/accounts/docs/OAuth2
 *
 * @package         Opauth.Truenth
 */
App::uses('HttpSocket', 'Network/Http');
App::uses('DatabaseSessionPlusUserId', 'Datasource/Session');
class TruenthStrategy extends OpauthStrategy{

    /**
     * Compulsory config keys, listed as unassociative arrays
     */
    public $expects = array('authorize_url', 'access_token_url',
            'base_url', 'client_id', 'client_secret');

    /**
     * Optional config keys, without predefining any default values.
     */
    public $optionals = array('redirect_uri', 'scope', 'state', 'access_type', 'approval_prompt');

    /**
     * Optional config keys with respective default values, listed as associative arrays
     * eg. array('scope' => 'email');
     */
    public $defaults = array(
        'redirect_uri' => '{complete_url_to_strategy}oauth2callback',
        'scope' => 'email'
    );

    /**
     * Auth request
     */
    public function request(){
        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), just entered');

        $url = $this->strategy['authorize_url'];
        $params = array(
            'client_id' => $this->strategy['client_id'],
            'redirect_uri' => $this->strategy['redirect_uri'],
            'next' => Router::url("/", true),
            'response_type' => 'code',
            'scope' => $this->strategy['scope']
        );
        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(); url: ' . $url . '; params for strategy: ' . print_r($params, true));

        foreach ($this->optionals as $key){
            if (!empty($this->strategy[$key])) $params[$key] = $this->strategy[$key];
        }

        $this->clientGet($url, $params);
        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), done');
    }

    /**
     * Validate signed requests from central services and return JSON data if valid
     */
    public static function validate_request($signed_request){
        list($encoded_sig, $encoded_data) = explode('.', $signed_request);

        $decoded_data = base64_decode(strtr($encoded_data, '-_', '+/'));

        $correct_decoded_sig = hash_hmac(
            'sha256',
            $encoded_data,
            Configure::read('Opauth.Strategy.Truenth.client_secret'),
            true
        );
        $correct_encoded_sig = strtr(base64_encode($correct_decoded_sig), '+/', '-_');

        if ($correct_encoded_sig !== $encoded_sig){
            CakeLog::write(LOG_ERROR, 'Request signature invalid');
            return false;
        }
        return json_decode($decoded_data, true);
    }

    /**
     * Internal callback, after OAuth
     */
    public function oauth2callback(){
        // CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), just entered');
        if (array_key_exists('code', $_GET) && !empty($_GET['code'])){
            $code = $_GET['code'];
            $url = $this->strategy['access_token_url'];
            $params = array(
                'code' => $code,
                'client_id' => $this->strategy['client_id'],
                'client_secret' => $this->strategy['client_secret'],
                'redirect_uri' => $this->strategy['redirect_uri'],
                'grant_type' => 'authorization_code'
            );
            $response = $this->serverPost($url, $params, null, $headers);

            //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), response serverPost:' . print_r($response, true));

            $results = json_decode($response);

            //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), results from json_decode(response):' . print_r($results, true));

            if (!empty($results) && !empty($results->access_token)){
                //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), !empty($results) && !empty($results->access_token)');
                CakeSession::write('OPAUTH_ACCESS_TOKEN', $results->access_token);
                $userinfo = $this->userinfo($results->access_token);
                //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), userinfo:' . print_r($userinfo, true));

                $this->auth = array(
                    'uid' => $userinfo['id'],
                    'info' => array(),
                    'credentials' => array(
                        'token' => $results->access_token,
                        'expires' => date('c', time() + $results->expires_in)
                    ),
                    'raw' => $userinfo
                );

                if (!empty($results->refresh_token))
                {
                    $this->auth['credentials']['refresh_token'] = $results->refresh_token;
                }

                $this->callback();
            }
            else{
                $error = array(
                    'code' => 'access_token_error',
                    'message' => 'Failed when attempting to obtain access token',
                    'raw' => array(
                        'response' => $response,
                        'headers' => $headers
                    )
                );

                $this->errorCallback($error);
            }
        }
        else{
            $error = array(
                'code' => 'oauth2callback_error',
                'raw' => $_GET
            );

            $this->errorCallback($error);
        }
        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), done');
    }// public function oauth2callback(){

    /**
     * Generic CS to intervention callback. Note that you'll need to write this function name to the CS's clients table's callback_url field, eg https://p3p-dev.cirg.washington.edu/usa-self-mgmt/auth/truenth/eventcallback
     */
    public function eventcallback(){

        $data = self::validate_request($_POST['signed_request']);

        // CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(); data after json_decode:' . print_r($data, true));
        /** example
            [issued_at] => 1442959000
            [user_id] => 10015
            [event] => logout
            [algorithm] => HMAC-SHA256
        */

        if ($data['event'] == 'logout'){
            //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(); data[event] == "logout"');
            $sessionObj = new DatabaseSessionPlusUserId();
            $deleteResult = $sessionObj->deleteByUserId($data['user_id']);
            //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . "(); just did logout, heres deleteResult: $deleteResult");
         }
        //else CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(); data[event] != "logout"');

        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(); done.');
    }// public function eventcallback

    /**
     * Queries Truenth API for user info
     *
     * @param string $access_token
     * @return array Parsed JSON results
     */
    private function userinfo($access_token){
        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), just entered');
        $userinfo = $this->serverGet($this->strategy['base_url'] . 'me', array('access_token' => $access_token), null, $headers); // 'me' from flask; google uses this naming convention, as do others.
        if (!empty($userinfo)){
            return $this->recursiveGetObjectVars(json_decode($userinfo));
        }
        else{
            $error = array(
                'code' => 'userinfo_error',
                'message' => 'Failed when attempting to query for user information',
                'raw' => array(
                    'response' => $userinfo,
                    'headers' => $headers
                )
            );

            $this->errorCallback($error);
        }
        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), done');
    }

    /**
     * Queries Truenth demographics API
     *
     * @return JSON results
     */
    public function demographicsInfo(){
        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), just entered');

        $access_token = CakeSession::read('OPAUTH_ACCESS_TOKEN');

        $userinfo = $this->serverGet($this->strategy['base_url'] . 'demographics', array('access_token' => $access_token), null, $headers);
        if (!empty($userinfo)){
            return $userinfo;
        }
        else{
            $error = array(
                'code' => 'userinfo_error',
                'message' => 'Failed when attempting to query demographics API',
                'raw' => array(
                    'response' => $userinfo,
                    'headers' => $headers
                )
            );

            $this->errorCallback($error);
        }
        //CakeLog::write(LOG_DEBUG, __CLASS__ . '.' . __FUNCTION__ . '(), done');
    }

    public function set_questionniare($user_id, $data){

        $access_token = CakeSession::read('OPAUTH_ACCESS_TOKEN');

        $HttpSocket = new HttpSocket();
        $response = $HttpSocket->put(
            implode(array($this->strategy['base_url'], 'assessment', '/', $user_id, '/',)),
            json_encode($data),
            array(
                'header' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token,
                ),
            )
        );

        if ($response->code == 200){
            return json_decode($response->body, true);
        }

        $error = array(
            'message' => 'Error submitting questionnaire response data',
            'code' => $response->code,
            'raw' => array(
                'response' => $response,
                'headers' => $response->headers,
            ),
        );

        CakeLog::write(LOG_ERROR, print_r($error,1));

        $this->errorCallback($error);
        switch ($response->code){
            case 404:
                throw new NotFoundException($error['message']);
                break;
        }
        return $error;
    }

}
