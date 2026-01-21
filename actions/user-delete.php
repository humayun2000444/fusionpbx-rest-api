<?php
$required_params = array("user_uuid");

function do_action($body) {
    // Check if user exists
    $sql = "SELECT user_uuid, username FROM v_users WHERE user_uuid = :user_uuid";
    $parameters["user_uuid"] = $body->user_uuid;
    $database = new database;
    $existing = $database->select($sql, $parameters, "row");
    
    if(!$existing) {
        return array("error" => "User not found");
    }
    unset($parameters);
    
    // Delete user groups first
    $sql = "DELETE FROM v_user_groups WHERE user_uuid = :user_uuid";
    $parameters["user_uuid"] = $body->user_uuid;
    $database = new database;
    $database->execute($sql, $parameters);
    unset($parameters);
    
    // Delete user
    $sql = "DELETE FROM v_users WHERE user_uuid = :user_uuid";
    $parameters["user_uuid"] = $body->user_uuid;
    $database = new database;
    $database->execute($sql, $parameters);
    
    return array(
        "success" => true,
        "message" => "User deleted successfully",
        "deleted_user" => $existing["username"]
    );
}
