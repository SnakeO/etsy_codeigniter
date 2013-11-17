<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

require_once( dirname(__FILE__) . "/../third_party/etsy/classes/Etsy.php");

class Et
{
	public $sy;
	public $ci;

	public $access_token_promo;

	function __construct() 
	{
		$this->ci =& get_instance();
		include(APPPATH.'/third_party/etsy/etsy_api.php');
		
		$etsy = $this->ci->config->item('etsy');
		
		$options = array(
			'etsy_key' => 'emt8n1w5dda7qdq7vwypzlqe',
			'cache' => false,
			'tmp_dir' => 'tmp',
			'ttl' => "+1 hour"
		);
		
		$this->sy = new Etsy($options);
		
		// access granted?
		if( @App::$d->promo->access_token != null )
		{
			$this->useTokenFromPromo(App::$d->promo);
		}
	} 
	
	public function useTokenFromPromo($promo, $debug=false)
	{
		$this->access_token_promo = $promo;
		$this->sy->access_token = $promo->access_token;
		$this->sy->access_token_secret =  $promo->access_token_secret;
	}
	
	public function dontUseToken()
	{
		$this->access_token_promo = null;
		$this->sy->access_token = null;
		$this->sy->access_token_secret =  null;
	}
	
	public function api($url, $includes='', $options=null, $method='GET')
	{
		if( $options == null )
			$options = array();
			
		if( $includes != '' )
			$options['includes'] = $includes;
	
		/*
		// difft promos have difft access tokens
		$this->useTokenFromPromo(App::$d->promo);
		*/
			
		$api_call = R::dispense('api_call');
		$api_call->method = $method;
		$api_call->url = $url;
		$api_call->includes = json_encode($includes);
		$api_call->options = json_encode($options);
		$api_call->date_added = R::$f->now();
		$api_call->promo = $promo;
		$api_call->access_token = $this->sy->access_token;
		$api_call->access_token_promo_id = $this->access_token_promo->id;
		$api_call->is_from_admin = (App::$d->admin != null);
//		R::store($api_call);
	
		unset($api_call);
		
	   return $this->sy->request($url, $options, $method, false);
	}
	
	public function get($url, $includes='', $options=null)
	{
		return $this->api($url, $includes, $options, 'GET');
	}
	
	public function post($url, $includes='', $options=null)
	{
		return $this->api($url, $includes, $options, 'POST');
	}
	
	public function put($url, $includes='', $options=null)
	{
		return $this->api($url, $includes, $options, 'PUT');
	}
	
	public function delete($url, $includes='', $options=null)
	{
		return $this->api($url, $includes, $options, 'DELETE');
	}
	
	public function loginURL($perms, $callback='oob')
	{
		$etsyc = $this->ci->config->item('etsyc');
		$oauth = new OAuth($etsyc['key'], $etsyc['secret']);

		// make an API request for your temporary credentials
		$req_token = $oauth->getRequestToken($this->sy->base_url . "oauth/request_token?scope=" . urlencode($perms), $callback);
		return $req_token;
	}
	
	public function verifyOauthToken($request_token, $verifier, $request_token_secret=null)
	{
		// get the temporary credentials secret
		if( $request_token_secret == null )
		{
			if( !App::$d->promo->request_token_secret )
			{
				api_log_db(__FILE__, __LINE__, print_r(@App::$d->promo->export(), true));
				throw new HTMLException("No request token found.");
			}
			
			$request_token_secret = App::$d->promo->request_token_secret;
		}	 

		$etsyc = $this->ci->config->item('etsyc');
		$oauth = new OAuth($etsyc['key'], $etsyc['secret']);
		$oauth->enableDebug();

		// set the temporary credentials and secret
		$oauth->setToken($request_token, $request_token_secret);

		try 
		{
			$oa = R::dispense('oauth');
			$oa->etsy_secret = $etsyc['secret'];
			$oa->request_token_secret = $request_token_secret;

			// set the verifier and request Etsy's token credentials url
			$acc_token = $oauth->getAccessToken($this->sy->base_url . "oauth/access_token", null, $verifier);
		} 
		catch (OAuthException $e) 
		{	
			$oa->debugInfo = print_r($oauth->debugInfo, true);
			//$oa->debug = print_r($oauth->getRequestHeader(), true);
			$oa->passed = 0;
			R::store($oa);

			api_log_db(__FILE__, __LINE__, 
				"Response--\n" . $oauth->getLastResponseHeaders() 
				. "\n\n" . $oauth->getLastResponse() 
				. "\n\n" . print_r($oauth->debugInfo, true)
				. "\n\n secret: $etsyc[secret]; request_token_secret: $request_token_secret" );
			
			throw new HTMLException($e->getMessage());
			return false;
		}

		$oa->debugInfo = print_r($oauth->debugInfo, true);
		//$oa->debug = print_r($oauth->getRequestHeader(), true);
		$oa->passed = 1;
		R::store($oa);
		
		App::$d->promo->access_token           = $acc_token['oauth_token'];
		App::$d->promo->access_token_secret    = $acc_token['oauth_token_secret'];
		R::store(App::$d->promo);
		
		return true;
	}
}