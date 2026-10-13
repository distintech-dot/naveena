<?php
/** Shared Top 5 query for screen and exports; branch scope is enforced by caller. */
function top_rows(string $type, string $ps, string $pe, ?int $scope, int $limit = 5): array
{
    $cat = $type === 'skincare' ? 's.category' : 't.category';
    $join = $type === 'skincare' ? 'LEFT JOIN skincare_products s ON s.id = oi.skincare_id' : 'LEFT JOIN treatments t ON t.id = oi.treatment_id';
    $bs = $scope === null ? '' : ' AND o.branch_id = ?';
    $params = [$type, $ps, $pe];
    if ($scope !== null) $params[] = $scope;
    return all("SELECT oi.item_name name, oi.item_code code, COALESCE({$cat},'-') category,
                       COALESCE(SUM(oi.quantity),0) qty, COALESCE(SUM(oi.subtotal),0) total,
                       COUNT(DISTINCT o.id) trx
                FROM order_items oi JOIN orders o ON o.id = oi.order_id {$join}
                WHERE o.status='paid' AND oi.item_type = ? AND date(o.created_at) BETWEEN ? AND ? {$bs}
                GROUP BY oi.item_name ORDER BY total DESC LIMIT " . (int)$limit, $params);
}
