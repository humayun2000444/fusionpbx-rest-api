<?php
/**
 * order-confirm-install.php
 * Creates the Order Confirmation tables by running order-confirm-install.sql.
 * Idempotent (CREATE TABLE IF NOT EXISTS). Call once after deployment.
 */

$required_params = array();

function do_action($body) {
    $sql_file = __DIR__ . '/order-confirm-install.sql';
    if (!file_exists($sql_file)) {
        return array("success" => false, "error" => "order-confirm-install.sql not found next to this action");
    }
    $sql = file_get_contents($sql_file);

    $database = new database;
    // Split on semicolons at end of line to run statements individually.
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
    $ran = 0; $errors = array();
    foreach ($statements as $stmt) {
        if ($stmt === '' || strpos($stmt, '--') === 0) continue;
        try {
            $database->execute($stmt);
            $ran++;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }

    // Verify the main table exists
    try {
        $database->select("SELECT 1 FROM v_order_confirm_calls LIMIT 1", array(), 'row');
        $installed = true;
    } catch (Exception $e) {
        $installed = false;
    }

    return array(
        "success" => $installed,
        "message" => $installed ? "Order Confirmation schema installed" : "Install may have failed",
        "statementsRun" => $ran,
        "errors" => $errors,
    );
}
