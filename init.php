<?php
	date_default_timezone_set("UTC");
	header('Content-Type: text/html; charset=utf-8');

	require_once __DIR__ . '/vendor/autoload.php';
	require_once 'config.php';

	global $DEBUG;
	$DEBUG = false;
	if (isset($_COOKIE["debug"]))
		$DEBUG = $_COOKIE["debug"];
	if (isset($_GET["debug"]))
		$DEBUG = $_GET["debug"];
	if (isset($_POST["debug"]))
		$DEBUG = $_POST["debug"];

	if ($DEBUG) {
		setcookie("debug", $DEBUG);
	}

	global $APPENGINE;
	$APPENGINE = false;
	if (stream_resolve_include_path("google/appengine/api")) {
		$APPENGINE = true;
	}

	global $pusher;
	$pusher = new Pusher(
	  $config['pusher_key'],
	  $config['pusher_secret'],
	  $config['pusher_app_id'],
	  array('encrypted' => true)
	);


	global $authToken;
	$authToken = false;

	if (isset($_COOKIE["auth_token"]))
		$authToken = json_decode($_COOKIE["auth_token"], true);
	if (isset($_POST["authToken"]))
		$authToken = $_POST["authToken"];
	if (isset($_GET["authToken"]))
		$authToken = $_GET["authToken"];

	global $channel;
	$channel = null;

	if (isset($_POST["channel"]))
		$channel = $_POST['channel'];
	if (isset($_GET["channel"]))
		$channel = $_GET['channel'];
?>