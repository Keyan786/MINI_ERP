<?php
/**
 * Manufacturing AJAX Endpoint - Mini ERP System
 * Returns BoM and component data as JSON for dynamic form behavior.
 *
 * Actions:
 *   ?action=boms_for_product&product_id=X     → BoMs for a product
 *   ?action=bom_components&bom_id=X&mo_qty=Y  → Component list with scaled quantities + availability
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {

    // ─── BoMs for a product ─────────────────────────────────────────────────
    case 'boms_for_product':
        $productId = intval($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            echo json_encode([]);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT bom_id, bom_code, bom_name, quantity, standard_time_minutes
            FROM tbl_bom
            WHERE product_id = ? AND is_active = 1
            ORDER BY bom_code ASC
        ");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $boms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Cast numeric fields
        foreach ($boms as &$b) {
            $b['bom_id'] = (int)$b['bom_id'];
            $b['quantity'] = (float)$b['quantity'];
            $b['standard_time_minutes'] = (float)$b['standard_time_minutes'];
        }
        unset($b);

        echo json_encode($boms);
        break;

    // ─── BoM component details with scaled quantities ───────────────────────
    case 'bom_components':
        $bomId = intval($_GET['bom_id'] ?? 0);
        $moQty = floatval($_GET['mo_qty'] ?? 1);

        if ($bomId <= 0) {
            echo json_encode(['components' => [], 'bom_base_qty' => 1, 'standard_time' => 0]);
            exit;
        }

        // Get BoM header
        $stmt = $conn->prepare("SELECT quantity, standard_time_minutes FROM tbl_bom WHERE bom_id = ?");
        $stmt->bind_param("i", $bomId);
        $stmt->execute();
        $bom = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$bom) {
            echo json_encode(['components' => [], 'bom_base_qty' => 1, 'standard_time' => 0]);
            exit;
        }

        $bomBaseQty = (float)$bom['quantity'];
        $scale = ($bomBaseQty > 0) ? ($moQty / $bomBaseQty) : 1;

        // Get component lines with product stock info
        $stmt = $conn->prepare("
            SELECT bl.product_id, bl.quantity, bl.uom, bl.notes,
                   p.product_code, p.product_name, p.on_hand_qty, p.reserved_qty, p.cost_price
            FROM tbl_bom_lines bl
            LEFT JOIN tbl_products p ON bl.product_id = p.product_id
            WHERE bl.bom_id = ?
            ORDER BY bl.sort_order ASC, bl.bom_line_id ASC
        ");
        $stmt->bind_param("i", $bomId);
        $stmt->execute();
        $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $components = [];
        foreach ($lines as $line) {
            $requiredQty = round((float)$line['quantity'] * $scale, 3);
            $onHand = (float)($line['on_hand_qty'] ?? 0);
            $reserved = (float)($line['reserved_qty'] ?? 0);
            $freeQty = get_free_qty($onHand, $reserved);

            $components[] = [
                'product_id'   => (int)$line['product_id'],
                'product_code' => $line['product_code'] ?? '',
                'product_name' => $line['product_name'] ?? '',
                'uom'          => $line['uom'],
                'bom_qty'      => (float)$line['quantity'],
                'required_qty' => $requiredQty,
                'on_hand'      => $onHand,
                'reserved'     => $reserved,
                'free_qty'     => $freeQty,
                'available'    => ($freeQty >= $requiredQty),
                'cost_price'   => (float)($line['cost_price'] ?? 0),
                'notes'        => $line['notes'] ?? '',
            ];
        }

        echo json_encode([
            'components'   => $components,
            'bom_base_qty' => $bomBaseQty,
            'standard_time' => (float)$bom['standard_time_minutes'],
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>
