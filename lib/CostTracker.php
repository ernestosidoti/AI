<?php
/**
 * CostTracker — Calcolo costi token Claude API
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

class CostTracker
{
    const PRICE_INPUT_PER_MTOK = 3.00;
    const PRICE_OUTPUT_PER_MTOK = 15.00;
    const USD_TO_EUR = 0.92;

    public static function calculate(int $inputTokens, int $outputTokens): float
    {
        $inputCost = ($inputTokens / 1_000_000) * self::PRICE_INPUT_PER_MTOK;
        $outputCost = ($outputTokens / 1_000_000) * self::PRICE_OUTPUT_PER_MTOK;
        return round($inputCost + $outputCost, 6);
    }

    public static function getStats(PDO $db): array
    {
        $stats = [];

        $row = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(cost_usd),0) AS c,
            COALESCE(SUM(input_tokens),0) AS it, COALESCE(SUM(output_tokens),0) AS ot
            FROM queries")->fetch(PDO::FETCH_ASSOC);
        $stats['totale'] = [
            'queries' => (int)$row['n'],
            'cost_usd' => (float)$row['c'],
            'input_tokens' => (int)$row['it'],
            'output_tokens' => (int)$row['ot'],
        ];

        $row = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(cost_usd),0) AS c
            FROM queries WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC);
        $stats['oggi'] = ['queries' => (int)$row['n'], 'cost_usd' => (float)$row['c']];

        $row = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(cost_usd),0) AS c
            FROM queries WHERE YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())")
            ->fetch(PDO::FETCH_ASSOC);
        $stats['mese'] = ['queries' => (int)$row['n'], 'cost_usd' => (float)$row['c']];

        $stats['media'] = $stats['totale']['queries'] > 0
            ? $stats['totale']['cost_usd'] / $stats['totale']['queries'] : 0;

        return $stats;
    }
}
