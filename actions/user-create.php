<?php
$required_params = array("domain_uuid", "username", "password");

function do_action($body) {
    // Check if username already exists
    $sql = "SELECT user_uuid FROM v_users WHERE username = :username AND domain_uuid = :domain_uuid";
    $parameters["username"] = $body->username;
    $parameters["domain_uuid"] = $body->domain_uuid;
    $database = new database;
    if($database->select($sql, $parameters, "column")) {
        return array("error" => "Username already exists");
    }
    unset($parameters);
    
    // Generate salt and hash password
    $salt = uuid();
    $password_hash = md5($salt . $body->password);
    
    $user_uuid = uuid();
    
    // Prepare user data
    $array["users"][] = array(
        "user_uuid" => $user_uuid,
        "domain_uuid" => $body->domain_uuid,
        "username" => $body->username,
        "password" => $password_hash,
        "salt" => $salt,
        "user_email" => isset($body->user_email) ? $body->user_email : "",
        "user_status" => isset($body->user_status) ? $body->user_status : "Available",
        "user_enabled" => isset($body->user_enabled) ? $body->user_enabled : "true",
        "insert_date" => date("Y-m-d H:i:s")
    );
    
    // Add user group if specified
    $group_uuid = isset($body->group_uuid) ? $body->group_uuid : "727fec46-7ea4-47d4-835e-164843a5e257"; // default: user group
    $array["user_groups"][] = array(
        "user_group_uuid" => uuid(),
        "domain_uuid" => $body->domain_uuid,
        "group_uuid" => $group_uuid,
        "group_name" => "",
        "user_uuid" => $user_uuid,
        "insert_date" => date("Y-m-d H:i:s")
    );
    
    $_SESSION["permissions"]["user_add"] = true;
    $_SESSION["permissions"]["user_group_add"] = true;
    
    $database = new database;
    $database->app_name = "rest_api";
    $database->app_uuid = "2bfe71d9-e112-4b8b-bcff-75aeb0e06302";
    if(!$database->save($array)) {
        return array("error" => "Error creating user");
    }
    
    // Return created user (without sensitive data)
    $sql = "SELECT user_uuid, domain_uuid, username, user_email, user_status, user_enabled, insert_date FROM v_users WHERE user_uuid = :user_uuid";
    $parameters["user_uuid"] = $user_uuid;
    $database = new database;
    return $database->select($sql, $parameters, "row");
}
