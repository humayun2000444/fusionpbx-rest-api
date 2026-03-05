<?php
// Auto-install callback system tables
// This file is called automatically on first use

$required_params = array();

function do_action($body) {
    global $db;

    try {
        $database = new database;

        // Check if tables already exist
        $sql = "SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'public'
            AND table_name = 'v_callback_configs'
        )";
        $exists = $database->select($sql, null, 'column');

        if ($exists === 't' || $exists === true) {
            return array(
                "success" => true,
                "message" => "Callback system already installed",
                "tables_created" => false
            );
        }

        // Read and execute installation SQL
        $sql_file = __DIR__ . '/callback-install.sql';

        if (!file_exists($sql_file)) {
            return array(
                "success" => false,
                "error" => "Installation SQL file not found"
            );
        }

        $sql = file_get_contents($sql_file);

        // Execute the SQL (PostgreSQL supports multiple statements)
        $result = $database->execute($sql, null);

        // Verify installation
        $sql_check = "SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'public'
            AND table_name = 'v_callback_configs'
        )";
        $installed = $database->select($sql_check, null, 'column');

        if ($installed === 't' || $installed === true) {
            return array(
                "success" => true,
                "message" => "Callback system installed successfully",
                "tables_created" => true,
                "tables" => array(
                    "v_callback_configs",
                    "v_callback_queue"
                )
            );
        } else {
            return array(
                "success" => false,
                "error" => "Failed to create tables"
            );
        }

    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Installation failed: " . $e->getMessage()
        );
    }
}
?>
