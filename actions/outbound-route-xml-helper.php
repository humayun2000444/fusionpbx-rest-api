<?php
/**
 * Regenerate a dialplan's XML from its detail rows.
 *
 * FreeSWITCH executes v_dialplans.dialplan_xml verbatim. The GUI, and this
 * API, edit v_dialplan_details. Change the details without regenerating the
 * XML and the two diverge: the portal and the FusionPBX GUI both show the new
 * route while the switch keeps running the old one. reloadxml does not help --
 * it reloads the stale blob, so the operation reports success and nothing
 * changes.
 *
 * That divergence took every external inbound call down with limit_exceeded
 * earlier this month, so it is worth the duplication being removed: these
 * functions were copied into outbound-route-create.php, destination-create.php
 * and destination-update.php, and outbound-route-update.php simply never got a
 * copy. Shared here so the next caller cannot forget.
 *
 * outbound-route-delete.php does NOT need this: it removes the v_dialplans row
 * outright, so there is no stale XML left to disagree with anything.
 */

function generate_dialplan_xml($dialplan_uuid, $dialplan_name, $dialplan_continue) {
    // Get dialplan details from database
    $sql = "SELECT dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
            dialplan_detail_break, dialplan_detail_inline, dialplan_detail_group,
            dialplan_detail_order, dialplan_detail_enabled
            FROM v_dialplan_details
            WHERE dialplan_uuid = :dialplan_uuid AND dialplan_detail_enabled = 'true'
            ORDER BY dialplan_detail_group, dialplan_detail_order";
    $parameters = array("dialplan_uuid" => $dialplan_uuid);
    $database = new database;
    $details = $database->select($sql, $parameters, "all");

    if (!$details || count($details) == 0) {
        return null;
    }

    // Build XML
    $xml = '<extension name="' . htmlspecialchars($dialplan_name) . '" continue="' . $dialplan_continue . '" uuid="' . $dialplan_uuid . '">' . "\n";

    $conditions = array();
    $actions = array();

    // Group details by group number
    foreach ($details as $detail) {
        $group = $detail['dialplan_detail_group'];
        if (!isset($conditions[$group])) {
            $conditions[$group] = array();
            $actions[$group] = array();
        }

        if ($detail['dialplan_detail_tag'] == 'condition') {
            $conditions[$group][] = $detail;
        } else {
            $actions[$group][] = $detail;
        }
    }

    // Generate XML for each group
    foreach ($conditions as $group => $group_conditions) {
        $group_actions = isset($actions[$group]) ? $actions[$group] : array();

        // Process conditions
        $condition_count = count($group_conditions);
        for ($i = 0; $i < $condition_count; $i++) {
            $cond = $group_conditions[$i];
            $is_last_condition = ($i == $condition_count - 1);

            $field = $cond['dialplan_detail_type'];
            $expression = htmlspecialchars($cond['dialplan_detail_data']);

            if ($is_last_condition && count($group_actions) > 0) {
                // Last condition contains the actions
                $xml .= "\t" . '<condition field="' . $field . '" expression="' . $expression . '">' . "\n";

                // Add actions
                foreach ($group_actions as $action) {
                    $app = $action['dialplan_detail_type'];
                    $data = htmlspecialchars($action['dialplan_detail_data']);
                    $inline = $action['dialplan_detail_inline'];

                    $action_xml = "\t\t" . '<action application="' . $app . '" data="' . $data . '"';
                    if ($inline == 'true') {
                        $action_xml .= ' inline="true"';
                    }
                    $action_xml .= '/>' . "\n";
                    $xml .= $action_xml;
                }

                $xml .= "\t" . '</condition>' . "\n";
            } else {
                // Standalone condition (no actions)
                $xml .= "\t" . '<condition field="' . $field . '" expression="' . $expression . '"/>' . "\n";
            }
        }
    }

    $xml .= '</extension>';

    return $xml;
}

function update_dialplan_xml($dialplan_uuid, $xml) {
    if (!$xml) return;

    $sql = "UPDATE v_dialplans SET dialplan_xml = :dialplan_xml, update_date = NOW()
            WHERE dialplan_uuid = :dialplan_uuid";
    $parameters = array(
        "dialplan_uuid" => $dialplan_uuid,
        "dialplan_xml" => $xml
    );
    $database = new database;
    $database->execute($sql, $parameters);
}
