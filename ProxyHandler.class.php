<?php



if(!defined('CRLF'))
	define('CRLF', "\r\n");



// error reporting
error_reporting(E_ALL);
if(defined('debug')) {
	ini_set('display_errors', 'On');
}else {
	ini_set('display_errors', 'Off');
	ini_set('log_errors',     'On');
	ini_set('error_log',      'error_log');
}



class ProxyHandler {

private $cache_control  = false;
private $chunked        = false;
private $client_headers = array();
private $curl_handle;
private $pragma         = false;



/**
 * Create a new ProxyHandler
 * @param array|string $options
 */
function __construct($options) {
	if (is_string($options))
		$options = array('proxyUri' => $options);
	// trim slashes, we will append what is needed later
	$translatedUri = rtrim($options['proxyUri'], '/');
	// Get all parameters from options
	$baseUri = '';
	if (isset($options['baseUri'])) {
		$baseUri = $options['baseUri'];
	} else
	if (!empty($_SERVER['REDIRECT_URL'])) {
		$baseUri = dirname($_SERVER['REDIRECT_URL']);
	}
	$requestUri = '';
	if (isset($options['requestUri'])) {
		$requestUri = $options['requestUri'];
	} else {
		if (!empty($_SERVER['REQUEST_URI']))
			$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		if (!empty($_SERVER['QUERY_STRING']))
			$requestUri .= '?' . $_SERVER['QUERY_STRING'];
	}
	if (!empty($requestUri)) {
		if (!empty($baseUri)) {
			$baseUriLength = strlen($baseUri);
			if (substr($requestUri, 0, $baseUriLength) === $baseUri)
				$requestUri = substr($requestUri, $baseUriLength);
		}
		$translatedUri .= $requestUri;
	} else {
		$translatedUri .= '/';
	}
	$this->curl_handle = curl_init($translatedUri);
	// Set various cURL options
	$this->setCurlOption(CURLOPT_FOLLOWLOCATION, true);
	$this->setCurlOption(CURLOPT_RETURNTRANSFER, true);
	$this->setCurlOption(CURLOPT_BINARYTRANSFER, true); // For images, etc.
	$this->setCurlOption(CURLOPT_WRITEFUNCTION,  array($this, 'readResponse'));
	$this->setCurlOption(CURLOPT_HEADERFUNCTION, array($this, 'readHeaders'));
	$requestMethod = '';
	if (isset($options['requestMethod'])) {
		$requestMethod = $options['requestMethod'];
	} else
	if (!empty($_SERVER['REQUEST_METHOD'])) {
		$requestMethod = $_SERVER['REQUEST_METHOD'];
	}
	// Default cURL request method is 'GET'
	if ($requestMethod !== 'GET') {
		$this->setCurlOption(CURLOPT_CUSTOMREQUEST, $requestMethod);
		$inputStream = isset($options['inputStream']) ? $options['inputStream'] : 'php://input';
		switch($requestMethod) {
			case 'POST':
				$data = '';
				if (isset($options['data'])) {
					$data = $options['data'];
				} else {
					if (!isset($HTTP_RAW_POST_DATA)) {
						$HTTP_RAW_POST_DATA = file_get_contents($inputStream);
					}
					$data = $HTTP_RAW_POST_DATA;
				}
				$this->setCurlOption(CURLOPT_POSTFIELDS, $data);
				break;
			case 'PUT':
				// Set the request method.
				$this->setCurlOption(CURLOPT_UPLOAD, 1);
				// PUT data comes in on the stdin stream.
				$putData = fopen($inputStream, 'r');
				$this->setCurlOption(CURLOPT_READDATA, $putData);
				// TODO: set CURLOPT_INFILESIZE to the value of Content-Length.
				break;
		}
	}
	// Handle the client headers.
	$this->handleClientHeaders();
}



/**
 * @return array
 */
private function _getRequestHeaders() {
	// function apache_request_headers() exists
	if (function_exists('apache_request_headers'))
		return apache_request_headers();
	$headers = array();
	foreach ($_SERVER as $key => $value) {
		if (substr($key, 0, 5) == 'HTTP_' && !empty($value)) {
			$headerName = strtolower(substr($key, 5, strlen($key)));
			$headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', $headerName)));
			$headers[$headerName] = $value;
		}
	}
	return $headers;
}



/**
 * @param string $headerName
 * @return void
 */
private function _removeHeader($headerName) {
	if (function_exists('header_remove'))
		header_remove($headerName);
	else
		header($headerName . ': ');
}



/**
 * Called at the end of the constructor
 * @return void
 */
protected function handleClientHeaders() {
	$headers = $this->_getRequestHeaders();
	$xForwardedFor = array();
	foreach ($headers as $headerName => $value) {
		switch($headerName) {
			case 'Host':
			case 'X-Real-IP':
				break;
			case 'X-Forwarded-For':
				$xForwardedFor[] = $value;
				break;
			default:
				$this->setClientHeader($headerName, $value);
				break;
		}
	}
	$xForwardedFor[] = $_SERVER['REMOTE_ADDR'];
	$this->setClientHeader('X-Forwarded-For', implode(',', $xForwardedFor));
	$this->setClientHeader('X-Real-IP', $xForwardedFor[0]);
}



/**
 * Used as value for cURL option CURLOPT_HEADERFUNCTION
 * @param resource $cu
 * @param string $string
 * @return int
 */
protected function readHeaders(&$cu, $header) {
	$length = strlen($header);
	if (preg_match(',^Cache-Control:,', $header)) {
		$this->cache_control = true;
	} else
	if (preg_match(',^Pragma:,', $header)) {
		$this->pragma = true;
	} else
	if (preg_match(',^Transfer-Encoding:,', $header)) {
		$this->chunked = strpos($header, 'chunked') !== false;
	}
	if ($header !== CRLF)
		header(rtrim($header));
	return $length;
}



/**
 * Used as value for cURL option CURLOPT_WRITEFUNCTION
 * @param resource $cu
 * @param string $body
 * @return int
 */
protected function readResponse(&$cu, $body) {
	static $headersParsed = false;
	// Clear the Cache-Control and Pragma headers
	// if they aren't passed from the proxy application.
	if ($headersParsed === false) {
		if (!$this->cache_control)
			$this->_removeHeader('Cache-Control');
		if (!$this->pragma)
			$this->_removeHeader('Pragma');
		$headersParsed = true;
	}
	$length = strlen($body);
	if ($this->chunked) {
		echo dechex($length) . CRLF . $body . CRLF;
	} else {
		echo $body;
	}
	return $length;
}



/**
 * Close the cURL handle and a possible chunked response
 * @return void
 */
public function close() {
	if ($this->chunked)
		echo '0' . CRLF . CRLF;
	curl_close($this->curl_handle);
}



/**
 * Executes the cURL handler, making the proxy request.
 * Returns true if request is successful, false if there was an error.
 * By checking this return, you may output the return from getCurlError
 * Or output your own bad gateway page.
 * @return boolean
 */
public function execute() {
	$this->setCurlOption(CURLOPT_HTTPHEADER, $this->client_headers);
	return curl_exec($this->curl_handle) !== false;
}



/**
 * Get possible cURL error.
 * Should NOT be called before exec.
 * @return string
 */
public function getCurlError() {
	return curl_error($this->curl_handle);
}



/**
 * Get information about the request.
 * Should NOT be called before exec.
 * @return array
 */
public function getCurlInfo() {
	return curl_getinfo($this->curl_handle);
}



/**
 * Sets a new header that will be sent with the proxy request
 * @param string $headerName
 * @param string $value
 * @return void
 */
public function setClientHeader($headerName, $value) {
	$this->client_headers[] = $headerName . ': ' . $value;
}



/**
 * Sets a cURL option.
 * @param string $option
 * @param string $value
 * @return void
 */
public function setCurlOption($option, $value) {
	curl_setopt($this->curl_handle, $option, $value);
}



}
?>