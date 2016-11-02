<?php

/**
 * This is based on the implementation here:
 * https://gist.github.com/AWeg/5814427
 */

function asanaRequest($methodPath, $httpMethod = 'GET', $body = null, $cached = true, $wait = true)
{
	global $authToken;
	global $DEBUG;
	global $APPENGINE;
	global $ratelimit;

	$key = false;

	if ($APPENGINE && strcmp($httpMethod,'GET') == 0 && $cached) {
		$key = sha1($authToken['refresh_token']) . ":" . $methodPath;

		$data = getCached($key);

		if ($DEBUG >= 2) {
			pre(array('request' => $body, 'response' => $data), "Memcache: " . $methodPath);
		}

		if ($data != false) {
			return $data;
		}
	}

	$request = createAsanaRequest($methodPath, $httpMethod, $body);

	for ($i = 0; $i < 10; $i++) {
		if ($ratelimit > time()) {
			if ($wait) {
				echo("Waiting for rate limit (". ($ratelimit - time()) . "s)");
				time_sleep_until($ratelimit);
			} else {
				die ("Rate limit reached: retry in " . ($ratelimit - time()) . "s");
			}
		}

		$response = $request->send();
		$error = null;
		if ($response->hasError()) {
			$error = $response->getError();
		}

		$data = $response->getContent();
		$result = parseAsanaResponse($data);

		if(isset($result['retry_after'])) {
			$ratelimit = time() + $result['retry_after'];
			setRateLimit($ratelimit);

			if ($wait)
				continue;
		}
		break;
	}

	cache($key, $result);

	if ($DEBUG >= 2) {
		pre(array('request' => $body, 'response' => $result), "$httpMethod " . $url);
	}
	return $result;
}

function createAsanaRequest($methodPath, $httpMethod = 'GET', $body = null) {
	global $authToken;
	global $DEBUG;
	global $APPENGINE;
	global $ratelimit;

	$access_token = getAccessToken();
	$ratelimit = getRateLimit();

	$url = "https://app.asana.com/api/1.0/$methodPath";
	$headers = array(
		"Content-type: application/json",
		"Authorization: Bearer $access_token"
	);

	$request = new \cURL\Request($url);
	$request->getOptions()
	    ->set(CURLOPT_TIMEOUT, 5)
	    ->set(CURLOPT_RETURNTRANSFER, true)
		->set(CURLOPT_CUSTOMREQUEST, $httpMethod)
		->set(CURLOPT_HTTPHEADER, $headers);

	$jbody = $body;
	if ($jbody)
	{
		if (!is_string($jbody))
		{
			$jbody = json_encode($body);
		}
		$request->getOptions()
			->set(CURLOPT_POSTFIELDS, $jbody);
	}

	return $request;
}

function parseAsanaResponse($data) {
	$result = null;

	// See http://stackoverflow.com/a/27909889/37416
	if (version_compare(PHP_VERSION, '5.4.0', '>=') && !(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
	    /** In PHP >=5.4.0, json_decode() accepts an options parameter, that allows you
	     * to specify that large ints (like Steam Transaction IDs) should be treated as
	     * strings, rather than the PHP default behaviour of converting them to floats.
	     */
	    $result = json_decode($data, true, 512, JSON_BIGINT_AS_STRING);
	} else {
	    /** Not all servers will support that, however, so for older versions we must
	     * manually detect large ints in the JSON string and quote them (thus converting
	     *them to strings) before decoding, hence the preg_replace() call.
	     */
	    $max_int_length = strlen((string) PHP_INT_MAX) - 1;
	    $json_without_bigints = preg_replace('/:\s*(-?\d{'.$max_int_length.',})/', ': "$1"', $data);
	    $result = json_decode($json_without_bigints, true);
	}
	// $result = json_decode($data, true);

	return $result;
}

function getAccessToken() {

	global $authToken;
	$access_token = $authToken['access_token'];
	// Check for expiry
	$refresh = time() + 600;
	if (!$access_token || $authToken['expires'] < $refresh) {
		// Look for an already-refreshed token
		$tokenKey = sha1($authToken['refresh_token']) . ":access_token";
		$refreshed_token = getCached($tokenKey);
		
		if ($refreshed_token && $refreshed_token['expires'] > $refresh) {
			$access_token = $refreshed_token['access_token'];
			$authToken['access_token'] = $refreshed_token['access_token'];
			$authToken['expires'] = $refreshed_token['expires'];
		} else {
			// Refresh the token
			global $asana_app;
			$asana_config = $asana_app[$authToken['host']];
			$client = Asana\Client::oauth(array(
			    'client_id' => $asana_config['key'],
			    'client_secret' => $asana_config['secret'],
			    'redirect_uri' => $asana_config['redirect'],
			    'refresh_token' => $authToken['refresh_token']
			));
			$access_token = $client->dispatcher->refreshAccessToken();
			$authToken['access_token'] = $access_token;
			$authToken['expires'] = time() + $client->dispatcher->expiresIn;

			cache($tokenKey, $authToken);
		}
	}

	return $access_token;
}

function cache($key, $result) {
	global $APPENGINE;
	if ($APPENGINE && $key) {
		try {
			getMemcache()->set($key, $result, false, 120);
		} catch (Exception $e) {
			
		}
	}
}

function getCached($key) {
	global $APPENGINE;
	if ($APPENGINE) {
		try {
			$data = getMemcache()->get($key);
			return $data;
		} catch (Exception $e) {
			
		}
	}
	return null;
}

function getMemcache() {
	global $memcache;
	if (!$memcache)
		$memcache = new Memcache;
	return $memcache;
}

function isCancelled($channel) {
	global $authToken;
	$key = sha1($authToken['refresh_token']) . ":$channel:cancelled";
	$cancelled = getMemcache()->get($key);
	return $cancelled;
}

function cancel($channel) {
	global $authToken;
	$key = sha1($authToken['refresh_token']) . ":$channel:cancelled";
	$cancelled = getMemcache()->set($key, true);
	return $cancelled;
}

function getPendingRequests() {
	global $authToken;
	$key = sha1($authToken['refresh_token']) . ":issuedRequests:" . floor(time()/60);
	$pending = getMemcache()->get($key);
	return $pending;
}

function incrementRequests($value = 1) {
	global $authToken;
	$key = sha1($authToken['refresh_token']) . ":issuedRequests:" . floor(time()/60);
	$pending = getMemcache()->increment($key, $value);
	if (!$pending) {
		getMemcache()->set($key, $value, false, 120);
		return $value;
	}
	return $pending;
}

function getRateLimit() {
	global $APPENGINE;
	global $authToken;
	$ratelimit = false;
	if ($APPENGINE) {
		$key = sha1($authToken['refresh_token']) . ":ratelimit";
		$ratelimit = getMemcache()->get($key);
	}
	return $ratelimit;
}

function setRateLimit($ratelimit) {
	global $APPENGINE;
	global $authToken;
	if ($APPENGINE) {
		$key = sha1($authToken['refresh_token']) . ":ratelimit";
		getMemcache()->set($key, $ratelimit);
	}
	return $ratelimit;
}

function notifyCreated($project) {
	global $pusher;
	global $channel;
	if ($pusher) {
		$pusher->trigger($channel, 'created', $project);
	}
}

function progress($text) {
	global $pusher;
	global $channel;
	if ($pusher) {
		$body = array('message' => $text);
		$pusher->trigger($channel, 'progress', $body);
	} else {
		print "<p>" . $text . "</p>\n";
		flush();
	}
}

function error($body, $title, $style) {
	global $pusher;
	global $channel;
	if ($pusher) {
		$error = array ('error' => $title, 'api_response' => $body);
		$pusher->trigger($channel, 'error', $error);
		if (strcmp($style, 'danger') == 0)
			throw new Exception(json_encode($error, JSON_PRETTY_PRINT));
	} else {
		print '<div class="bs-callout bs-callout-' . $style . '">';
		if ($title)
			print "<h4>$title</h4>";
		print "<pre>";
		print(json_encode($body, JSON_PRETTY_PRINT));
		print "</pre></div>\n";
		flush();
	}
}

function p($text) {
	progress($text);
}

function pre($o, $title = false, $style = 'info') {
	error($o, $title, $style);
}

function isError($result) {
	return isset($result['errors']) || !isset($result['data']);
}

require_once 'workspaces.php';
require_once 'projects.php';
require_once 'tasks.php';
require_once 'taskdetails.php';
require_once 'attachments.php';
