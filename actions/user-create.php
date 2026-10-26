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
    
    // save() gates on permission_exists('user_add') and ('user_group_add'), and
    // there is no logged-in user over REST. Setting $_SESSION['permissions'] only
    // counts if nothing has built the permissions singleton yet, so grant on the
    // singleton itself, as the GUI and domain-create.php do.
    $granted = array("user_add", "user_group_add");
    $permission = permissions::new();
    foreach ($granted as $p) {
        $permission->add($p, "temp");
    }

    $database = new database;
    $database->app_name = "rest_api";
    $database->app_uuid = "2bfe71d9-e112-4b8b-bcff-75aeb0e06302";
    $database->save($array);
    $save_message = $database->message;
    unset($array);

    foreach ($granted as $p) {
        $permission->delete($p, "temp");
    }

    // Return created user (without sensitive data). save() returns its message
    // array, which is truthy even when it refuses, so check the row landed instead.
    $sql = "SELECT user_uuid, domain_uuid, username, user_email, user_status, user_enabled, insert_date FROM v_users WHERE user_uuid = :user_uuid";
    $parameters["user_uuid"] = $user_uuid;
    $database = new database;
    $user = $database->select($sql, $parameters, "row");
    if (empty($user)) {
        $reason = isset($save_message["message"]) ? $save_message["message"] : "unknown database error";
        error_log("USER_CREATE_ERROR: " . $body->username . ": " . $reason);
        return array("error" => "Error creating user: " . $reason);
    }
    return $user;
}
