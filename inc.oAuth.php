<?
Class OAuth
{
	#
	# this gets filled after each HTTP request
	#

	var $oauth_last_request = array();
	
	#
	# key_bucket
	#
	
	var $key_bucket = array();

	################################################################################################
	
	function OAuth($consumer_key, $consumer_secret)
	{
		$this->key_bucket = array(
			'oauth_key'		=> $consumer_key,
			'oauth_secret'		=> $consumer_secret,
		);
	}
	
    function setToken($token, $token_secret)
    {
		$this->key_bucket['user_key'] = $token;
		$this->key_bucket['user_secret'] = $token_secret;
    }

	function oauth_sign($url, $params=array(), $method="GET"){

		#
		# fold query params passed on the URL into params array
		#

		$url_parsed = parse_url($url);
		if (isset($url_parsed['query'])){
			parse_str($url_parsed['query'], $url_params);
			$params = array_merge($params, $url_params);
		}
		

		#
		# create the request thingy
		#

		$oauth_params = $params;
		$oauth_params['oauth_version']		= '1.0';
		$oauth_params['oauth_nonce']		= $this->oauth_generate_nonce();
		$oauth_params['oauth_timestamp']	= $this->oauth_generate_timestamp();
		$oauth_params['oauth_consumer_key']	= $this->key_bucket['oauth_key'];

		if (isset($this->key_bucket['user_key'])){
			$oauth_params['oauth_token']		= $this->key_bucket['user_key'];
		}

		$oauth_params['oauth_signature_method']	= 'HMAC-SHA1';
		$oauth_params['oauth_signature']	= $this->oauth_build_signature($url, $oauth_params, $method);

		return $oauth_params;
	}

	################################################################################################

	function oauth_sign_get($url, $params=array(), $method="GET"){

		$params = $this->oauth_sign($url, $params, $method);

		$url = $this->oauth_normalize_http_url($url) . "?" . $this->oauth_to_postdata($params);

		return $url;
	}

	################################################################################################

		
	function fetch($url,$data=array(),$method="GET")
	{
		return $this->oauth_request($url, $data, $method);
	}
	
	function oauth_request($url, $params=array(), $method="GET"){

		$url = $this->oauth_sign_get($url, $params, $method);

		if ($method == 'POST'){
			list($url, $postdata) = explode('?', $url, 2);
		}else{
			$postdata = null;
		}

		return $this->oauth_http_request($url, $method, $postdata);
	}

	################################################################################################	

	function oauth_build_signature($url, $params, $method){

		$sig = array(
			rawurlencode(StrToUpper($method)),
			preg_replace('/%7E/', '~', rawurlencode($this->oauth_normalize_http_url($url))),
			rawurlencode($this->oauth_get_signable_parameters($params)),
		);

		$key = rawurlencode($this->key_bucket['oauth_secret']) . "&";

		if (isset($this->key_bucket['user_key'])){
			$key .= rawurlencode($this->key_bucket['user_secret']);
		}

		$raw = implode("&", $sig);
		#echo "base string: $raw\n";

		$hashed = base64_encode($this->oauth_hmac_sha1($raw, $key, TRUE));
		return $hashed;
	}

	################################################################################################	

	function oauth_normalize_http_url($url){
		$parts = parse_url($url);
		$port = "";
		if (array_key_exists('port', $parts) && $parts['port'] != '80'){
			$port = ':' . $parts['port'];
		}
		return "{$parts['scheme']}://{$parts['host']}{$port}{$parts['path']}"; 
	}

	################################################################################################	

	function oauth_get_signable_parameters($params){
		$sorted = $params;
		ksort($sorted);

		$total = array();
		foreach ($sorted as $k => $v) {
			if ($k == "oauth_signature") continue;
			$total[] = rawurlencode($k) . "=" . rawurlencode($v);
		}
		return implode("&", $total);
	}

	################################################################################################	

	function oauth_to_postdata($params){
		$total = array();
		foreach ($params as $k => $v) {
			$total[] = rawurlencode($k) . "=" . rawurlencode($v);
		}
		$out = implode("&", $total);
		return $out;
	}

	################################################################################################	

	function oauth_generate_timestamp(){
		return time();
	}

	################################################################################################	

	function oauth_generate_nonce(){
		$mt = microtime();
		$rand = mt_rand();
		return md5($mt . $rand); // md5s look nicer than numbers
	}

	################################################################################################	

	function oauth_hmac_sha1($data, $key, $raw=TRUE){

		if (strlen($key) > 64){
			$key =  pack('H40', sha1($key));
		}

		if (strlen($key) < 64){
			$key = str_pad($key, 64, chr(0));
		}

		$_ipad = (substr($key, 0, 64) ^ str_repeat(chr(0x36), 64));
		$_opad = (substr($key, 0, 64) ^ str_repeat(chr(0x5C), 64));

		$hex = sha1($_opad . pack('H40', sha1($_ipad . $data)));

		if ($raw){
			$bin = '';
			while (strlen($hex)){
				$bin .= chr(hexdec(substr($hex, 0, 2)));
				$hex = substr($hex, 2);
			}
			return $bin;
		}

		return $hex;
	}

	################################################################################################	

	function oauth_get_auth_token($url, $params=array()){

		$url = $this->oauth_sign_get($url, $params);
		$bits = $this->oauth_url_to_hash($url);

		$this->key_bucket['request_key']	= $bits['oauth_token'];
		$this->key_bucket['request_secret']	= $bits['oauth_token_secret'];

		if ($this->key_bucket['request_key'] && $this->key_bucket['request_secret']){
			print_r($this->key_bucket);
			return 1;
		}

		return 0;
	}

	################################################################################################	

	function oauth_url_to_hash($url){

		$crap = $this->oauth_http_request($url);
		$bits = explode("&", $crap);

		$out = array();
		foreach ($bits as $bit){
			list($k, $v) = explode('=', $bit, 2);
			$out[urldecode($k)] = urldecode($v);
		}

		return $out;
	}

	################################################################################################

	function oauth_get_auth_url($url, $params=array()){

		return $url . "?oauth_token=".$this->key_bucket["request_key"];
	}

	################################################################################################

	function oauth_get_access_token($url, $params=array()){

		$this->key_bucket['user_key']		= $this->key_bucket['request_key'];
		$this->key_bucket['user_secret']	= $this->key_bucket['request_secret'];

		$url = $this->oauth_sign_get($url, $params);
		$bits = $this->oauth_url_to_hash($url);

		$this->key_bucket['user_key']		= $bits['oauth_token'];
		$this->key_bucket['user_secret']	= $bits['oauth_token_secret'];

		if ($this->key_bucket['user_key'] && $this->key_bucket['user_secret']){
			return 1;
		}

		return 0;
	}

	################################################################################################
	
	function oauth_http_request($url, $method="GET", $data=null)
	{
		$cparams = array(
			'http' => array(
			  'method' => $method,
			  'header' => '',
			  'ignore_errors' => true
			)
		);
		if ($data !== null) {
			$params = $this->http_build_query($data);
			if ($method == 'POST') {
				$cparams['http']['header'] .= "Content-Length: ". strlen($params) ."\r\n"; 
			    $cparams['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
				$cparams['http']['content'] = $params;
			} else {
			  $url .= '?' . $params;
			}
		}
		
		$context = stream_context_create($cparams);

		$fp = fopen($url, 'rb', false, $context);
		
		if (!$fp)
			return false;
			
		//@ob_end_clean();
		ob_start(array($this,"ob_clear"));
		@fpassthru($fp);
		$meta = stream_get_meta_data($fp);
		fclose($fp);
		$response = ob_get_contents();
		ob_end_clean();

		$this->oauth_last_request = array(
			'request'	=> array(
				'url'		=> $url,
				'method'	=> $method,
				'postdata'	=> $data,
			),
			'headers'	=> $meta["wrapper_data"],
			'body'		=> $response,
		);

		return $response;
	}
    
    function ob_clear($buffer)
    {
    	return "";
    }
    
    function http_build_query($data, $prefix='', $sep='', $key='') { 
        $ret = array(); 
        foreach ((array)$data as $k => $v) { 
            if (is_int($k) && $prefix != null) { 
                $k = urlencode($prefix . $k); 
            } 
            if ((!empty($key)) || ($key === 0))  $k = $key.'['.urlencode($k).']'; 
            if (is_array($v) || is_object($v)) { 
                array_push($ret, $this->http_build_query($v, '', $sep, $k)); 
            } else { 
                array_push($ret, $k.'='.urlencode($v)); 
            } 
        } 
        if (empty($sep)) $sep = ini_get('arg_separator.output'); 
        return implode($sep, $ret); 
    }
}