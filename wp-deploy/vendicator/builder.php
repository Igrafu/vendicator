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
 * Reward points follow the SHAPE of decimal odds - longer price, more
 * points, always in the same order - but on a compressed curve rather than
 * the price itself. A straight price hands out 26.04 for a 2+ goals shot
 * and 145.80 for a 3+, which makes a season's rank a matter of finding one
 * long shot rather than being consistently right.
 *
 * The exponent flattens the tail: a 50% pick pays 1.51, a 4% pick 6.90, and
 * the longest line the engine will print tops out near 12. Ranks are earned
 * by being right often, not once.
 *
 * $weight tilts it where the platform wants to reward exploration - backing
 * a lower-rated player, or a fixture the model finds genuinely hard.
 */
define('VENDICATOR_POINTS_CURVE', 0.6);
define('VENDICATOR_POINTS_MAX', 4000);   // 40.00 - a hard ceiling per leg

function vendicator_leg_points($pct, $weight = 1.0) {
    $p = max(min((float) $pct, 97.0), 1.0) / 100.0;
    $price = 1.0 / $p;
    $raw = 100.0 * pow($price, VENDICATOR_POINTS_CURVE)
        * max((float) $weight, 0.1);
    return (int) round(max(min($raw, VENDICATOR_POINTS_MAX), 105));
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

/** Bonus ceiling and Scoreline contribution for each value grade. */
function vendicator_grade_rules() {
    return array(
        'bronze' => array('bonus' => 0.12, 'cap' => 1.35, 'label' => 'Bronze value'),
        'silver' => array('bonus' => 0.25, 'cap' => 1.65, 'label' => 'Silver value'),
        'gold' => array('bonus' => 0.40, 'cap' => 2.00, 'label' => 'Gold value'),
    );
}

/**
 * Winning multiplier for a slip that lands in full.
 *
 * A bonus is not automatic. It is paid only on cards the engine has graded
 * as carrying value - a strong Scoreline, a fixture that is genuinely hard
 * to price, and a rating with room to climb. An ungraded card pays its leg
 * values and nothing else, which is what stops a member farming bonuses off
 * whichever fixture happens to be on the board.
 *
 * On a graded card the bonus comes from three places: the grade itself, the
 * risk the member chose to carry, and the price of their longest leg. The
 * grade also sets the ceiling, so a Bronze card can never pay like a Gold.
 */
function vendicator_win_multiplier($scoreline, $risk, $legs = array(),
                                   $grade = null) {
    $tier = is_array($grade) && isset($grade['tier']) ? $grade['tier'] : null;
    $rules = vendicator_grade_rules();
    if (!$tier || !isset($rules[$tier])) {
        return 1.0;      // ungraded card - leg values only, no bonus
    }
    $r = $rules[$tier];
    $bonus = 1.0 + $r['bonus'];
    $bonus += min(max((float) $risk, 0), 1) * 0.35;
    $longest = 0.0;
    foreach ($legs as $l) {
        $pct = max(min((float) $l['pct'], 97.0), 1.5);
        $longest = max($longest, 100.0 / $pct);      // implied decimal price
    }
    if ($longest > 2.0) {
        $bonus += min(($longest - 2.0) / 18.0, 1.0) * 0.15;
    }
    return round(min($bonus, $r['cap']), 3);
}

/**
 * Settle a slip.
 * $legs: each ['pct','points','won'(bool)]
 * $scoreline: headline Vendicator Scoreline (0-100) for the fixture
 */
function vendicator_settle_slip($legs, $scoreline = 50.0, $grade = null) {
    $failed = 0;
    $gross = 0;
    foreach ($legs as $l) {
        if (!empty($l['won'])) { $gross += (int) $l['points']; }
        else { $failed++; }
    }
    $risk = vendicator_risk_factor($legs);
    if ($failed === 0) {
        $bonus = vendicator_win_multiplier($scoreline, $risk, $legs, $grade);
        $total = (int) round($gross * $bonus);
        $tier = is_array($grade) && isset($grade['tier']) ? $grade['tier'] : null;
        return array('points' => $total, 'outcome' => 'won', 'risk' => $risk,
            'multiplier' => $bonus,
            'note' => 'Full house - ' . vendicator_pts($gross) . ' base'
                . ($bonus > 1.0
                    ? ' x' . number_format($bonus, 2) . ' ('
                        . ucfirst((string) $tier) . ' value card: grade, risk '
                        . 'carried and price) = ' . vendicator_pts($total)
                    : ' (this card carries no value grade, so no bonus applies)'));
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
function vendicator_option($group, $value, $label, $pct, $points, $extra = '',
                           $excl = null) {
    // by default a market is single-pick: every option in it overlaps every
    // other, so the group name is its own exclusion key
    if ($excl === null) { $excl = $group; }
    return '<label class="vd-opt" data-pct="' . esc_attr($pct) . '" data-points="'
        . (int) $points . '"' . vendicator_excl_attr($excl)
        . ' data-label="' . esc_attr($label) . '">'
        . '<input type="checkbox" name="vd_sel[]" value="'
        . esc_attr($group . '|' . $value . '|' . $label . '|' . $pct . '|' . $points) . '">'
        . '<span class="vd-opt-label">' . esc_html($label) . '</span>'
        . '<span class="vd-opt-pct">' . esc_html($pct) . '%</span>'
        . '<span class="vd-opt-pts">' . vendicator_pts($points, true) . '</span>'
        . ($extra ? '<span class="vd-opt-extra">' . $extra . '</span>' : '')
        . '</label>';
}

/**
 * Selections that cannot sit on the same slip.
 *
 * Backing "Everton win" alongside "Everton win or draw" is the same wager
 * twice: the second cannot lose if the first wins, so it inflates the
 * payout without adding any risk. The same is true of "over 1.5" with
 * "over 2.5", or a player "to score" with "goal or assist".
 *
 * Every option declares one or more exclusion keys. Picking an option
 * clears any other checked option sharing a key, so a slip can never carry
 * two overlapping selections. Keys are space-separated because some options
 * overlap more than one family - "goal or assist" conflicts with both the
 * score ladder and the assist ladder.
 */
function vendicator_excl_attr($excl) {
    $excl = is_array($excl) ? implode(' ', $excl) : (string) $excl;
    return $excl ? ' data-excl="' . esc_attr($excl) . '"' : '';
}

/** Compact chip form of an option, for the player-market threshold rows. */
function vendicator_chip_option($group, $value, $label, $pct, $points,
                                $excl = '') {
    return '<label class="vd-opt vd-chip" data-pct="' . esc_attr($pct)
        . '" data-points="' . (int) $points . '"' . vendicator_excl_attr($excl)
        . ' title="' . esc_attr($pct)
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

/**
 * Both teams to score, plus each side individually.
 *
 * The 0-0 option was removed: it belongs in Exact Score and Alternative
 * Total Goals, both of which price it properly, and having it here made
 * three ways to back the same outcome from one card.
 */
function vendicator_btts_options($p) {
    $dc = $p['markets_dixon_coles'];
    $t = isset($p['team_to_score']) ? $p['team_to_score'] : null;
    $home = isset($p['home_team']) ? $p['home_team'] : 'Home';
    $away = isset($p['away_team']) ? $p['away_team'] : 'Away';
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
 * Both Halves - a single nine-way market.
 *
 * The half-by-half lists were split into two separate selections, which let
 * a member back "first half home win" and "second half home win" as though
 * they were independent legs when they are really one combination. This is
 * the combination itself: pick how the first half goes AND how the second
 * goes, one selection, priced as the product of the two halves.
 *
 * Nine outcomes, three first-half results by three second-half results.
 */
function vendicator_halves_options($p) {
    if (empty($p['markets_halves']['ht_ft'])) { return ''; }
    $h = $p['markets_halves']['ht_ft'];
    $home = isset($p['home_team']) ? $p['home_team'] : 'Home';
    $away = isset($p['away_team']) ? $p['away_team'] : 'Away';
    $name = array('home' => $home . ' win', 'draw' => 'Draw',
        'away' => $away . ' win');
    $opts = '';
    foreach (array('home', 'draw', 'away') as $first) {
        foreach (array('home', 'draw', 'away') as $second) {
            $key = $first . '_' . $second;
            if (!isset($h[$key])) { continue; }
            $pct = $h[$key];
            $opts .= vendicator_option('htft', $key,
                '1st half: ' . $name[$first] . ' &nbsp;&ndash;&nbsp; 2nd half: '
                    . $name[$second], $pct, vendicator_leg_points($pct));
        }
    }
    if (!$opts) { return ''; }
    return '<div class="vd-card"><h3>Both Halves</h3>'
        . vendicator_scroll_opts($opts, true)
        . '<p class="vd-muted" style="font-size:12px;">One selection covers '
        . 'both halves. Each half is priced off its own expected goals '
        . '&mdash; roughly 45% of goals arrive before the break, so the '
        . 'second half carries the heavier expectation.</p></div>';
}

/**
 * Exact score, over the full board rather than a shortlist.
 *
 * Ten scorelines is barely a market - it is the model's favourites listed
 * back. The board runs to every scoreline the engine gives a real chance,
 * sorted by likelihood, in a scroll box with a filter for the home side's
 * goal count so a member can find 3-1 without hunting.
 */
function vendicator_score_options($p) {
    $dc = $p['markets_dixon_coles'];
    $board = !empty($dc['exact_score_board'])
        ? $dc['exact_score_board'] : $dc['exact_score_top10'];
    $opts = '';
    $buckets = array();
    foreach ($board as $pair) {
        $hg = (int) substr($pair[0], 0, strpos($pair[0], '-'));
        $buckets[$hg] = true;
        $opts .= '<span class="vd-scoreopt" data-hg="' . $hg . '">'
            . vendicator_option('exact', $pair[0], $pair[0], $pair[1],
                vendicator_leg_points($pair[1])) . '</span>';
    }
    ksort($buckets);
    $filter = '<select class="vd-scorefilter" aria-label="Filter by home goals">'
        . '<option value="">Every scoreline (' . count($board) . ')</option>';
    foreach (array_keys($buckets) as $hg) {
        $filter .= '<option value="' . (int) $hg . '">' . (int) $hg
            . ' goal' . ($hg === 1 ? '' : 's') . ' for the home side</option>';
    }
    $filter .= '</select>';
    return '<div class="vd-card"><h3>Exact Score</h3>' . $filter
        . vendicator_scroll_opts($opts, true)
        . '<p class="vd-muted" style="font-size:12px;">Every scoreline the '
        . 'model gives a realistic chance, likeliest first.</p></div>';
}

/**
 * Clean sheets. A keeper's market, priced straight off the score grid: a
 * clean sheet is simply the opposition failing to score. Understat's open
 * feed lists almost no goalkeepers, so the row is anchored to the team and
 * names the keeper only when the data actually has them.
 */
function vendicator_clean_sheet_options($p) {
    $dc = $p['markets_dixon_coles'];
    if (empty($dc['clean_sheet'])) { return ''; }
    $home = isset($p['home_team']) ? $p['home_team'] : 'Home';
    $away = isset($p['away_team']) ? $p['away_team'] : 'Away';
    $keepers = array();
    foreach ((array) (isset($p['players']) ? $p['players'] : array()) as $pl) {
        if (!empty($pl['position_short']) && $pl['position_short'] === 'GK') {
            $keepers[$pl['team']] = $pl;
        }
    }
    $out = '';
    foreach (array(array($home, 'home'), array($away, 'away')) as $side) {
        list($team, $key) = $side;
        $pct = (float) $dc['clean_sheet'][$key];
        $gk = isset($keepers[$team]) ? $keepers[$team] : null;
        $who = $gk ? $gk['name'] : $team;
        $out .= '<div class="vd-prow"><span class="vd-pwho">'
            . ($gk ? vendicator_player_identity($gk)
                : '<span class="vd-pident"><span class="vd-pname">'
                    . esc_html($team) . ' <i class="vd-pos">GK</i></span>'
                    . '<small class="vd-pteam">goalkeeper not named in the '
                    . 'open feed</small></span>')
            . '</span><span class="vd-plines">'
            . vendicator_chip_option('cleansheet', $key . '_yes',
                $who . ' - clean sheet', $pct, vendicator_leg_points($pct),
                'cs-' . $key)
            . vendicator_chip_option('cleansheet', $key . '_no',
                $who . ' - concedes', 100 - $pct,
                vendicator_leg_points(100 - $pct), 'cs-' . $key)
            . '</span></div>';
    }
    return '<details class="vd-details vd-pcat"><summary>Clean sheets</summary>'
        . '<div class="vd-prows">' . $out . '</div></details>';
}

/** One discipline market rendered as a scrollable ladder of lines. */
function vendicator_discipline_block($m) {
    $opts = '';
    foreach ($m['lines'] as $ln) {
        $side = isset($ln['side']) ? $ln['side'] : 'over';
        // one line per market: "9+ corners" and "11+ corners" overlap
        $opts .= vendicator_option('disc',
            $m['key'] . '_' . $side . '_' . $ln['line'],
            $m['label'] . ' ' . $ln['label'], $ln['pct'],
            vendicator_leg_points($ln['pct']), '', 'disc-' . $m['key']);
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

/**
 * Best odds - back the price.
 *
 * Two gates sit on this card. Seeing it at all requires a lifetime points
 * total above the site average, which is a moving target that has to be
 * defended rather than reached once. Seeing WHICH bookmaker is offering the
 * price is a Gold-tier benefit; everyone else sees the price itself.
 *
 * The selections share the match-result exclusion key: backing "Home win @
 * 3.2" here is the same wager as backing the home win on the result card.
 */
function vendicator_odds_options($p, $may_see = true, $show_books = false) {
    if (empty($p['odds_board'])) { return ''; }
    if (!$may_see) {
        return '<div class="vd-card vd-locked"><h3>Best Odds</h3>'
            . '<p class="vd-muted">&#128274; Opens once your lifetime reward '
            . 'points are above the site average. It is a moving line &mdash; '
            . 'holding it takes a steady record, not one good week.</p></div>';
    }
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
            $show_books ? esc_html($best['book'])
                : '<span class="vd-bookhidden">bookmaker hidden &mdash; '
                    . 'Gold tier</span>',
            'result');
    }
    return $out . '</div><p class="vd-muted" style="font-size:12px;">'
        . 'Top price across the open bookmaker feed'
        . ($show_books ? '' : '; the book behind each price is shown at Gold '
            . 'tier') . '. Informational only &mdash; not betting advice.'
        . '</p></div>';
}
