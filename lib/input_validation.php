<?php

// Convert camelCase to snake_case
function camelToSnake($input) {
    return strtolower(preg_replace("/([a-z])([A-Z])/", "\$1_\$2", $input));
}

// Convert snake_case to camelCase
function snakeToCamel($input) {
    return lcfirst(str_replace(" ", "", ucwords(str_replace("_", " ", $input))));
}

function ensure_parameters($body, $required) {
    $missing = array();
    foreach($required as $param) {
        // Check camelCase version
        $camelParam = $param;
        // Check snake_case version
        $snakeParam = camelToSnake($param);
        
        $hasCamel = isset($body->{$camelParam}) && $body->{$camelParam} !== "";
        $hasSnake = isset($body->{$snakeParam}) && $body->{$snakeParam} !== "";
        
        if(!$hasCamel && !$hasSnake) {
            $missing[] = $param;
        }
    }
    if(sizeof($missing) == 0) {
        return false;
    }

    return array("error" => "missing required parameter(s)", "missing_parameters" => $missing);
}
