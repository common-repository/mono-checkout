<?php

namespace mono;

class MonoApi {

    public $token;
    public $url;
    public $last_raw_response;
    public $last_error;
    public $pub_key;

    public static function fetch_pub_key( $token, $url ) {
        $pub_key_url = str_replace('personal/checkout/order/', 'personal/checkout/signature/public/key', $url);
        $r = json_decode(self::send_request( $pub_key_url, 'GET', $token ), true);
        if ($r) { return $r['key']; }
        return null;
    }

    protected static function send_request( $url, $method, $token, $postdata = null ) {
        $headers = [
            'Content-Type: application/json',
            'X-Token: ' . $token,
        ];
        if (extension_loaded("curl")) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST => (strtoupper($method) != 'GET'),
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => "",
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_POSTFIELDS => $postdata
            ));
            $resultRaw = curl_exec($ch);
            curl_close($ch);
        } else {
            $opts = array('http' =>
              array(
                  'method'  => $method,
                  'header'  => implode(PHP_EOL, $headers),
                  'content' => $postdata,
                  'ignore_errors' => true,
              )
            );

            $context  = stream_context_create($opts);

            $resultRaw = file_get_contents($url, false, $context);
        }
        return $resultRaw;
    }

    public function __construct($token, $url, $pub_key) {
        $this->token = $token;
        $this->url = $url;
        $this->pub_key = $pub_key;
    }

    public function create_order( $request ) {
        return $this->do_request( $this->url, 'POST', wp_json_encode($request) );
    }

    public function update_order( $mono_order_id ) {
        return $this->do_request( $this->url . '/' . $mono_order_id );
    }

    public function validate_webhook( $xSignHeader, $data ) {
        $pubKeyBase64 = $this->pub_key;

        // value from X-Sign header in webhook request
        $xSignBase64 = $xSignHeader;

        $message = $data;
        $signature = base64_decode($xSignBase64);
        $publicKey = openssl_get_publickey(base64_decode($pubKeyBase64));

        $result = openssl_verify($message, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    protected function do_request( $url, $method = 'GET', $postdata = null ) {
        $resultRaw = self::send_request( $url, $method, $this->token, $postdata );
        $this->last_raw_response = $resultRaw;
        $result = rest_sanitize_object(json_decode($resultRaw, true));
        $this->last_error = @$result['errorDescription'] ?: @$result['errText'];
        return @$result['result'];
    }
}