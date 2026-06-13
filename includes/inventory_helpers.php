<?php
/**
 * Inventory Helpers - Mini ERP System
 * Shared functions for stock management, product utilities, and field-level audit logging.
 */

/**
 * Update stock for a product and create a stock movement record.
 *
 * @param mysqli  $conn         Database connection
 * @param int     $productId    Product ID
 * @param float   $qty          Quantity change (positive = in, negative = out)
 * @param string  $movementType Movement type enum value
 * @param string|null $refType  Reference type (e.g., 'Manual', 'Purchase Order')
 * @param int|null    $refId    Reference document ID
 * @param string|null $notes    Notes/reason
 * @param int|null    $userId   User performing the action
 * @return bool Success
 */
function update_stock(
    mysqli $conn,
    int $productId,
    float $qty,
    string $movementType,
    ?string $refType = null,
    ?int $refId = null,
    ?string $notes = null,
    ?int $userId = null,
    ?float $unitCost = null
): bool {
    // Get current on-hand qty and default cost_price
    $stmt = $conn->prepare("SELECT on_hand_qty, cost_price FROM tbl_products WHERE product_id = ? FOR UPDATE");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) return false;

    $qtyBefore = (float)$result['on_hand_qty'];
    $qtyAfter = $qtyBefore + $qty;

    if ($unitCost === null) {
        $unitCost = (float)$result['cost_price'];
    }
    $movementValue = abs($qty) * $unitCost;

    // Update product on-hand qty
    $stmt = $conn->prepare("UPDATE tbl_products SET on_hand_qty = ? WHERE product_id = ?");
    $stmt->bind_param("di", $qtyAfter, $productId);
    $stmt->execute();
    $stmt->close();

    // Create stock movement record
    $stmt = $conn->prepare("INSERT INTO tbl_stock_movements (product_id, movement_type, reference_type, reference_id, quantity, qty_before, qty_after, unit_cost, movement_value, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issidddddsi", $productId, $movementType, $refType, $refId, $qty, $qtyBefore, $qtyAfter, $unitCost, $movementValue, $notes, $userId);
    $stmt->execute();
    $stmt->close();

    return true;
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
 * Recalculate reserved qty for a product.
 * Aggregates from tbl_mo_components for active (confirmed) manufacturing orders.
 * Formula: SUM(required_qty - consumed_qty) from confirmed MOs
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
