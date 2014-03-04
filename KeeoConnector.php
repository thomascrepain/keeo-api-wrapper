<?php
require_once('curl/curl.php');

class KeeoConnector extends Curl
{
	public function __construct()
	{
		parent::__construct();

		$this->options['CURLOPT_SSL_VERIFYPEER'] = false;
		$this->options['CURLOPT_SSL_VERIFYHOST'] = false;
		$this->options['CURLOPT_USERPWD'] = KEEO_API_USERNAME . ":" . KEEO_API_PASSWORD;
	}

	function get($url, $vars = array())
	{
		return parent::get(KEEO_API_URL.$url, $vars);
	}

	function post($url, $vars = array())
	{
		return parent::post(KEEO_API_URL.$url, $vars);
	}


} 