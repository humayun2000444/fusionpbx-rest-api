<?php
$required_params = array("user_uuid");

function do_action($body) {
    // Check if user exists
    $sql = "SELECT user_uuid, domain_uuid, salt FROM v_users WHERE user_uuid = :user_uuid";
    $parameters["user_uuid"] = $body->user_uuid;
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");
    
    if(!$existing) {
        return array("error" => "User not found");
    }
    unset($parameters);
    
    // Build update query dynamically
    $updates = array();
    $parameters = array();
    $parameters["user_uuid"] = $body->user_uuid;
    
    if(isset($body->username)) {
        $updates[] = "username = :username";
        $parameters["username"] = $body->username;
    }
    
    if(isset($body->password) && !empty($body->password)) {
        $updates[] = "password = :password";
        $parameters["password"] = md5($existing["salt"] . $body->password);
    }
    
    if(isset($body->user_email)) {
        $updates[] = "user_email = :user_email";
        $parameters["user_email"] = $body->user_email;
    }
    
    if(isset($body->user_status)) {
        $updates[] = "user_status = :user_status";
        $parameters["user_status"] = $body->user_status;
    }
    
    if(isset($body->user_enabled)) {
        $updates[] = "user_enabled = :user_enabled";
        $parameters["user_enabled"] = $body->user_enabled;
    }
    
    if(empty($updates)) {
        return array("error" => "No fields to update");
    }
    
    $updates[] = "update_date = NOW()";
    
    $sql = "UPDATE v_users SET " . implode(", ", $updates) . " WHERE user_uuid = :user_uuid";
    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);
    
    // Update user group if specified
    if(isset($body->group_uuid)) {
        // Delete existing groups
        $sql = "DELETE FROM v_user_groups WHERE user_uuid = :user_uuid";
        $parameters["user_uuid"] = $body->user_uuid;
        $database = new database;
        $database->execute($sql, $parameters);
        unset($parameters);
        
        // Add new group
        $sql = "INSERT INTO v_user_groups (user_group_uuid, domain_uuid, group_uuid, user_uuid, insert_date) VALUES (:user_group_uuid, :domain_uuid, :group_uuid, :user_uuid, NOW())";
        $parameters["user_group_uuid"] = uuid();
        $parameters["domain_uuid"] = $existing["domain_uuid"];
        $parameters["group_uuid"] = $body->group_uuid;
        $parameters["user_uuid"] = $body->user_uuid;
        $database = new database;
        $database->execute($sql, $parameters);
        unset($parameters);
    }
    
    // Return updated user
    $sql = "SELECT user_uuid, domain_uuid, username, user_email, user_status, user_enabled, update_date FROM v_users WHERE user_uuid = :user_uuid";
    $parameters["user_uuid"] = $body->user_uuid;
    $database = new database;
    return $database->select($sql, $parameters, "row");
}
