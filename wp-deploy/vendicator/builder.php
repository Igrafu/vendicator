<?php
/**
 * Vendicator bet builder - the core gaming feature.
 *
 * Every market section renders SELECTABLE options. Each option carries the
 * points it pays, derived from the model probability (easy picks pay little,
 * long shots pay more) and from the Vendicator Scoreline. The running total
 * updates live in the browser as selections are added or removed.
 *
 * Rules the UI and the settler both honour:
 *   - points per leg scale inversely with probability, inside a bounded
 *     range so a near-certain pick can never be worth much
 *   - the Scoreline adds a bonus multiplier when the whole slip lands
 *   - 3+ failed legs deducts points and the card is removed but logged
 *   - fewer than 3 failures can still deduct when the risk factor is high
 */

if (!defined('ABSPATH')) { exit; }

/** Points for a selection at probability $pct (0-100). */
function vendicator_leg_points($pct, $weight = 1.0) {
    $p = max(min((float) $pct, 97.0), 1.5) / 100.0;
    // inverse-probability scaled into a sane band: ~12 (near certain)
    // up to ~320 (long shot)
    $raw = 26.0 * (1.0 / $p) - 14.0;
    return (int) round(max(min($raw * $weight, 320), 10));
}

/** Risk factor 0-1: how exposed a slip is (legs + combined improbability). */
function vendicator_risk_factor($legs) {
    if (!$legs) { return 0.0; }
    $combined = 1.0;
    foreach ($legs as $l) {
        $combined *= max(min((float) $l['pct'], 97.0), 1.5) / 100.0;
    }
    $count_risk = min(count($legs) / 8.0, 1.0);
    $prob_risk = 1.0 - $combined;
    return round(min($count_risk * 0.45 + $prob_risk * 0.55, 1.0), 3);
}

/**
 * Settle a slip.
 * $legs: each ['pct','points','won'(bool)]
 * $scoreline: headline Vendicator Scoreline (0-100) for the fixture
 */
function vendicator_settle_slip($legs, $scoreline = 50.0) {
    $failed = 0;
    $gross = 0;
    foreach ($legs as $l) {
        if (!empty($l['won'])) { $gross += (int) $l['points']; }
        else { $failed++; }
    }
    $risk = vendicator_risk_factor($legs);
    if ($failed === 0) {
        // full house: Scoreline bonus, up to +40%
        $bonus = 1.0 + (min(max((float) $scoreline, 0), 100) / 100.0) * 0.4;
        return array('points' => (int) round($gross * $bonus),
            'outcome' => 'won', 'risk' => $risk,
            'note' => 'Full house - Scoreline bonus applied');
    }
    if ($failed >= 3) {
        $penalty = (int) round(40 * $failed * (0.5 + $risk));
        return array('points' => -$penalty, 'outcome' => 'lost', 'risk' => $risk,
            'note' => $failed . ' legs failed - card removed and logged as a loss');
    }
    // 1-2 failures: no winnings; deduct only when the slip was high risk
    if ($risk >= 0.75) {
        $penalty = (int) round(30 * $failed * $risk);
        return array('points' => -$penalty, 'outcome' => 'lost', 'risk' => $risk,
            'note' => 'High-risk slip missed - points deducted');
    }
    return array('points' => 0, 'outcome' => 'lost', 'risk' => $risk,
        'note' => 'Slip missed - no points earned');
}

/** One selectable option. */
function vendicator_option($group, $value, $label, $pct, $points, $extra = '') {
    return '<label class="vd-opt" data-pct="' . esc_attr($pct) . '" data-points="'
        . (int) $points . '" data-label="' . esc_attr($label) . '">'
        . '<input type="checkbox" name="vd_sel[]" value="'
        . esc_attr($group . '|' . $value . '|' . $label . '|' . $pct . '|' . $points) . '">'
        . '<span class="vd-opt-label">' . esc_html($label) . '</span>'
        . '<span class="vd-opt-pct">' . esc_html($pct) . '%</span>'
        . '<span class="vd-opt-pts">+' . (int) $points . '</span>'
        . ($extra ? '<span class="vd-opt-extra">' . $extra . '</span>' : '')
        . '</label>';
}

/** Outcome card: home win / home or draw / draw / away or draw / away win. */
function vendicator_outcome_options($p) {
    $dc = $p['markets_dixon_coles'];
    $home = isset($p['home_team']) ? $p['home_team'] : 'Home';
    $away = isset($p['away_team']) ? $p['away_team'] : 'Away';
    $rows = array(
        array('home', $home . ' win', $dc['1x2']['home']),
        array('home_or_draw', $home . ' win or draw', $dc['double_chance']['1x']),
        array('draw', 'Draw', $dc['1x2']['draw']),
        array('away_or_draw', $away . ' win or draw', $dc['double_chance']['x2']),
        array('away', $away . ' win', $dc['1x2']['away']),
    );
    $out = '<div class="vd-card"><h3>Match Result</h3><div class="vd-opts">';
    foreach ($rows as $r) {
        $out .= vendicator_option('result', $r[0], $r[1], $r[2],
            vendicator_leg_points($r[2]));
    }
    return $out . '</div><p class="vd-muted" style="font-size:12px;">'
        . 'Safer selections pay fewer points &mdash; the double chances are '
        . 'deliberately modest.</p></div>';
}

/** BTTS with a no-team-to-score option. */
function vendicator_btts_options($p) {
    $dc = $p['markets_dixon_coles'];
    $t = isset($p['team_to_score']) ? $p['team_to_score'] : null;
    $home = isset($p['home_team']) ? $p['home_team'] : 'Home';
    $away = isset($p['away_team']) ? $p['away_team'] : 'Away';
    $none = isset($dc['totals']['under_0.5']) ? $dc['totals']['under_0.5'] : 3.0;
    $out = '<div class="vd-card"><h3>Both Teams To Score (BTTS)</h3><div class="vd-opts">'
        . vendicator_option('btts', 'yes', 'Both teams to score', $dc['btts']['yes'],
            vendicator_leg_points($dc['btts']['yes']))
        . vendicator_option('btts', 'no', 'Not both teams to score', $dc['btts']['no'],
            vendicator_leg_points($dc['btts']['no']));
    if ($t) {
        $out .= vendicator_option('btts', 'home_scores', $home . ' to score',
                $t['home_pct'], vendicator_leg_points($t['home_pct']))
            . vendicator_option('btts', 'away_scores', $away . ' to score',
                $t['away_pct'], vendicator_leg_points($t['away_pct']));
    }
    $out .= vendicator_option('btts', 'none', 'No team to score (0-0)',
        $none, vendicator_leg_points($none));
    return $out . '</div></div>';
}

/** Alternative total goals, every line selectable. */
function vendicator_totals_options($p) {
    $dc = $p['markets_dixon_coles'];
    $out = '<div class="vd-card"><h3>Alternative Total Goals</h3><div class="vd-opts">';
    foreach (array('0.5', '1.5', '2.5', '3.5', '4.5') as $line) {
        if (!isset($dc['totals']['over_' . $line])) { continue; }
        $out .= vendicator_option('totals', 'over_' . $line, 'Over ' . $line,
            $dc['totals']['over_' . $line],
            vendicator_leg_points($dc['totals']['over_' . $line]));
        $out .= vendicator_option('totals', 'under_' . $line, 'Under ' . $line,
            $dc['totals']['under_' . $line],
            vendicator_leg_points($dc['totals']['under_' . $line]));
    }
    return $out . '</div></div>';
}

/** Exact score, selectable. */
function vendicator_score_options($p) {
    $dc = $p['markets_dixon_coles'];
    $out = '<div class="vd-card"><h3>Exact Score</h3><div class="vd-opts">';
    foreach (array_slice($dc['exact_score_top10'], 0, 10) as $pair) {
        $out .= vendicator_option('exact', $pair[0], $pair[0], $pair[1],
            vendicator_leg_points($pair[1]));
    }
    return $out . '</div></div>';
}

/** Cards / fouls / corners, selectable. */
function vendicator_discipline_options($p) {
    if (empty($p['discipline'])) { return ''; }
    $out = '<div class="vd-card"><h3>Cards, Fouls &amp; Corners</h3>';
    foreach ($p['discipline'] as $m) {
        $out .= '<p class="vd-subhead">' . esc_html($m['label'])
            . ' <span class="vd-muted">expected ' . esc_html($m['expected'])
            . '</span></p><div class="vd-opts">';
        foreach ($m['lines'] as $ln) {
            $out .= vendicator_option('disc', $m['key'] . '_' . $ln['line'],
                $m['label'] . ' ' . $ln['label'], $ln['pct'],
                vendicator_leg_points($ln['pct']));
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}

/** Best odds as a selectable market rather than a static table. */
function vendicator_odds_options($p) {
    if (empty($p['odds_board'])) { return ''; }
    $dc = $p['markets_dixon_coles'];
    $labels = array('home' => 'Home win', 'draw' => 'Draw', 'away' => 'Away win');
    $out = '<div class="vd-card"><h3>Best Odds &mdash; back the price</h3><div class="vd-opts">';
    foreach ($p['odds_board'] as $market => $prices) {
        $best = isset($prices[0]) ? $prices[0] : null;
        if (!$best) { continue; }
        $pct = isset($dc['1x2'][$market]) ? $dc['1x2'][$market] : 33.0;
        $out .= vendicator_option('odds', $market,
            (isset($labels[$market]) ? $labels[$market] : $market)
                . ' @ ' . $best['odds'], $pct, vendicator_leg_points($pct),
            esc_html($best['book']));
    }
    return $out . '</div><p class="vd-muted" style="font-size:12px;">'
        . 'Top price across the open bookmaker feed. Informational only '
        . '&mdash; not betting advice.</p></div>';
}
