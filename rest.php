<?php
require_once "root.php";
require_once "resources/require.php";
require_once "lib/input_validation.php";

// Every response this endpoint produces is JSON, but PHP was letting the default
// text/html content type stand. Clients that dispatch on Content-Type (rather
// than sniffing the body) treat a perfectly good JSON reply as an HTML error
// page. Declared once here so both the error and success paths inherit it.
if(!headers_sent()) {
	header("Content-Type: application/json");
}

function return_error($msg, $code=500) {
	http_response_code($code);
	echo json_encode(array("error" => $msg));
	die();
}

if(!isset($_SERVER['PHP_AUTH_USER'])) {
	error_log("rejecting request with no auth");
	return_error("unauthorized", 401);
}

// get the hash of the secret key for this key id out of the database
$sql = "SELECT key_secret FROM rest_api_keys WHERE key_uuid = :key_id";
$parameters['key_id'] = $_SERVER['PHP_AUTH_USER'];
$database = new database;
$secret = $database->select($sql, $parameters, 'column');
if(!$secret) {
	error_log("rejecting request with invalid token identifier (".$_SERVER['PHP_AUTH_USER'].")");
	return_error("unauthorized", 401);
}

// verify the hash
//
// password_verify() is password_verify($plaintext, $hash) and returns TRUE on a
// match. The call below used to pass the arguments the other way round AND treat
// a TRUE result as a failure. Because $_SERVER['PHP_AUTH_PW'] is not a valid
// hash, the swapped call always returned FALSE, the branch never fired, and
// *every* secret was accepted for any key_uuid that existed - a full auth
// bypass. Verified against the live key table: the stored secrets are bcrypt,
// password_verify($plaintext, $stored) is TRUE for a good secret and FALSE for
// a bad one, so legitimate clients keep working under the corrected call.
if(!password_verify($_SERVER['PHP_AUTH_PW'], $secret)) {
	error_log("rejecting request with valid token identifier but invalid secret");
	return_error("unauthorized", 401);
}

// set the key last used time
$sql = "UPDATE rest_api_keys SET last_used = NOW() WHERE key_uuid = :key_id";
$database = new database;
$result = $database->execute($sql, $parameters);
unset($parameters);

$body = json_decode(file_get_contents('php://input'));

$validation_errors = ensure_parameters($body, array("action"));
if($validation_errors) {
	return_error($validation_errors, 400);
}

$action = strtolower($body->action);
$file = __DIR__."/actions/".$action.".php";
if($body->app) {
	$app = $body->app;
	$app_dir = __DIR__."/../".$app;
	$app_index = $app_dir."/app_api.php";
	if(!file_exists($app_index)) {
		return_error(array("error" => "unknown app"), 400);
	}
	include($app_index);
	if($app_api[$app][$action]) {
		$file = $app_dir."/".$app_api[$app][$action];
	}
}

if(!file_exists($file)) {
	return_error("unknown action", 400);
}

include($file);
$validation_errors = ensure_parameters($body, $required_params);
if($validation_errors) {
	return_error($validation_errors, 400);
}

if(function_exists('do_action')) {
	$resp = do_action($body);
	if($resp['code']) {
		http_response_code($resp['code']);
		unset($resp['code']);
	} elseif($resp['error']) {
		http_response_code(500);
	}

	echo json_encode($resp);
}
