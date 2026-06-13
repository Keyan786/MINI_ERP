<?php
/**
 * Inventory Helpers - Mini ERP System
 * Shared functions for stock management, product utilities, and field-level audit logging.
 */

/**
 * Update stock for a product in a specific warehouse and create a stock movement record.
 *
 * @param mysqli  $conn         Database connection
 * @param int     $productId    Product ID
 * @param int     $warehouseId  Warehouse ID
 * @param float   $qty          Quantity change (positive = in, negative = out)
 * @param string  $movementType Movement type enum value
 * @param string|null $refType  Reference type (e.g., 'Manual', 'Purchase Order')
 * @param int|null    $refId    Reference document ID
 * @param string|null $notes    Notes/reason
 * @param int|null    $userId   User performing the action
 * @param float|null  $unitCost Optional unit cost
 * @return bool Success
 */
function update_stock(
    mysqli $conn,
    int $productId,
    int $warehouseId,
    float $qty,
    string $movementType,
    ?string $refType = null,
    ?int $refId = null,
    ?string $notes = null,
    ?int $userId = null,
    ?float $unitCost = null
): bool {
    // 1. Get default cost_price
    $stmt = $conn->prepare("SELECT cost_price FROM tbl_products WHERE product_id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $prodResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$prodResult) return false;

    if ($unitCost === null) {
        $unitCost = (float)$prodResult['cost_price'];
    }
    $movementValue = abs($qty) * $unitCost;

    // 2. Fetch or create warehouse stock row
    $stmt = $conn->prepare("SELECT on_hand_qty FROM tbl_product_warehouse_stock WHERE product_id = ? AND warehouse_id = ? FOR UPDATE");
    $stmt->bind_param("ii", $productId, $warehouseId);
    $stmt->execute();
    $wsResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $qtyBefore = 0.000;
    if ($wsResult) {
        $qtyBefore = (float)$wsResult['on_hand_qty'];
        $qtyAfter = $qtyBefore + $qty;
        $stmt = $conn->prepare("UPDATE tbl_product_warehouse_stock SET on_hand_qty = ? WHERE product_id = ? AND warehouse_id = ?");
        $stmt->bind_param("dii", $qtyAfter, $productId, $warehouseId);
        $stmt->execute();
        $stmt->close();
    } else {
        $qtyAfter = $qty;
        $stmt = $conn->prepare("INSERT INTO tbl_product_warehouse_stock (product_id, warehouse_id, on_hand_qty, reserved_qty) VALUES (?, ?, ?, 0)");
        $stmt->bind_param("iid", $productId, $warehouseId, $qtyAfter);
        $stmt->execute();
        $stmt->close();
    }

    // 3. Update global cached on_hand_qty
    sync_global_stock_cache($conn, $productId);

    // 4. Create stock movement record
    $stmt = $conn->prepare("INSERT INTO tbl_stock_movements (product_id, warehouse_id, movement_type, reference_type, reference_id, quantity, qty_before, qty_after, unit_cost, movement_value, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissidddddsi", $productId, $warehouseId, $movementType, $refType, $refId, $qty, $qtyBefore, $qtyAfter, $unitCost, $movementValue, $notes, $userId);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Reserve stock for a product in a specific warehouse.
 */
function reserve_stock(mysqli $conn, int $productId, int $warehouseId, float $qty): bool {
    // Ensure row exists
    $stmt = $conn->prepare("INSERT IGNORE INTO tbl_product_warehouse_stock (product_id, warehouse_id, on_hand_qty, reserved_qty) VALUES (?, ?, 0, 0)");
    $stmt->bind_param("ii", $productId, $warehouseId);
    $stmt->execute();
    $stmt->close();

    // Update reserved_qty
    $stmt = $conn->prepare("UPDATE tbl_product_warehouse_stock SET reserved_qty = reserved_qty + ? WHERE product_id = ? AND warehouse_id = ?");
    $stmt->bind_param("dii", $qty, $productId, $warehouseId);
    $stmt->execute();
    $stmt->close();

    // Update global cached reserved_qty
    sync_global_stock_cache($conn, $productId);

    return true;
}

/**
 * Sync global cached on_hand_qty and reserved_qty in tbl_products based on warehouse data.
 */
function sync_global_stock_cache(mysqli $conn, int $productId): void {
    $stmt = $conn->prepare("
        UPDATE tbl_products p
        SET 
            p.on_hand_qty = (SELECT COALESCE(SUM(on_hand_qty), 0) FROM tbl_product_warehouse_stock WHERE product_id = p.product_id),
            p.reserved_qty = (SELECT COALESCE(SUM(reserved_qty), 0) FROM tbl_product_warehouse_stock WHERE product_id = p.product_id)
        WHERE p.product_id = ?
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Calculate free-to-use quantity.
 */
function get_free_qty(float $onHand, float $reserved): float {
    return max(0, $onHand - $reserved);
}

/**
 * Get stock status based on free-to-use qty and minimum stock level.
 *
 * @return string 'in_stock', 'low_stock', or 'out_of_stock'
 */
function get_stock_status(float $freeToUse, float $minLevel): string {
    if ($freeToUse <= 0) return 'out_of_stock';
    if ($freeToUse <= $minLevel) return 'low_stock';
    return 'in_stock';
}

/**
 * Render a stock status badge.
 */
function stock_status_badge(float $freeToUse, float $minLevel): string {
    $status = get_stock_status($freeToUse, $minLevel);
    $labels = [
        'in_stock'     => ['In Stock', 'badge-success'],
        'low_stock'    => ['Low Stock', 'badge-warning'],
        'out_of_stock' => ['Out of Stock', 'badge-danger'],
    ];
    $label = $labels[$status] ?? ['Unknown', 'badge-secondary'];
    return '<span class="badge ' . $label[1] . '">' . $label[0] . '</span>';
}

/**
 * Auto-generate the next product code based on category prefix.
 * Format: PREFIX-XXXX (e.g., RM-0001, FG-0002)
 */
function generate_product_code(mysqli $conn, ?string $categoryName = null): string {
    $prefixMap = [
        'Raw Materials'   => 'RM',
        'Finished Goods'  => 'FG',
        'Semi-Finished'   => 'SF',
        'Consumables'     => 'CO',
        'Packaging'       => 'PK',
    ];
    $prefix = $prefixMap[$categoryName] ?? 'PRD';

    $stmt = $conn->prepare("SELECT product_code FROM tbl_products WHERE product_code LIKE ? ORDER BY product_id DESC LIMIT 1");
    $pattern = $prefix . '-%';
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result) {
        $lastNum = (int)substr($result['product_code'], strlen($prefix) + 1);
        $nextNum = $lastNum + 1;
    } else {
        $nextNum = 1;
    }

    return $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

/**
 * Compare old and new product data for field-level audit logging.
 * Returns structured diff with changed_fields, old_values, and new_values.
 *
 * @param array $oldData        Previous product record
 * @param array $newData        New product values
 * @param array $trackedFields  List of field names to track
 * @return array|null           Null if nothing changed, otherwise structured diff
 */
function audit_product_changes(array $oldData, array $newData, array $trackedFields): ?array {
    $oldValues = [];
    $newValues = [];
    $changedFields = [];

    foreach ($trackedFields as $field) {
        $oldVal = $oldData[$field] ?? null;
        $newVal = $newData[$field] ?? null;

        // Normalize for comparison: cast numerics, trim strings
        if (is_numeric($oldVal) && is_numeric($newVal)) {
            if ((float)$oldVal !== (float)$newVal) {
                $changedFields[] = $field;
                $oldValues[$field] = $oldVal;
                $newValues[$field] = $newVal;
            }
        } else {
            if ((string)$oldVal !== (string)$newVal) {
                $changedFields[] = $field;
                $oldValues[$field] = $oldVal;
                $newValues[$field] = $newVal;
            }
        }
    }

    if (empty($changedFields)) return null;

    return [
        'changed_fields' => $changedFields,
        'old_values'     => $oldValues,
        'new_values'     => $newValues,
    ];
}

/**
 * Recalculate reserved qty for a product across all warehouses, or globally.
 * (This is no longer directly used for Multi-Warehouse but kept for backward compatibility if needed, though reserve_stock should be used)
 */
function recalculate_reserved_qty(mysqli $conn, int $productId): float {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(mc.required_qty - mc.consumed_qty), 0) as reserved
        FROM tbl_mo_components mc
        JOIN tbl_manufacturing_orders mo ON mc.mo_id = mo.mo_id
        WHERE mc.product_id = ? AND mo.status = 'confirmed'
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($result['reserved'] ?? 0);
}

/**
 * The tracked fields for product edit audit logging.
 */
function get_product_tracked_fields(): array {
    return [
        'product_name',
        'sales_price',
        'cost_price',
        'category_id',
        'uom',
        'min_stock_level',
        'procure_on_demand',
        'procurement_type',
        'default_vendor_id',
        'default_bom_id',
    ];
}

/**
 * Get list of Units of Measure.
 */
function get_uom_list(): array {
    return ['Pcs', 'Kg', 'g', 'Ltr', 'mL', 'm', 'cm', 'Box', 'Set', 'Pack'];
}

/**
 * Format a decimal number for display (removes trailing zeros).
 */
function fmt_qty(float $qty): string {
    return rtrim(rtrim(number_format($qty, 3, '.', ','), '0'), '.');
}

/**
 * Format a currency value.
 */
function fmt_price(float $price): string {
    return number_format($price, 2, '.', ',');
}
?>
