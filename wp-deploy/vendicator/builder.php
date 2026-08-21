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

/**
 * Reward points are stored as whole numbers but READ like decimal odds.
 *
 * A leg worth 263 internally shows as 2.63 - the same shape as a Betfair
 * or bet365 price - so a member never has to translate between the slip
 * they are used to and the points they are earning. Storage stays integer
 * so nothing drifts when totals are added up over a season.
 */
function vendicator_pts($n, $sign = false) {
    $v = (float) $n / 100.0;
    $s = number_format(abs($v), 2);
    if ($n < 0) { return '-' . $s; }
    return ($sign ? '+' : '') . $s;
}

/**
 * Points for a selection at probability $pct (0-100).
 *
 * The value IS the fair price. A 50% selection pays 2.00, a 25% one pays
 * 4.00, a 4% one pays 25.00 - identical in shape to the decimal odds on an
 * exchange, which is the whole point: a member reading "3+ goals 12.50"
 * knows instantly what they are being offered.
 *
 * $weight tilts it where the platform wants to reward exploration - backing
 * a lower-rated player, or a fixture the model finds genuinely hard.
 *
 * The old formula flattened out against a hard 320 ceiling, which made a
 * 3% shot pay exactly the same as a 12% one. The ceiling here sits far
 * beyond any realistic selection, so the ladder never goes flat.
 */
function vendicator_leg_points($pct, $weight = 1.0) {
    $p = max(min((float) $pct, 97.0), 1.0) / 100.0;
    $raw = 100.0 * (1.0 / $p) * max((float) $weight, 0.1);
    return (int) round(max(min($raw, 20000), 101));
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
 * Winning multiplier for a slip that lands in full.
 *
 * Taking on risk knowingly is the point of the builder, so a slip that
 * survives a high risk factor is paid for it twice over: once through the
 * long-shot leg values themselves, and again through this bonus. The
 * Vendicator Scoreline contributes up to +40%, the risk carried up to
 * +60%, and the price on the board up to a further +25% when the member
 * backed a genuine outsider. Capped so no single slip can run away.
 */
function vendicator_win_multiplier($scoreline, $risk, $legs = array()) {
    $sl = min(max((float) $scoreline, 0), 100) / 100.0;
    $bonus = 1.0 + $sl * 0.4 + min(max((float) $risk, 0), 1) * 0.6;
    $longest = 0.0;
    foreach ($legs as $l) {
        $pct = max(min((float) $l['pct'], 97.0), 1.5);
        $longest = max($longest, 100.0 / $pct);      // implied decimal price
    }
    if ($longest > 2.0) {
        $bonus += min(($longest - 2.0) / 18.0, 1.0) * 0.25;
    }
    return round(min($bonus, 2.25), 3);
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
        $bonus = vendicator_win_multiplier($scoreline, $risk, $legs);
        $total = (int) round($gross * $bonus);
        return array('points' => $total, 'outcome' => 'won', 'risk' => $risk,
            'multiplier' => $bonus,
            'note' => 'Full house - ' . vendicator_pts($gross) . ' base x'
                . number_format($bonus, 2) . ' (Scoreline, risk and price '
                . 'bonus) = ' . vendicator_pts($total));
    }
    if ($failed >= 3) {
        $penalty = (int) round(120 * $failed * (0.5 + $risk));
        return array('points' => -$penalty, 'outcome' => 'lost', 'risk' => $risk,
            'note' => $failed . ' legs failed - card removed and logged as a loss');
    }
    // 1-2 failures: no winnings; deduct only when the slip was high risk
    if ($risk >= 0.75) {
        $penalty = (int) round(90 * $failed * $risk);
        return array('points' => -$penalty, 'outcome' => 'lost', 'risk' => $risk,
            'note' => 'High-risk slip missed - points deducted');
    }
    return array('points' => 0, 'outcome' => 'lost', 'risk' => $risk,
        'note' => 'Slip missed - no points earned');
}

/**
 * Settle one leg against a finished result.
 *
 * Returns true (won), false (lost) or null (cannot be settled from a final
 * score alone - half-time results, corners, fouls and player lines need
 * match detail the free results feed does not carry). A null leg is VOIDED:
 * it is dropped from the slip rather than counted as a loss, which is how a
 * bookmaker treats a market it cannot settle, and the member is told.
 */
function vendicator_settle_leg($leg, $outcome, $score) {
    $group = isset($leg['group']) ? $leg['group'] : '';
    $value = isset($leg['value']) ? $leg['value'] : '';
    $hg = $ag = null;
    if (preg_match('#^(\d+)-(\d+)$#', (string) $score, $m)) {
        $hg = (int) $m[1];
        $ag = (int) $m[2];
    }
    $total = ($hg === null) ? null : $hg + $ag;
    switch ($group) {
        case 'result':
        case 'odds':
            $map = array('home' => array('H'), 'draw' => array('D'),
                'away' => array('A'), 'home_or_draw' => array('H', 'D'),
                'away_or_draw' => array('A', 'D'));
            return isset($map[$value])
                ? in_array($outcome, $map[$value], true) : null;
        case 'btts':
            if ($hg === null) { return null; }
            switch ($value) {
                case 'yes': return $hg > 0 && $ag > 0;
                case 'no': return !($hg > 0 && $ag > 0);
                case 'home_scores': return $hg > 0;
                case 'away_scores': return $ag > 0;
                case 'none': return $hg === 0 && $ag === 0;
            }
            return null;
        case 'totals':
            if ($total === null
                || !preg_match('#^(over|under)_([\d.]+)$#', $value, $m)) {
                return null;
            }
            return $m[1] === 'over' ? $total > (float) $m[2]
                : $total < (float) $m[2];
        case 'exact':
            return $hg === null ? null : ((string) $value === (string) $score);
        default:
            return null;   // voided - not settleable from the final score
    }
}

/** One selectable option. */
function vendicator_option($group, $value, $label, $pct, $points, $extra = '') {
    return '<label class="vd-opt" data-pct="' . esc_attr($pct) . '" data-points="'
        . (int) $points . '" data-label="' . esc_attr($label) . '">'
        . '<input type="checkbox" name="vd_sel[]" value="'
        . esc_attr($group . '|' . $value . '|' . $label . '|' . $pct . '|' . $points) . '">'
        . '<span class="vd-opt-label">' . esc_html($label) . '</span>'
        . '<span class="vd-opt-pct">' . esc_html($pct) . '%</span>'
        . '<span class="vd-opt-pts">' . vendicator_pts($points, true) . '</span>'
        . ($extra ? '<span class="vd-opt-extra">' . $extra . '</span>' : '')
        . '</label>';
}

/** Compact chip form of an option, for the player-market threshold rows. */
function vendicator_chip_option($group, $value, $label, $pct, $points) {
    return '<label class="vd-opt vd-chip" data-pct="' . esc_attr($pct)
        . '" data-points="' . (int) $points . '" title="' . esc_attr($pct)
        . '% &middot; pays ' . vendicator_pts($points) . '">'
        . '<input type="checkbox" name="vd_sel[]" value="'
        . esc_attr($group . '|' . $value . '|' . $label . '|' . $pct . '|' . $points) . '">'
        . '<span class="vd-chip-label">' . esc_html($label) . '</span>'
        . '<span class="vd-chip-pts">' . vendicator_pts($points, true) . '</span>'
        . '</label>';
}

/** A market whose option list is long enough to want its own scroll box. */
function vendicator_scroll_opts($html, $tall = false) {
    return '<div class="vd-opts vd-scroll' . ($tall ? ' tall' : '') . '">'
        . $html . '</div>';
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

/**
 * Alternative total goals. Every line the model prices is offered, over and
 * under, in a scrollable list - the deep lines (6.5, 7.5) are long shots
 * and pay accordingly, which is exactly what a builder wants available.
 */
function vendicator_totals_options($p) {
    $dc = $p['markets_dixon_coles'];
    $opts = '';
    foreach (array('0.5', '1.5', '2.5', '3.5', '4.5', '5.5', '6.5', '7.5')
             as $line) {
        if (!isset($dc['totals']['over_' . $line])) { continue; }
        $opts .= vendicator_option('totals', 'over_' . $line, 'Over ' . $line,
            $dc['totals']['over_' . $line],
            vendicator_leg_points($dc['totals']['over_' . $line]));
        $opts .= vendicator_option('totals', 'under_' . $line, 'Under ' . $line,
            $dc['totals']['under_' . $line],
            vendicator_leg_points($dc['totals']['under_' . $line]));
    }
    return '<div class="vd-card"><h3>Alternative Total Goals</h3>'
        . vendicator_scroll_opts($opts, true)
        . '<p class="vd-muted" style="font-size:12px;">Scroll for the deeper '
        . 'lines &mdash; they price as long shots and pay like them.</p></div>';
}

/**
 * Half-by-half results. Each half is priced off its own expected goals, so
 * a second-half home win is a genuinely different selection from a first-
 * half one. The HT/FT combinations sit underneath for bigger prices.
 */
function vendicator_halves_options($p) {
    if (empty($p['markets_halves'])) { return ''; }
    $h = $p['markets_halves'];
    $home = isset($p['home_team']) ? $p['home_team'] : 'Home';
    $away = isset($p['away_team']) ? $p['away_team'] : 'Away';
    $name = array('home' => $home . ' win', 'draw' => 'Draw',
        'away' => $away . ' win');
    $opts = '';
    foreach (array('first_half' => 'First half',
                   'second_half' => 'Second half') as $key => $half) {
        if (empty($h[$key])) { continue; }
        foreach (array('home', 'draw', 'away') as $side) {
            if (!isset($h[$key][$side])) { continue; }
            $pct = $h[$key][$side];
            $opts .= vendicator_option('half', $key . '_' . $side,
                $half . ' &mdash; ' . $name[$side], $pct,
                vendicator_leg_points($pct));
        }
    }
    $combos = '';
    if (!empty($h['ht_ft'])) {
        foreach ($h['ht_ft'] as $k => $pct) {
            $parts = explode('_', $k);
            if (count($parts) !== 2) { continue; }
            $combos .= vendicator_option('htft', $k,
                'First half ' . $name[$parts[0]] . ', second half '
                    . $name[$parts[1]], $pct, vendicator_leg_points($pct));
        }
    }
    return '<div class="vd-card"><h3>Half Results</h3>'
        . vendicator_scroll_opts($opts)
        . ($combos ? '<p class="vd-subhead">Both halves</p>'
            . vendicator_scroll_opts($combos) : '')
        . '<p class="vd-muted" style="font-size:12px;">Halves are priced '
        . 'independently &mdash; roughly 45% of goals arrive before the '
        . 'break, so second-half selections carry the heavier expectation.'
        . '</p></div>';
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

/** One discipline market rendered as a scrollable ladder of lines. */
function vendicator_discipline_block($m) {
    $opts = '';
    foreach ($m['lines'] as $ln) {
        $side = isset($ln['side']) ? $ln['side'] : 'over';
        $opts .= vendicator_option('disc',
            $m['key'] . '_' . $side . '_' . $ln['line'],
            $m['label'] . ' ' . $ln['label'], $ln['pct'],
            vendicator_leg_points($ln['pct']));
    }
    return '<p class="vd-subhead">' . esc_html($m['label'])
        . ' <span class="vd-muted">expected ' . esc_html($m['expected'])
        . '</span></p>' . vendicator_scroll_opts($opts, true);
}

/**
 * Fouls expected. Yellow cards used to live here; the half-result markets
 * took that slot, and corners now have a section of their own, so this
 * card is the fouls ladder alone - offered several lines either side of
 * the expectation rather than a single threshold.
 */
function vendicator_discipline_options($p) {
    if (empty($p['discipline'])) { return ''; }
    $out = '';
    foreach ($p['discipline'] as $m) {
        if ($m['key'] !== 'fouls') { continue; }
        $out .= vendicator_discipline_block($m);
    }
    if (!$out) { return ''; }
    return '<div class="vd-card"><h3>Fouls Expected</h3>' . $out
        . '<p class="vd-muted" style="font-size:12px;">Lines are drawn from '
        . 'both sides\' recent foul rates and what their opponent tends to '
        . 'induce. Scroll for the wider thresholds.</p></div>';
}

/** Corners - its own section, with a full ladder of lines both ways. */
function vendicator_corners_options($p) {
    if (empty($p['discipline'])) { return ''; }
    $out = '';
    foreach ($p['discipline'] as $m) {
        if ($m['key'] !== 'corners') { continue; }
        $out .= vendicator_discipline_block($m);
    }
    if (!$out) { return ''; }
    return '<div class="vd-card"><h3>Expected Corners</h3>' . $out
        . '<p class="vd-muted" style="font-size:12px;">Corner counts swing '
        . 'hard on game state, so the ladder runs wide either side of the '
        . 'expectation &mdash; good ground for a builder leg.</p></div>';
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
