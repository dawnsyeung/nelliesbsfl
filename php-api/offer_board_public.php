<?php
declare(strict_types=1);
require_once __DIR__ . '/offer_board_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    offer_board_json_response(405, ['error' => 'Method not allowed']);
}

function round_quantity_for_privacy(int $lbs): int {
    if ($lbs <= 0) return 0;
    $step = $lbs < 5000 ? 50 : 100;
    return (int)(round($lbs / $step) * $step);
}

function month_label(string $monthBucket): string {
    // monthBucket: YYYY-MM
    try {
        $dt = DateTimeImmutable::createFromFormat('Y-m', $monthBucket, new DateTimeZone('UTC'));
        if ($dt === false) return $monthBucket;
        return $dt->format('M Y');
    } catch (Throwable $e) {
        return $monthBucket;
    }
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$thisMonth = $now->format('Y-m');
$nextMonth = $now->modify('first day of next month')->format('Y-m');

try {
    $pdo = offer_board_pdo();
    offer_board_init_schema($pdo);

    // Latest fulfillments (public feed)
    $limit = 20;
    $stmt = $pdo->prepare('SELECT fulfilled_date, month_bucket, grade, format, quantity_lbs, delivery_window, region, note_public
                           FROM market_fulfillments
                           ORDER BY created_at DESC
                           LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $fulfillments = [];
    while ($row = $stmt->fetch()) {
        $qty = (int)($row['quantity_lbs'] ?? 0);
        $fulfillments[] = [
            'fulfilled_date' => (string)$row['fulfilled_date'],
            'grade' => (string)$row['grade'],
            'format' => (string)$row['format'],
            'quantity_lbs_display' => round_quantity_for_privacy($qty),
            'delivery_window' => (string)$row['delivery_window'],
            'region' => (string)$row['region'],
            'note_public' => $row['note_public'] !== null ? (string)$row['note_public'] : '',
        ];
    }

    // Capacity helper for a month
    $getCapacity = function(string $monthBucket) use ($pdo): array {
        $capStmt = $pdo->prepare('SELECT capacity_lbs FROM monthly_capacity WHERE month_bucket = :m LIMIT 1');
        $capStmt->execute([':m' => $monthBucket]);
        $capRow = $capStmt->fetch();
        $capacity = (int)($capRow['capacity_lbs'] ?? 0);

        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity_lbs), 0) AS filled FROM market_fulfillments WHERE month_bucket = :m');
        $sumStmt->execute([':m' => $monthBucket]);
        $filled = (int)($sumStmt->fetch()['filled'] ?? 0);

        $remaining = max(0, $capacity - $filled);
        return [
            'month_bucket' => $monthBucket,
            'month_label' => month_label($monthBucket),
            'capacity_lbs' => $capacity,
            'filled_lbs' => $filled,
            'remaining_lbs' => $remaining,
        ];
    };

    $capacity = [
        'this_month' => $getCapacity($thisMonth),
        'next_month' => $getCapacity($nextMonth),
    ];

    offer_board_json_response(200, [
        'success' => true,
        'capacity' => $capacity,
        'fulfillments' => $fulfillments,
        'disclaimer' => 'Activity is anonymized; pricing and buyer identity are never shown.',
    ]);
} catch (Throwable $e) {
    offer_board_json_response(200, [
        'success' => true,
        'capacity' => [
            'this_month' => ['month_bucket' => $thisMonth, 'month_label' => month_label($thisMonth), 'capacity_lbs' => 0, 'filled_lbs' => 0, 'remaining_lbs' => 0],
            'next_month' => ['month_bucket' => $nextMonth, 'month_label' => month_label($nextMonth), 'capacity_lbs' => 0, 'filled_lbs' => 0, 'remaining_lbs' => 0],
        ],
        'fulfillments' => [],
        'disclaimer' => 'Activity is anonymized; pricing and buyer identity are never shown.',
    ]);
}

