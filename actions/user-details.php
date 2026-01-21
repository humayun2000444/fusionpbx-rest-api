<?php
$required_params = array("user_uuid");

function do_action($body) {
    $sql = "SELECT u.*, 
            (SELECT json_agg(json_build_object('group_uuid', g.group_uuid, 'group_name', g.group_name)) 
             FROM v_user_groups ug 
             JOIN v_groups g ON ug.group_uuid = g.group_uuid 
             WHERE ug.user_uuid = u.user_uuid) as groups
            FROM v_users u 
            WHERE u.user_uuid = :user_uuid";
    $parameters["user_uuid"] = $body->user_uuid;
    $database = new database;
    $result = $database->select($sql, $parameters, "row");
    
    if(!$result) {
        return array("error" => "User not found");
    }
    
    // Remove sensitive data
    unset($result["password"]);
    unset($result["salt"]);
    unset($result["api_key"]);
    unset($result["user_totp_secret"]);
    
    return $result;
}
