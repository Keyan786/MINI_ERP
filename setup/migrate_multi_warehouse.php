<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "Starting Multi-Warehouse Migration...\n";

$conn->begin_transaction();
try {
    // 1. Create tbl_warehouses
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_warehouses (
        warehouse_id INT AUTO_INCREMENT PRIMARY KEY,
        warehouse_code VARCHAR(50) UNIQUE NOT NULL,
        warehouse_name VARCHAR(150) NOT NULL,
        location VARCHAR(255),
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Created tbl_warehouses.\n";

    // Seed Main Warehouse
    $stmt = $conn->query("SELECT warehouse_id FROM tbl_warehouses WHERE warehouse_code = 'MAIN'");
    if ($stmt->num_rows == 0) {
        $conn->query("INSERT INTO tbl_warehouses (warehouse_code, warehouse_name, location) VALUES ('MAIN', 'Main Warehouse', 'Headquarters')");
        $mainWarehouseId = $conn->insert_id;
        echo "Seeded Main Warehouse (ID: $mainWarehouseId).\n";
    } else {
        $mainWarehouseId = $stmt->fetch_assoc()['warehouse_id'];
        echo "Main Warehouse exists (ID: $mainWarehouseId).\n";
    }

    // 2. Create tbl_product_warehouse_stock
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_product_warehouse_stock (
        product_id INT NOT NULL,
        warehouse_id INT NOT NULL,
        on_hand_qty DECIMAL(12,3) DEFAULT 0.000,
        reserved_qty DECIMAL(12,3) DEFAULT 0.000,
        PRIMARY KEY (product_id, warehouse_id),
        FOREIGN KEY (product_id) REFERENCES tbl_products(product_id),
        FOREIGN KEY (warehouse_id) REFERENCES tbl_warehouses(warehouse_id)
    )");
    echo "Created tbl_product_warehouse_stock.\n";

    // 3. Create tbl_stock_transfers
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_stock_transfers (
        transfer_id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_number VARCHAR(50) UNIQUE NOT NULL,
        source_warehouse_id INT NOT NULL,
        destination_warehouse_id INT NOT NULL,
        status ENUM('draft', 'completed', 'cancelled') DEFAULT 'draft',
        notes TEXT,
        created_by INT,
        completed_by INT,
        completed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (source_warehouse_id) REFERENCES tbl_warehouses(warehouse_id),
        FOREIGN KEY (destination_warehouse_id) REFERENCES tbl_warehouses(warehouse_id)
    )");
    echo "Created tbl_stock_transfers.\n";

    // 4. Create tbl_stock_transfer_lines
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_stock_transfer_lines (
        line_id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL,
        FOREIGN KEY (transfer_id) REFERENCES tbl_stock_transfers(transfer_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES tbl_products(product_id)
    )");
    echo "Created tbl_stock_transfer_lines.\n";

    // 5. Seed Existing Stock to Main Warehouse
    $res = $conn->query("SELECT product_id, on_hand_qty, reserved_qty FROM tbl_products");
    $stmtInsertStock = $conn->prepare("INSERT IGNORE INTO tbl_product_warehouse_stock (product_id, warehouse_id, on_hand_qty, reserved_qty) VALUES (?, ?, ?, ?)");
    while ($p = $res->fetch_assoc()) {
        $stmtInsertStock->bind_param("iidd", $p['product_id'], $mainWarehouseId, $p['on_hand_qty'], $p['reserved_qty']);
        $stmtInsertStock->execute();
    }
    $stmtInsertStock->close();
    echo "Migrated all global stock to Main Warehouse.\n";

    // 6. Add warehouse_id to tbl_stock_movements
    $res = $conn->query("SHOW COLUMNS FROM tbl_stock_movements LIKE 'warehouse_id'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE tbl_stock_movements ADD COLUMN warehouse_id INT NULL AFTER product_id");
        $conn->query("UPDATE tbl_stock_movements SET warehouse_id = $mainWarehouseId WHERE warehouse_id IS NULL");
        $conn->query("ALTER TABLE tbl_stock_movements ADD FOREIGN KEY (warehouse_id) REFERENCES tbl_warehouses(warehouse_id)");
        echo "Added warehouse_id to tbl_stock_movements.\n";
    }

    // 7. Add warehouse_id to tbl_sales_orders
    $res = $conn->query("SHOW COLUMNS FROM tbl_sales_orders LIKE 'warehouse_id'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE tbl_sales_orders ADD COLUMN warehouse_id INT NULL AFTER customer_id");
        $conn->query("UPDATE tbl_sales_orders SET warehouse_id = $mainWarehouseId WHERE warehouse_id IS NULL");
        $conn->query("ALTER TABLE tbl_sales_orders ADD FOREIGN KEY (warehouse_id) REFERENCES tbl_warehouses(warehouse_id)");
        echo "Added warehouse_id to tbl_sales_orders.\n";
    }

    // 8. Add warehouse_id to tbl_purchase_orders
    $res = $conn->query("SHOW COLUMNS FROM tbl_purchase_orders LIKE 'destination_warehouse_id'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE tbl_purchase_orders ADD COLUMN destination_warehouse_id INT NULL AFTER vendor_id");
        $conn->query("UPDATE tbl_purchase_orders SET destination_warehouse_id = $mainWarehouseId WHERE destination_warehouse_id IS NULL");
        $conn->query("ALTER TABLE tbl_purchase_orders ADD FOREIGN KEY (destination_warehouse_id) REFERENCES tbl_warehouses(warehouse_id)");
        echo "Added destination_warehouse_id to tbl_purchase_orders.\n";
    }

    // 9. Add warehouse_id to tbl_manufacturing_orders
    $res = $conn->query("SHOW COLUMNS FROM tbl_manufacturing_orders LIKE 'warehouse_id'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE tbl_manufacturing_orders ADD COLUMN warehouse_id INT NULL AFTER uom");
        $conn->query("UPDATE tbl_manufacturing_orders SET warehouse_id = $mainWarehouseId WHERE warehouse_id IS NULL");
        $conn->query("ALTER TABLE tbl_manufacturing_orders ADD FOREIGN KEY (warehouse_id) REFERENCES tbl_warehouses(warehouse_id)");
        echo "Added warehouse_id to tbl_manufacturing_orders.\n";
    }

    $conn->commit();
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    $conn->rollback();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
