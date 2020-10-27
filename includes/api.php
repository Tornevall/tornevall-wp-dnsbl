<?php

/**
 * @param null $method
 * @param null $verb
 * @param array $postdata
 * @param bool $objectify
 * @param null $postMethod
 * @return null
 * @throws Exception
 */
function torneApi($method = null, $verb = null, $postdata = [], $objectify = true, $postMethod = null)
{
    if (empty($method)) {
        throw new \Exception("Need method name", 404);
    }

    $prefApiUrl = get_option('tornevall_dnsbl_preferred_api_url');
    if (empty($prefApiUrl)) {
        $prefApiUrl = "https://api.tornevall.net/3.0/";
    }

    $apiUrl = $prefApiUrl . $method . "/" . $verb;

    $appId = get_option('tornevall_dnsbl_api_id');
    $appKey = get_option('tornevall_dnsbl_api_key');
    $curId = get_current_user_id();

    if ($appId) {
        $postdata['application'] = $appId;
    }
    if ($appKey) {
        $postdata['authKey'] = $appKey;
    }
    if ($curId) {
        $postdata['identifiedPortalUserId'] = $curId;
    }

    if (strtolower($postMethod) == "delete") {
        // Deletion is made easier with json as post parameters does not seem to reach the whole way properly
        $response = wp_remote_request($apiUrl, [
            'body' => @json_encode($postdata),
            'method' => 'DELETE',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($appId . ":" . $appKey),
            ],
        ]);

    } else {
        $response = wp_remote_post($apiUrl, [
            'body' => $postdata,
            'headers' => ['Authorization' => 'Basic ' . base64_encode($appId . ":" . $appKey)],
        ]);
    }

    // Handle internal errors
    if (is_wp_error($response)) {
        $return = [
            'response' => null,
            'code' => 500,
            'faultstring' => $response->get_error_message(),
        ];
        return @json_encode($return);
    }
    $objectifiedResponse = @json_decode($response['body']);

    if (isset($response['body'])) {
        if (!$objectify) {
            if (isset($objectifiedResponse->errors) && isset($objectifiedResponse->errors->code) && $objectifiedResponse->errors->code >= 400) {
                return json_encode($objectifiedResponse->errors);
            }

            return $response['body'];
        } else {
            if (isset($objectifiedResponse->errors) && isset($objectifiedResponse->errors->code) && $objectifiedResponse->errors->code >= 400) {
                return $objectifiedResponse->errors;
            }
            if (is_object($objectifiedResponse)) {
                if (isset($objectifiedResponse->response)) {
                    return $objectifiedResponse->response;
                }
            }
        }
    }

    return null;
}

/**
 * Formerly used like ... $c_postdata = null, $c_request = null, $c_verb = null
 *
 * @throws Exception
 */
function tornevall_dnsbl_api()
{
    $postdata = isset($_REQUEST['postdata']) ? $_REQUEST['postdata'] : [];
    $request = isset($_REQUEST['request']) ? $_REQUEST['request'] : null;
    $verb = isset($postdata['verb']) ? $postdata['verb'] : null;
    $n = isset($_REQUEST['n']) ? $_REQUEST['n'] : null;
    $m = isset($postdata['method']) ? $postdata['method'] : null;

    $nId = 'tornevall_dnsbl_n';
    if (!defined('TORNEVALL_DNSBL_NONCE_EQUALITY')) {
        if (is_admin()) {
            $nId = 'tornevall_dnsbl_a';
        }
    }
    $verified = wp_verify_nonce($n, $nId);

    if ($postdata['verb'] == 'request' && isset($postdata['ip'])) {
        $verified = true;
        if (!preg_match("/\//", trim($postdata['ip']))) {
            if (!get_option('tornevall_dnsbl_prefer_api')) {
                $response = dnsbl_resolve_addr($postdata['ip']);
                dnsbl_display_response($response);
            }
        }
    }

    $response = [
        'timestamp' => time(),
        'request' => $postdata,
        'response' => [],
        'errorstring' => '',
        'errorcode' => '0',
        'verified' => $verified,
    ];

    if (!$verified) {
        $response['errorstring'] = "Invalid API call";
        $response['errorcode'] = 403;
    } else {
        try {
            $getResponse = @json_decode(torneApi($request, $verb, $postdata, false, $m));

            if ($request === "captcha" && $verb === "testCaptcha" && tornevall_dnsbl_is_admin()) {
                $getResponse->response->testCaptchaResponse = 1;
                $getResponse->code = 200;
                $getResponse->response->adminOverride = true;
            }

            if (isset($getResponse->response)) {
                $response['response'] = $getResponse->response;
            }
            if (isset($getResponse->code) && $getResponse->code >= 400) {
                $response['errorstring'] = $getResponse->faultstring;
                $response['errorcode'] = $getResponse->code;
            }

        } catch (\Exception $e) {
            $response['errorstring'] = $e->getMessage();
            $response['errorcode'] = $e->getCode();
        }
    }

    if ($request == "test" && $verb == "key") {
        $dnsblClientData = serialize($response['response']->keyResponse->appClientData);
        update_option('tornevall_dnsbl_clientdata', $dnsblClientData);
        $flagControl = torneApi("dnsbl", "getFlags");
        if (isset($flagControl->getFlagsResponse)) {
            update_option('tornevall_dnsbl_current_flags', (array)$flagControl->getFlagsResponse->structure);
        }
    }
    dnsbl_display_response($response);
}

function dnsbl_display_response($response)
{
    header('Content-Type: application/json');
    echo json_encode($response);
    wp_die();
}
