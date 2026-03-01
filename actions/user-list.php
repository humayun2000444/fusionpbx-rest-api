<?php
$required_params = array("domain_uuid");

function do_action($body) {
    $sql = "SELECT u.user_uuid, u.username, u.user_email, u.user_status, u.user_enabled, u.user_type, u.insert_date,
            (SELECT string_agg(g.group_name, ','  ) FROM v_user_groups ug 
             JOIN v_groups g ON ug.group_uuid = g.group_uuid 
             WHERE ug.user_uuid = u.user_uuid) as groups
            FROM v_users u 
            WHERE u.domain_uuid = :domain_uuid 
            ORDER BY u.username";
    $parameters["domain_uuid"] = $body->domain_uuid;
    $database = new database;
    $result = $database->select($sql, $parameters, "all");
    
    if($result === false) {
        return array("error" => "Error fetching users");
    }
    
    return $result ? $result : array();
}
