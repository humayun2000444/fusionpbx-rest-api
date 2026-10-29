<?php
/**
 * Extra condition rows on an outbound route: what Dialplan Manager lets the GUI add to a route beyond the destination
 * pattern. Shared by outbound-route-create and outbound-route-update; not an action itself.
 *
 *   extra_conditions: [{"field": "${from_user_exists}", "expression": "^true$"}, ...]
 *
 * A field that the route already has a condition for is updated in place; a new one goes right after the route's first
 * condition (${user_exists}), before the destination pattern.
 */

/** The validated list, or an error message. */
function outbound_route_extra_conditions($body) {
    $list = isset($body->extra_conditions) ? $body->extra_conditions : (isset($body->extraConditions) ? $body->extraConditions : null);
    if ($list === null) {
        return array();
    }
    if (!is_array($list)) {
        return "extra_conditions must be a list of {field, expression}";
    }
    $conditions = array();
    foreach ($list as $condition) {
        $condition = (object) $condition;
        $field = isset($condition->field) ? (string) $condition->field : '';
        $expression = isset($condition->expression) ? (string) $condition->expression : '';
        if (!preg_match('/^(\$\{[A-Za-z0-9_]+\}|[A-Za-z0-9_]+)$/', $field)) {
            return "invalid condition field: " . $field;
        }
        if ($field === 'destination_number') {
            return "destination_number is set with destination_pattern";
        }
        if ($expression === '' || strlen($expression) > 255) {
            return "invalid condition expression for " . $field;
        }
        $conditions[] = array('field' => $field, 'expression' => $expression);
    }
    return $conditions;
}

function outbound_route_put_conditions($dialplan_uuid, $domain_uuid, $conditions) {
    $database = new database;
    foreach ($conditions as $i => $condition) {
        $existing = $database->select(
            "SELECT dialplan_detail_uuid FROM v_dialplan_details
              WHERE dialplan_uuid = :dialplan_uuid AND dialplan_detail_tag = 'condition' AND dialplan_detail_type = :field LIMIT 1",
            array('dialplan_uuid' => $dialplan_uuid, 'field' => $condition['field']), 'row');
        if (!empty($existing)) {
            $database->execute(
                "UPDATE v_dialplan_details SET dialplan_detail_data = :data, dialplan_detail_enabled = 'true', update_date = NOW()
                  WHERE dialplan_detail_uuid = :id",
                array('data' => $condition['expression'], 'id' => $existing['dialplan_detail_uuid']));
            continue;
        }
        $database->execute(
            "INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                    dialplan_detail_type, dialplan_detail_data, dialplan_detail_group, dialplan_detail_order,
                    dialplan_detail_enabled, insert_date)
             VALUES (:id, :domain_uuid, :dialplan_uuid, 'condition', :field, :data, 0, :ord, 'true', NOW())",
            array('id' => uuid(), 'domain_uuid' => $domain_uuid, 'dialplan_uuid' => $dialplan_uuid,
                  'field' => $condition['field'], 'data' => $condition['expression'], 'ord' => 11 + $i));
    }
}
