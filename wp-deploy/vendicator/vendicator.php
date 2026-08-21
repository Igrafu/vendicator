<?php
/**
 * Plugin Name: Vendicator
 * Description: Football prediction engine front end - predictions dashboard, accounts, points, subscriptions, rewards, and the Vendicator admin control panel.
 * Version: 0.1.0
 * Author: Vendicator
 */

if (!defined('ABSPATH')) { exit; }

// Bet-builder engine (selectable markets, points, risk, settlement).
if (!function_exists('vendicator_leg_points')) {
    require_once __DIR__ . '/builder.php';
}

/* ---------------------------------------------------------- configuration */

function vendicator_sections() {
    $custom = get_option('vendicator_custom_sections', array());
    $core = array(
        '1x2' => '1X2 Match Result',
        'alt_goals' => 'Alternative Total Goals',
        'btts' => 'Both Teams To Score (BTTS)',
        'halves' => 'Half Results (1st / 2nd half, HT-FT)',
        'players' => 'Player Markets (score / assist)',
        'corners' => 'Expected Corners',
        'discipline' => 'Fouls Expected',
        'best_odds' => 'Best Odds',
        'exact_score' => 'Exact Score',
        'ht_ft' => 'Half Time / Full Time',
        'total_shots' => 'Total Shots',
        'top_scorers' => 'Top Scorers',
        'market_view' => 'Market View',
    );
    foreach ((array) $custom as $slug => $label) { $core[$slug] = $label; }
    return $core;
}

/**
 * Which market sections show for a given competition. Admin-configurable
 * per league (Champions League nights can lead with HT/FT, league nights
 * with player markets), and the engine records which sections members
 * actually bet on so the ordering can follow real behaviour.
 */
function vendicator_league_sections($league) {
    $map = get_option('vendicator_league_sections', array());
    if (!empty($map[$league])) { return (array) $map[$league]; }
    if (!empty($map['_default'])) { return (array) $map['_default']; }
    return array('1x2', 'alt_goals', 'btts', 'halves', 'exact_score',
                 'corners', 'discipline', 'best_odds', 'players');
}

/**
 * New market sections have to reach existing installs.
 *
 * Per-league section lists are admin-editable, so a saved list from before
 * a section existed would silently hide it forever. This adds genuinely new
 * sections to every saved list exactly once, keyed by a version number -
 * an admin who then deselects one keeps that choice.
 */
define('VENDICATOR_SECTIONS_VERSION', 2);

add_action('init', function () {
    if ((int) get_option('vendicator_sections_version', 0)
        >= VENDICATOR_SECTIONS_VERSION) {
        return;
    }
    $added = array('halves', 'corners');
    $map = (array) get_option('vendicator_league_sections', array());
    foreach ($map as $league => $list) {
        $list = (array) $list;
        foreach ($added as $slug) {
            if (!in_array($slug, $list, true)) { $list[] = $slug; }
        }
        $map[$league] = $list;
    }
    if ($map) { update_option('vendicator_league_sections', $map); }
    update_option('vendicator_sections_version', VENDICATOR_SECTIONS_VERSION);
});

function vendicator_log_section_pick($league, $section) {
    $stats = (array) get_option('vendicator_section_stats', array());
    if (!isset($stats[$league])) { $stats[$league] = array(); }
    $stats[$league][$section] = (int) (isset($stats[$league][$section])
        ? $stats[$league][$section] : 0) + 1;
    update_option('vendicator_section_stats', $stats);
}

function vendicator_models() {
    return array(
        'ensemble' => array('Stacked ensemble (calibrated)', false),
        'dixon_coles' => array('Dixon-Coles Poisson', false),
        'elo' => array('Elo', false),
        'bayesian' => array('Bayesian hierarchical', false),
        'xg_poisson' => array('xG-Poisson', false),
        'tabular' => array('Tabular ML (LGBM/XGB/CatBoost)', false),
        'inplay_analytic' => array('In-play analytic engine', false),
        'temporal_prototype' => array('Temporal prototype (StatsBomb)', false),
        'temporal_transformer' => array('Temporal Transformer - needs event feed', true),
        'gnn' => array('Graph Neural Network - needs event/tracking feed', true),
    );
}

function vendicator_tiers() {
    return get_option('vendicator_subscription_tiers', array(
        // thresholds are stored whole and shown as decimals, so 25000 here
        // reads as 250.00 lifetime points on the site
        'free' => array('points' => 0, 'label' => 'Free',
            'benefits' => 'T3 lower-league predictions, 1X2 + BTTS, single-bookmaker odds'),
        'bronze' => array('points' => 5000, 'label' => 'Bronze',
            'benefits' => 'T2 leagues, alternative goals, half results, daily value pick'),
        'silver' => array('points' => 25000, 'label' => 'Silver',
            'benefits' => 'T1 leagues, exact score + corners, uncertainty bands, odds comparison, themes'),
        'gold' => array('points' => 100000, 'label' => 'Gold',
            'benefits' => 'Early release, full value engine, private competitions, avatar customization'),
    ));
}

function vendicator_infra_items() {
    return array(
        'kafka' => array('Kafka / Redpanda event streaming', 'VPS required - cron polling active instead'),
        'feast' => array('Feast feature store', 'VPS required - file-based features active instead'),
        'mlflow' => array('MLflow experiment tracking', 'VPS required - git + JSONL records active instead'),
        'postgres' => array('PostgreSQL + TimescaleDB', 'VPS required - WordPress MySQL + JSONL active instead'),
        'fastapi' => array('FastAPI / ONNX model serving', 'VPS required - static predictions push active instead'),
        'temporal_transformer' => array('Temporal Transformer live model', 'Paid event feed required - prototype + analytic engine active'),
        'gnn' => array('GNN tactical model', 'Event/tracking feed required - rule-based tactical notes active'),
    );
}

function vendicator_rank($lifetime) {
    $ladder = array(
        // matched to the reward-points scale: 2500 shows as 25.00
        array('points' => 0, 'name' => 'Rookie', 'icon' => '⚽'),
        array('points' => 2500, 'name' => 'Contender', 'icon' => '🛡️'),
        array('points' => 10000, 'name' => 'Sharp', 'icon' => '🎯'),
        array('points' => 30000, 'name' => 'Analyst', 'icon' => '📊'),
        array('points' => 70000, 'name' => 'Oracle', 'icon' => '🔮'),
        array('points' => 150000, 'name' => 'Legend', 'icon' => '👑'),
    );
    $current = $ladder[0];
    $next = null;
    foreach ($ladder as $r) {
        if ($lifetime >= (int) $r['points']) { $current = $r; }
        elseif (!$next) { $next = $r; }
    }
    return array($current, $next);
}

function vendicator_league_catalogue() {
    return array(
        'E0' => array('Premier League', 'England', 'league'),
        'E1' => array('Championship', 'England', 'league'),
        'E2' => array('League One', 'England', 'league'),
        'E3' => array('League Two', 'England', 'league'),
        'FAC' => array('FA Cup', 'England', 'cup'),
        'EFLC' => array('EFL Cup', 'England', 'cup'),
        'SP1' => array('La Liga', 'Spain', 'league'),
        'SP2' => array('La Liga 2', 'Spain', 'league'),
        'CDR' => array('Copa del Rey', 'Spain', 'cup'),
        'I1' => array('Serie A', 'Italy', 'league'),
        'I2' => array('Serie B', 'Italy', 'league'),
        'COPIT' => array('Coppa Italia', 'Italy', 'cup'),
        'D1' => array('Bundesliga', 'Germany', 'league'),
        'F1' => array('Ligue 1', 'France', 'league'),
        'UCL' => array('Champions League', 'Europe', 'cup'),
        'UEL' => array('Europa League', 'Europe', 'cup'),
        'SC0' => array('Scottish Premiership', 'Scotland', 'league'),
        'N1' => array('Eredivisie', 'Netherlands', 'league'),
        'P1' => array('Primeira Liga', 'Portugal', 'league'),
        'B1' => array('Pro League', 'Belgium', 'league'),
        'T1' => array('Super Lig', 'Turkey', 'league'),
        'G1' => array('Super League', 'Greece', 'league'),
        'BRA' => array('Serie A (Brazil)', 'Brazil', 'league'),
        'ARG' => array('Primera Division', 'Argentina', 'league'),
        'MLS' => array('MLS', 'USA', 'league'),
        'JPN' => array('J1 League', 'Japan', 'league'),
    );
}

function vendicator_leagues() {
    $enabled = get_option('vendicator_enabled_leagues');
    if (!is_array($enabled)) {
        $enabled = array('E0', 'E1', 'E2', 'E3', 'FAC', 'EFLC', 'SP1', 'SP2',
            'CDR', 'I1', 'I2', 'COPIT', 'D1', 'F1', 'UCL', 'UEL');
    }
    $cat = vendicator_league_catalogue();
    $out = array();
    foreach ($enabled as $code) {
        if (isset($cat[$code])) { $out[$code] = $cat[$code][0] . ' (' . $cat[$code][1] . ')'; }
    }
    foreach ((array) get_option('vendicator_custom_leagues', array()) as $code => $cfg) {
        $out[$code] = $cfg[0] . ' (' . $cfg[1] . ')';
    }
    return $out;
}

function vendicator_user_tier($user_id) {
    if (user_can($user_id, 'manage_options')) { return 'gold'; }
    $lifetime = (int) get_user_meta($user_id, 'vendicator_lifetime_points', true);
    $tier = 'free';
    foreach (vendicator_tiers() as $slug => $cfg) {
        if ($lifetime >= (int) $cfg['points']) { $tier = $slug; }
    }
    $stored = get_user_meta($user_id, 'vendicator_tier', true);
    return $stored ? $stored : $tier;
}

function vendicator_init_user($user_id) {
    add_user_meta($user_id, 'vendicator_points_balance', 0, true);
    add_user_meta($user_id, 'vendicator_lifetime_points', 0, true);
    add_user_meta($user_id, 'vendicator_tier', 'free', true);
    add_user_meta($user_id, 'vendicator_points_history', wp_json_encode(array()), true);
    add_user_meta($user_id, 'vendicator_bets', wp_json_encode(array()), true);
    add_user_meta($user_id, 'vendicator_joined', gmdate('c'), true);
}
add_action('user_register', 'vendicator_init_user');

/* ------------------------------------------------------------------- REST */

add_action('rest_api_init', function () {
    register_rest_route('vendicator/v1', '/predictions', array(
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function ($req) {
            update_option('vendicator_predictions', $req->get_json_params());
            update_option('vendicator_predictions_updated', gmdate('c'));
            return array('ok' => true);
        },
    ));
    register_rest_route('vendicator/v1', '/results', array(
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function ($req) {
            $body = $req->get_json_params();
            $results = isset($body['results']) ? (array) $body['results'] : array();
            $settled = vendicator_settle_bets($results);
            update_option('vendicator_last_results', $results);
            return array('ok' => true, 'bets_settled' => $settled);
        },
    ));
    register_rest_route('vendicator/v1', '/records', array(
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function () {
            $users = array();
            foreach (get_users(array('fields' => 'all')) as $u) {
                $users[] = array(
                    'id' => $u->ID, 'username' => $u->user_login,
                    'email' => $u->user_email,
                    'joined' => get_user_meta($u->ID, 'vendicator_joined', true),
                    'tier' => vendicator_user_tier($u->ID),
                    'points_balance' => (int) get_user_meta($u->ID, 'vendicator_points_balance', true),
                    'lifetime_points' => (int) get_user_meta($u->ID, 'vendicator_lifetime_points', true),
                    'banned' => (bool) get_user_meta($u->ID, 'vendicator_banned', true),
                    'bets' => json_decode((string) get_user_meta($u->ID, 'vendicator_bets', true), true),
                );
            }
            return array('schema_version' => 2, 'exported' => gmdate('c'), 'users' => $users);
        },
    ));
});

function vendicator_settle_bets($results) {
    $map = array();
    foreach ($results as $r) {
        if (isset($r['fixture'])) { $map[$r['fixture']] = $r; }
    }
    if (!$map) { return 0; }
    $count = 0;
    foreach (get_users() as $u) {
        $bets = json_decode((string) get_user_meta($u->ID, 'vendicator_bets', true), true);
        if (!is_array($bets)) { continue; }
        $changed = false;
        $streak = (int) get_user_meta($u->ID, 'vendicator_streak', true);
        foreach ($bets as $i => $b) {
            if (!empty($b['settled']) || empty($map[$b['fixture']])) { continue; }
            $r = $map[$b['fixture']];
            $difficulty = isset($r['difficulty']) ? (float) $r['difficulty'] : 1.0;
            $won = ($b['pick'] === $r['result']);
            // on the decimal scale a correct single call is worth roughly a
            // 3.00 shot, lifted by how hard the fixture was to price
            $points = $won ? (int) round(300 * $difficulty) : 0;
            $streak = $won ? $streak + 1 : 0;
            if ($streak === 3) { $points += 800; }
            elseif ($streak === 5) { $points += 2500; }
            elseif ($streak === 10) { $points += 10000; }
            $bets[$i]['settled'] = true;
            $bets[$i]['result'] = $r['result'];
            $bets[$i]['score'] = isset($r['score']) ? $r['score'] : '';
            $bets[$i]['points'] = $points;
            $bal = (int) get_user_meta($u->ID, 'vendicator_points_balance', true) + $points;
            $life = (int) get_user_meta($u->ID, 'vendicator_lifetime_points', true) + $points;
            update_user_meta($u->ID, 'vendicator_points_balance', $bal);
            update_user_meta($u->ID, 'vendicator_lifetime_points', $life);
            $hist = json_decode((string) get_user_meta($u->ID, 'vendicator_points_history', true), true);
            if (!is_array($hist)) { $hist = array(); }
            $hist[] = $life;
            update_user_meta($u->ID, 'vendicator_points_history', wp_json_encode($hist));
            $changed = true;
            $count++;
        }
        update_user_meta($u->ID, 'vendicator_streak', $streak);
        if ($changed) {
            update_user_meta($u->ID, 'vendicator_bets', wp_json_encode($bets));
        }
        $count += vendicator_settle_slips($u->ID, $map);
    }
    return $count;
}

/**
 * Settle bet-builder slips for one member.
 *
 * A slip that lands in full is paid its base points multiplied by the win
 * bonus - Scoreline, the risk the member chose to carry, and the price of
 * their longest leg. Three or more losing legs deducts points and the card
 * is removed from play but kept in the record as a loss, exactly as the
 * builder warns before the slip is placed.
 */
function vendicator_settle_slips($user_id, $map) {
    $slips = json_decode((string) get_user_meta($user_id, 'vendicator_slips', true), true);
    if (!is_array($slips)) { return 0; }
    $changed = 0;
    foreach ($slips as $i => $s) {
        if (!empty($s['settled']) || empty($map[$s['fixture']])) { continue; }
        $r = $map[$s['fixture']];
        $score = isset($r['score']) ? $r['score'] : '';
        $legs = array();
        $void = 0;
        foreach ((array) $s['legs'] as $leg) {
            $won = vendicator_settle_leg($leg, $r['result'], $score);
            if ($won === null) { $void++; continue; }
            $leg['won'] = $won;
            $legs[] = $leg;
        }
        if (!$legs) {
            // nothing on the slip could be settled from the final score
            $slips[$i]['settled'] = true;
            $slips[$i]['outcome'] = 'void';
            $slips[$i]['points'] = 0;
            $slips[$i]['score'] = $score;
            $slips[$i]['note'] = 'Voided - none of these markets can be '
                . 'settled from the final score.';
            $changed++;
            continue;
        }
        $res = vendicator_settle_slip($legs,
            isset($s['scoreline']) ? (float) $s['scoreline'] : 50.0);
        $slips[$i]['settled'] = true;
        $slips[$i]['outcome'] = $res['outcome'];
        $slips[$i]['points'] = (int) $res['points'];
        $slips[$i]['risk'] = $res['risk'];
        $slips[$i]['score'] = $score;
        $slips[$i]['result'] = $r['result'];
        $slips[$i]['legs_settled'] = $legs;
        $slips[$i]['note'] = $res['note']
            . ($void ? ' ' . $void . ' leg(s) voided - not settleable from '
                . 'the final score.' : '');
        $pts = (int) $res['points'];
        $bal = (int) get_user_meta($user_id, 'vendicator_points_balance', true) + $pts;
        $life = (int) get_user_meta($user_id, 'vendicator_lifetime_points', true)
            + max($pts, 0);
        update_user_meta($user_id, 'vendicator_points_balance', max($bal, 0));
        update_user_meta($user_id, 'vendicator_lifetime_points', $life);
        $hist = json_decode((string) get_user_meta($user_id, 'vendicator_points_history', true), true);
        if (!is_array($hist)) { $hist = array(); }
        $hist[] = $life;
        update_user_meta($user_id, 'vendicator_points_history', wp_json_encode($hist));
        $changed++;
    }
    if ($changed) {
        update_user_meta($user_id, 'vendicator_slips', wp_json_encode($slips));
    }
    return $changed;
}

/* ----------------------------------------------------------------- theming */

function vendicator_css() {
    return '
:root{--bg0:#0C0E12;--bg1:#14171D;--glass:rgba(255,255,255,.045);
--edge:rgba(255,255,255,.10);--lime:#C6FF4D;--lime2:#9BE81F;--mint:#7CFFCB;
--white:#F7FAF2;--muted:#9AA3B2;}
.vd-wrap{color:var(--white);font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;
background:radial-gradient(900px 400px at 85% -10%,rgba(198,255,77,.10),transparent 60%),
linear-gradient(160deg,var(--bg1),var(--bg0) 60%);min-height:100vh;
padding:34px 16px;margin:0 -8px;}
.vendicator-page,.vendicator-page main{background:var(--bg0);}
.vd-inner{max-width:1060px;margin:0 auto;display:grid;gap:18px;}
.vd-card{background:var(--glass);border:1px solid var(--edge);border-radius:16px;
padding:22px 24px;backdrop-filter:blur(14px);
box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 10px 30px rgba(0,0,0,.45);}
.vd-card h2,.vd-card h3{color:var(--white);margin:0 0 12px;}
.vd-card h3{font-size:12.5px;text-transform:uppercase;letter-spacing:2px;
color:var(--lime);text-shadow:0 0 14px rgba(198,255,77,.35);}
.vd-logo{font-size:26px;font-weight:800;letter-spacing:4px;color:var(--white);}
.vd-logo b{color:var(--lime);text-shadow:0 0 18px rgba(198,255,77,.55);}
.vd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:18px;}
.vd-wrap input[type=text],.vd-wrap input[type=email],.vd-wrap input[type=password]{
width:100%;background:rgba(255,255,255,.06);border:1px solid var(--edge);
border-radius:10px;color:var(--white);padding:10px 12px;margin:4px 0 12px;}
.vd-btn,.vd-wrap input[type=submit]{display:inline-block;cursor:pointer;border:0;
background:linear-gradient(120deg,var(--lime),var(--lime2));color:#101505;
font-weight:800;border-radius:999px;padding:10px 22px;
box-shadow:0 0 22px rgba(198,255,77,.35);text-decoration:none;}
.vd-muted{color:var(--muted);font-size:13px;}
.vd-bar{display:flex;height:42px;border-radius:12px;overflow:hidden;
border:1px solid var(--edge);font-weight:800;margin:14px 0 6px;}
.vd-bar div{display:flex;align-items:center;justify-content:center;min-width:34px;}
.vd-h{background:linear-gradient(180deg,var(--lime),var(--lime2));color:#101505;}
.vd-d{background:#343B47;color:var(--white);}
.vd-a{background:linear-gradient(180deg,var(--mint),#45D6A0);color:#062A1D;}
.vd-table{width:100%;border-collapse:collapse;font-size:14px;color:var(--white);}
.vd-table td{padding:5px 4px;border-bottom:1px solid rgba(255,255,255,.06);}
.vd-table td:last-child{text-align:right;font-weight:700;}
.vd-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.vd-tabs a{color:var(--muted);text-decoration:none;padding:7px 15px;
border-radius:999px;border:1px solid var(--edge);font-size:13px;}
.vd-tabs a.on{color:#101505;background:linear-gradient(120deg,var(--lime),var(--lime2));font-weight:700;}
.vd-lock{opacity:.45;}
.vd-pill{display:inline-block;background:rgba(255,255,255,.06);
border:1px solid var(--edge);border-radius:999px;padding:4px 12px;
margin:3px 4px 3px 0;font-size:12.5px;color:var(--white);}
/* hover layering: every card that owns a hover panel lifts above siblings */
.vd-inner{position:relative;}
.vd-card{position:relative;z-index:1;}
.vd-card:hover{z-index:60;}
.vd-grid{position:relative;}
.vd-wide{grid-column:1/-1;}
.vd-dots{display:inline-flex;gap:5px;}
.vd-dot{width:11px;height:11px;border-radius:50%;display:inline-block;
box-shadow:0 0 8px currentColor;background:currentColor;}
.vd-dot.goal{color:#C6FF4D;}
.vd-dot.assist{color:#7CFFCB;}
.vd-dot.blank{color:#FF5C5C;box-shadow:0 0 7px rgba(255,92,92,.7);}
.vd-dot.dnp{color:#3A414D;box-shadow:none;opacity:.75;}
.vd-players td{vertical-align:middle;padding:9px 6px;}
.vd-players td:last-child{text-align:left;}
.vd-value{display:inline-block;background:rgba(198,255,77,.14);
border:1px solid rgba(198,255,77,.4);color:var(--lime);border-radius:999px;
padding:3px 11px;font-weight:800;font-size:12.5px;}
.vd-psel{display:flex;gap:7px;flex-wrap:wrap;}
.vd-psel label{border:1px solid var(--edge);border-radius:10px;
padding:6px 10px;font-size:12px;cursor:pointer;background:rgba(255,255,255,.05);
white-space:nowrap;}
.vd-psel label:hover{border-color:var(--lime);
box-shadow:0 0 14px rgba(198,255,77,.18);}
.vd-psel b{color:var(--lime);}
.vd-playerlink{color:var(--white);text-decoration:none;position:relative;
display:inline-flex;align-items:center;gap:9px;}
.vd-playerpic{width:34px;height:34px;border-radius:50%;object-fit:cover;
background:rgba(255,255,255,.08);border:1px solid var(--edge);}
.vd-playerlink .tip3{display:none;position:absolute;top:40px;left:0;z-index:80;
width:265px;background:rgba(16,19,25,.98);border:1px solid rgba(198,255,77,.4);
border-radius:12px;padding:11px 13px;font-size:12.5px;line-height:1.55;
font-weight:400;box-shadow:0 0 30px rgba(198,255,77,.18);}
.vd-playerlink:hover .tip3{display:block;}
.vd-nav{position:sticky;top:0;z-index:400;display:flex;align-items:center;
gap:12px;flex-wrap:wrap;margin:-34px -16px 18px;padding:12px 20px;
background:linear-gradient(120deg,rgba(28,32,39,.9),rgba(16,19,25,.92));
border-bottom:1px solid rgba(198,255,77,.35);backdrop-filter:blur(16px);
box-shadow:0 8px 30px rgba(0,0,0,.5);}
.vd-nav-logo{color:var(--white);text-decoration:none;font-weight:800;
letter-spacing:3px;font-size:17px;}
.vd-nav-logo b{color:var(--lime);text-shadow:0 0 14px rgba(198,255,77,.5);}
.vd-nav-grid{display:inline-flex;align-items:center;gap:8px;text-decoration:none;
background:linear-gradient(120deg,var(--lime),var(--lime2));color:#101505;
font-weight:800;border-radius:999px;padding:7px 16px;font-size:13px;
box-shadow:0 0 20px rgba(198,255,77,.4);}
.vd-nav-grid:hover{filter:brightness(1.08);transform:translateY(-1px);}
.vd-mascot{font-size:19px;filter:drop-shadow(0 0 5px rgba(0,0,0,.35));}
.vd-nav-spacer{flex:1;}
.vd-nav-link{color:var(--muted);text-decoration:none;font-size:13px;
padding:6px 12px;border-radius:999px;border:1px solid transparent;}
.vd-nav-link:hover{color:var(--white);border-color:var(--edge);}
.vd-nav-link.on{color:var(--lime);border-color:rgba(198,255,77,.45);}
.vd-nav-score{color:var(--white);font-size:13px;background:rgba(255,255,255,.06);
border:1px solid var(--edge);border-radius:999px;padding:5px 13px;}
.vd-nav-score b{color:var(--lime);}
.vd-nav-clock{color:var(--lime);font-weight:800;font-size:14px;
font-variant-numeric:tabular-nums;letter-spacing:1px;}
.vd-nav-sl{color:var(--mint);font-size:12.5px;background:rgba(124,255,203,.1);
border:1px solid rgba(124,255,203,.4);border-radius:999px;padding:5px 12px;
font-variant-numeric:tabular-nums;}
.vd-nav-sl b{color:var(--white);font-size:13.5px;}
.vd-navreward{font-style:normal;color:var(--lime);font-weight:800;
margin-left:6px;padding-left:8px;border-left:1px solid rgba(255,255,255,.18);}
.vd-sl-badge{display:inline-flex;align-items:center;gap:7px;
background:rgba(124,255,203,.12);border:1px solid rgba(124,255,203,.4);
color:var(--mint);border-radius:999px;padding:4px 12px;font-size:12.5px;
font-weight:700;}
.vd-sl-badge small{color:var(--muted);font-weight:400;}
.vd-slrow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;
margin:10px 0 2px;padding-top:10px;border-top:1px solid rgba(255,255,255,.07);}
.vd-slhead{color:var(--muted);font-size:12px;letter-spacing:1px;
text-transform:uppercase;}
.vd-slhead b{color:var(--mint);font-size:15px;}
.vd-opts{display:grid;gap:8px;}
.vd-opt{display:grid;grid-template-columns:1fr auto auto;gap:10px;
align-items:center;border:1px solid var(--edge);border-radius:11px;
padding:9px 13px;cursor:pointer;background:rgba(255,255,255,.045);
transition:border-color .12s,box-shadow .12s,background .12s;}
.vd-opt:hover{border-color:rgba(198,255,77,.55);
box-shadow:0 0 15px rgba(198,255,77,.14);}
.vd-opt input{display:none;}
.vd-opt-label{font-size:13.5px;}
.vd-opt-pct{color:var(--muted);font-size:12px;font-variant-numeric:tabular-nums;}
.vd-opt-pts{color:var(--lime);font-weight:800;font-size:13px;
font-variant-numeric:tabular-nums;}
.vd-opt-extra{grid-column:1/-1;color:var(--muted);font-size:11px;}
.vd-opt.on{border-color:var(--lime);background:rgba(198,255,77,.13);
box-shadow:0 0 18px rgba(198,255,77,.22);}
.vd-opt.on .vd-opt-label{font-weight:700;}
.vd-subhead{margin:12px 0 6px;color:var(--lime);font-weight:700;font-size:12.5px;}
.vd-cardgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));
gap:14px;}
.vd-gcard{position:relative;display:flex;flex-direction:column;gap:6px;
text-decoration:none;color:var(--white);border:1px solid var(--edge);
border-radius:14px;padding:14px;background:rgba(255,255,255,.05);
transition:transform .14s,box-shadow .14s,border-color .14s;}
.vd-gcard:hover{transform:translateY(-3px);border-color:rgba(198,255,77,.6);
box-shadow:0 0 26px rgba(198,255,77,.22);z-index:50;}
.vd-gcard.silver{border-color:rgba(203,213,225,.55);
box-shadow:0 0 18px rgba(203,213,225,.18);}
.vd-gcard.gold{border-color:rgba(255,196,84,.6);
box-shadow:0 0 20px rgba(255,196,84,.25);}
.vd-gcode{font-weight:800;letter-spacing:2px;color:var(--lime);font-size:13px;}
.vd-gname{font-size:13.5px;}
.vd-gkick{color:var(--muted);font-size:11.5px;}
.vd-gbar{display:flex;height:7px;border-radius:5px;overflow:hidden;margin-top:4px;}
.vd-gbar .h{background:var(--lime);}
.vd-gbar .d{background:#3A414D;}
.vd-gbar .a{background:var(--mint);}
.vd-ghover{display:none;position:absolute;left:0;top:100%;z-index:120;width:250px;
background:rgba(16,19,25,.98);border:1px solid rgba(198,255,77,.45);
border-radius:12px;padding:12px 14px;font-size:12.5px;line-height:1.55;
box-shadow:0 0 30px rgba(198,255,77,.2);backdrop-filter:blur(12px);}
.vd-gcard:hover .vd-ghover{display:block;}
.vd-pcols{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));
gap:14px;}
.vd-pcol h4{margin:0 0 8px;font-size:11.5px;letter-spacing:1.5px;
text-transform:uppercase;color:var(--mint);}
.vd-pcol .vd-opt{grid-template-columns:1fr auto;margin-bottom:7px;padding:7px 11px;}
.vd-popt .vd-opt-label{display:flex;flex-direction:column;gap:3px;font-size:13px;}
.vd-pstat{color:var(--muted);font-size:11px;display:flex;align-items:center;gap:7px;}
.vd-pcol .vd-playerpic{width:24px;height:24px;}
.vd-slip{position:sticky;bottom:8px;z-index:90;margin-top:16px;
display:flex;align-items:center;gap:14px;flex-wrap:wrap;
background:linear-gradient(120deg,rgba(28,32,39,.97),rgba(20,23,29,.97));
border:1px solid rgba(198,255,77,.45);border-radius:14px;padding:13px 18px;
box-shadow:0 0 32px rgba(198,255,77,.18),0 14px 34px rgba(0,0,0,.6);
backdrop-filter:blur(10px);}
.vd-slip-main{display:flex;align-items:baseline;gap:12px;flex:1;}
.vd-slip-count{color:var(--muted);font-size:13px;}
.vd-slip-total{font-size:27px;font-weight:800;color:var(--lime);
font-variant-numeric:tabular-nums;text-shadow:0 0 16px rgba(198,255,77,.45);}
.vd-slip-total.neg{color:#FF6B6B;text-shadow:0 0 16px rgba(255,107,107,.45);}
.vd-slip-meta{display:flex;gap:12px;color:var(--muted);font-size:12px;}
.vd-slip-risk.hot{color:#FF6B6B;font-weight:700;}
.vd-slip-sl{color:var(--mint);}
.vd-details summary{cursor:pointer;color:var(--lime);font-weight:700;
font-size:12.5px;letter-spacing:1.4px;text-transform:uppercase;
padding:10px 0 2px;list-style:none;}
.vd-details summary::-webkit-details-marker{display:none;}
.vd-details summary:before{content:"\25B8 ";}
.vd-details[open] summary:before{content:"\25BE ";}
.vd-details[open] summary{margin-bottom:6px;}
.vd-filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.vd-filters>div{flex:1;min-width:170px;}
.vd-clock{font-size:40px;font-weight:800;letter-spacing:3px;color:var(--lime);
text-shadow:0 0 22px rgba(198,255,77,.5);font-variant-numeric:tabular-nums;}
.vd-clock small{font-size:22px;opacity:.75;}
.vd-ticker{margin-top:12px;border:1px solid var(--edge);border-radius:12px;
background:rgba(255,255,255,.05);padding:12px 16px;position:relative;
display:flex;align-items:center;gap:12px;min-height:52px;cursor:pointer;
z-index:70;}
.vd-ticker:hover{z-index:200;}
.vd-tick-code{font-weight:800;letter-spacing:2px;color:var(--white);}
.vd-tick-odds{color:var(--mint);font-variant-numeric:tabular-nums;}
.vd-tick-soon{color:var(--lime);font-weight:700;}
.vd-tick-live{color:#FF6B6B;font-weight:700;}
.vd-ticker .vd-hover{display:none;position:absolute;left:0;top:56px;z-index:300;
width:min(460px,90vw);background:rgba(16,19,25,.98);border:1px solid rgba(198,255,77,.4);
border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.55;
box-shadow:0 0 36px rgba(198,255,77,.18),0 20px 44px rgba(0,0,0,.65);}
.vd-ticker:hover .vd-hover{display:block;}
.vd-teamlink{color:var(--white);text-decoration:none;display:inline-flex;
align-items:center;gap:8px;position:relative;}
.vd-teambadge{width:30px;height:30px;object-fit:contain;
filter:drop-shadow(0 0 7px rgba(198,255,77,.3));}
.vd-teamlink .tip2{display:none;position:absolute;top:36px;left:0;z-index:120;
width:250px;background:rgba(16,19,25,.98);border:1px solid rgba(198,255,77,.4);
border-radius:12px;padding:11px 13px;font-size:12.5px;font-weight:400;
line-height:1.5;color:var(--white);box-shadow:0 0 30px rgba(198,255,77,.16);}
.vd-teamlink:hover .tip2{display:block;}
.vd-toasts{position:fixed;right:18px;bottom:18px;z-index:99999;
display:grid;gap:10px;max-width:330px;}
.vd-toast{background:rgba(16,19,25,.97);border:1px solid var(--lime);
border-left:5px solid var(--lime);border-radius:12px;padding:13px 34px 13px 15px;
color:var(--white);font-size:13.5px;position:relative;
box-shadow:0 0 30px rgba(198,255,77,.25),0 16px 40px rgba(0,0,0,.6);}
.vd-toast.lost{border-color:#FF6B6B;border-left-color:#FF6B6B;
box-shadow:0 0 30px rgba(255,107,107,.22),0 16px 40px rgba(0,0,0,.6);}
.vd-toast .x{position:absolute;top:8px;right:11px;color:var(--muted);
text-decoration:none;font-size:14px;}
.vd-wrap select{appearance:none;background:rgba(255,255,255,.06)
url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\'><path d=\'M1 1l5 5 5-5\' stroke=\'%23C6FF4D\' stroke-width=\'2\' fill=\'none\'/></svg>")
no-repeat right 14px center;border:1px solid var(--edge);border-radius:10px;
color:var(--white);padding:11px 38px 11px 14px;font-size:14px;width:100%;
margin:4px 0 12px;cursor:pointer;}
.vd-wrap select:focus{outline:none;border-color:var(--lime);
box-shadow:0 0 0 3px rgba(198,255,77,.18);}
.vd-wrap select option{background:#14171D;color:var(--white);}
.vd-pickrow{display:flex;gap:10px;flex-wrap:wrap;}
.vd-pickrow label{flex:1;min-width:92px;text-align:center;cursor:pointer;
border:1px solid var(--edge);border-radius:12px;padding:12px 8px;
background:rgba(255,255,255,.05);transition:.15s;}
.vd-pickrow label:hover{border-color:var(--lime);
box-shadow:0 0 16px rgba(198,255,77,.18);}
.vd-pickrow input{display:block;margin:0 auto 6px;accent-color:#C6FF4D;}
.vd-rank{display:flex;align-items:center;gap:14px;font-size:20px;color:var(--white);}
.vd-rank-icon{font-size:44px;filter:drop-shadow(0 0 14px rgba(198,255,77,.55));}
.vendicator-page h1.entry-title,.vendicator-page .wp-block-post-title,
.vendicator-page h1:not([class]),.vendicator-page .entry-header{display:none;}
a.vd-logo{display:inline-block;text-decoration:none;}
a.vd-logo:hover b{filter:brightness(1.15);}
.vd-footer{margin-top:26px;padding:18px 4px 6px;text-align:center;
color:var(--muted);font-size:12.5px;letter-spacing:.6px;
border-top:1px solid rgba(255,255,255,.07);}
.vd-footer a{color:var(--lime);text-decoration:none;margin-left:12px;
border-bottom:1px solid rgba(198,255,77,.4);}
.vd-footer a:hover{color:var(--white);}
/* long option ladders scroll inside the card instead of stretching it */
.vd-scroll{max-height:210px;overflow-y:auto;padding-right:6px;}
.vd-scroll.tall{max-height:290px;}
.vd-scroll::-webkit-scrollbar{width:8px;}
.vd-scroll::-webkit-scrollbar-thumb{background:rgba(198,255,77,.28);
border-radius:8px;}
.vd-scroll::-webkit-scrollbar-track{background:rgba(255,255,255,.04);
border-radius:8px;}
/* player markets: one row per player, one chip per threshold */
.vd-prows{display:grid;gap:8px;}
.vd-prow{display:grid;grid-template-columns:minmax(180px,1.1fr) 2fr;gap:12px;
align-items:center;border:1px solid var(--edge);border-radius:12px;
padding:8px 12px;background:rgba(255,255,255,.04);}
.vd-pwho{display:flex;flex-direction:column;gap:3px;min-width:0;}
.vd-pident{display:flex;flex-direction:column;line-height:1.25;min-width:0;}
.vd-pname{font-size:13.5px;font-weight:600;}
.vd-pteam{color:var(--mint);font-size:11px;letter-spacing:.4px;}
.vd-pos{font-style:normal;font-size:9.5px;font-weight:800;letter-spacing:1px;
background:rgba(198,255,77,.16);border:1px solid rgba(198,255,77,.4);
color:var(--lime);border-radius:5px;padding:1px 5px;vertical-align:middle;}
.vd-plines{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end;}
.vd-chip{display:inline-flex;align-items:center;gap:7px;grid-template-columns:none;
padding:6px 11px;border-radius:9px;font-size:12px;white-space:nowrap;}
.vd-chip-label{color:var(--white);}
.vd-chip-pts{color:var(--lime);font-weight:800;font-variant-numeric:tabular-nums;}
.vd-pcat>summary{font-size:11.5px;}
.vd-shirt{display:none;font-size:15px;font-weight:800;color:#101505;
background:linear-gradient(120deg,var(--lime),var(--lime2));border-radius:8px;
padding:1px 8px;margin-right:9px;vertical-align:middle;}
.vd-shirt.on{display:inline-block;}
/* the star card: the best value the board is showing right now */
.vd-star{border-color:rgba(255,196,84,.6);
box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 0 30px rgba(255,196,84,.2),
0 10px 30px rgba(0,0,0,.45);}
.vd-starflag{display:inline-block;background:linear-gradient(120deg,#FFC454,#FFA51F);
color:#2B1B00;font-weight:800;font-size:11px;letter-spacing:1.4px;
text-transform:uppercase;border-radius:999px;padding:4px 13px;margin-bottom:10px;}
.vd-countdown{color:var(--lime);font-variant-numeric:tabular-nums;}
.vd-live{color:#FF6B6B;}
.vd-card.vd-started{opacity:.6;}
.vd-slip-bonus{color:var(--lime);font-weight:700;}
.vd-reward{display:inline-flex;align-items:center;gap:7px;
background:rgba(198,255,77,.12);border:1px solid rgba(198,255,77,.42);
color:var(--lime);border-radius:999px;padding:4px 12px;font-size:11px;
font-weight:800;letter-spacing:1.2px;}
.vd-reward b{color:var(--white);font-size:13.5px;letter-spacing:0;
font-variant-numeric:tabular-nums;}
/* league table: promotion, play-off and relegation zones */
.vd-tablewrap{overflow-x:auto;}
.vd-ltable td{white-space:nowrap;}
.vd-ltable td:last-child{text-align:right;}
.vd-lrow.up{background:linear-gradient(90deg,rgba(155,232,31,.14),transparent 45%);}
.vd-lrow.po{background:linear-gradient(90deg,rgba(255,196,84,.13),transparent 45%);}
.vd-lrow.down{background:linear-gradient(90deg,rgba(255,92,92,.14),transparent 45%);}
.vd-arrow{font-size:10px;}
.vd-arrow.up{color:#9BE81F;}
.vd-arrow.po{color:#FFC454;}
.vd-arrow.down{color:#FF5C5C;}
.vd-legend{margin-top:10px;color:var(--muted);font-size:11.5px;
display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.vd-cmpcell{display:flex;gap:5px;justify-content:flex-end;}
.vd-cmpcell label{cursor:pointer;}
.vd-cmpcell input{display:none;}
.vd-cmpcell span{display:inline-block;width:22px;height:22px;line-height:20px;
text-align:center;font-size:11px;font-weight:800;border-radius:6px;
border:1px solid var(--edge);color:var(--muted);}
.vd-cmpcell input:checked+span{background:linear-gradient(120deg,var(--lime),var(--lime2));
color:#101505;border-color:var(--lime);}
.vd-cmphead{display:flex;align-items:center;gap:18px;flex-wrap:wrap;
margin-bottom:14px;font-size:17px;font-weight:700;}
.vd-vs{color:var(--muted);font-size:13px;font-weight:400;}
.vd-cmptable td{text-align:center;font-variant-numeric:tabular-nums;}
.vd-cmptable td:last-child{text-align:center;}
.vd-cmpname{color:var(--muted);font-size:12px;font-weight:400;
text-transform:uppercase;letter-spacing:1px;}
.vd-better{color:var(--lime);font-weight:800;}
.vd-lhead{display:flex;align-items:center;gap:10px;}
.vd-leaguebadge{width:24px;height:24px;object-fit:contain;}
.vd-gbadge{width:18px;height:18px;object-fit:contain;vertical-align:-3px;
margin-right:4px;}
/* a badge that never resolved should leave no gap, not an empty box.
   player photos keep their placeholder circle - that one is deliberate. */
img.vd-leaguebadge:not([src]),img.vd-gbadge:not([src]),
img.vd-teambadge:not([src]){display:none;}
.vd-gname em{color:var(--muted);font-style:normal;font-size:11px;
margin:0 3px;}
@media(max-width:640px){
.vd-prow{grid-template-columns:1fr;}
.vd-plines{justify-content:flex-start;}}';
}
add_action('wp_enqueue_scripts', function () {
    wp_register_style('vendicator', false);
    wp_enqueue_style('vendicator');
    wp_add_inline_style('vendicator', vendicator_css());
});
add_action('admin_enqueue_scripts', function () {
    wp_register_style('vendicator-admin', false);
    wp_enqueue_style('vendicator-admin');
    wp_add_inline_style('vendicator-admin',
        '.vd-admin-lock{opacity:.5;} .vd-admin-note{max-width:640px;color:#555;}');
});
add_filter('body_class', function ($classes) {
    if (is_page(array('login', 'dashboard', 'account', 'team',
                      'leagues', 'player', 'help', 'compare', 'cardgrid'))) {
        $classes[] = 'vendicator-page';
    }
    return $classes;
});

/**
 * The wordmark, always a link home. It appears on most pages and members
 * reasonably expect clicking it to take them back to the predictions home,
 * so it is never rendered as dead text.
 */
function vendicator_logo($suffix = '') {
    return '<a class="vd-logo" href="' . esc_url(vendicator_page_url('dashboard'))
        . '">VENDI<b>CATOR</b>'
        . ($suffix ? ' <span class="vd-muted" style="font-size:14px;">'
            . esc_html($suffix) . '</span>' : '') . '</a>';
}

/** Footer shown on every Vendicator page. */
function vendicator_footer() {
    return '<div class="vd-footer">&copy; ' . esc_html(gmdate('Y'))
        . ' Vendicator <a href="' . esc_url(vendicator_page_url('help'))
        . '">Contact us</a></div>';
}

/**
 * Standard page shell: floating nav on top, footer underneath, everything
 * wrapped in the glass container. Every shortcode goes through this so a
 * page can never end up without navigation.
 */
function vendicator_shell($active, $body) {
    return '<div class="vd-wrap"><div class="vd-inner">'
        . vendicator_nav($active) . $body . vendicator_footer()
        . '</div></div>';
}

/** Floating glass navigation shown on every Vendicator page. */
function vendicator_nav($active = '') {
    $uid = get_current_user_id();
    $items = array(
        'dashboard' => array('Predictions', '&#9917;'),
        'leagues' => array('League Tables', '&#127942;'),
        'account' => array('Account', '&#9881;'),
        'help' => array('Help', '&#63;'),
    );
    $out = '<div class="vd-nav"><a class="vd-nav-logo" href="'
        . esc_url(vendicator_page_url('dashboard'))
        . '">VENDI<b>CATOR</b></a>'
        . '<a class="vd-nav-grid" href="' . esc_url(vendicator_page_url('cardgrid'))
        . '" title="Enter the CardGrid"><span class="vd-mascot">&#127918;</span>'
        . 'CardGrid</a><span class="vd-nav-spacer"></span>';
    foreach ($items as $slug => $it) {
        $out .= '<a class="vd-nav-link' . ($active === $slug ? ' on' : '')
            . '" href="' . esc_url(vendicator_page_url($slug)) . '">'
            . $it[1] . ' ' . esc_html($it[0]) . '</a>';
    }
    if ($uid) {
        $life = (int) get_user_meta($uid, 'vendicator_lifetime_points', true);
        list($rank) = vendicator_rank($life);
        $out .= '<span class="vd-nav-score" title="' . esc_attr($rank['name'])
            . ' - ' . vendicator_pts($life) . ' lifetime reward points">'
            . $rank['icon'] . ' <b>' . vendicator_pts($life) . '</b></span>';
    }
    $out .= '<span class="vd-nav-sl" id="vd-nav-sl" title="Vendicator Scoreline">'
        . 'VS <b>--</b></span>'
        . '<span class="vd-nav-clock" id="vd-clock">--:--:--</span>';
    return $out . '</div>';
}

function vendicator_team_link($team, $league) {
    $url = add_query_arg(array('vd_team' => rawurlencode($team),
        'vd_league' => rawurlencode($league)), vendicator_page_url('team'));
    return '<a class="vd-teamlink" href="' . esc_url($url) . '">'
        . '<img class="vd-teambadge" data-badge="' . esc_attr($team) . '" alt="">'
        . esc_html($team)
        . '<span class="tip2" data-tip="' . esc_attr($team) . '">&hellip;</span></a>';
}

function vendicator_fixture_heading($p) {
    $parts = explode(' vs ', $p['fixture']);
    if (count($parts) !== 2) { return esc_html($p['fixture']); }
    return vendicator_team_link($parts[0], $p['league'])
        . ' <span class="vd-muted" style="font-size:14px;">vs</span> '
        . vendicator_team_link($parts[1], $p['league']);
}

function vendicator_dashboard_js($p) {
    // pages without a prediction payload (account, help) still need the
    // clock, badges and countdowns, so an empty fixture list is valid here
    $src = isset($p['fixtures']) ? $p['fixtures']
        : (isset($p['fixture']) ? array($p) : array());
    // Only the handful of fields the ticker and header badge actually read
    // are inlined. Shipping the whole payload put ~1.7MB of player markets
    // into the HTML of every page, which is a page-weight problem, not a
    // data problem - the cards render server-side from the full payload.
    $fx = array();
    foreach ($src as $one) {
        if (empty($one['final_calibrated'])) { continue; }
        $c = $one['final_calibrated'];
        $best = max((float) $c['home'], (float) $c['draw'], (float) $c['away']);
        $top = isset($one['markets_dixon_coles']['exact_score_top10'][0])
            ? array($one['markets_dixon_coles']['exact_score_top10'][0])
            : array(array('-', 0));
        $fx[] = array(
            'fixture' => $one['fixture'],
            'short' => isset($one['short']) ? $one['short'] : $one['fixture'],
            'home_team' => isset($one['home_team']) ? $one['home_team'] : '',
            'away_team' => isset($one['away_team']) ? $one['away_team'] : '',
            'kickoff' => isset($one['kickoff']) ? $one['kickoff'] : '',
            'kickoff_ts' => vendicator_kickoff_ts($one),
            'odds_board' => isset($one['odds_board']) ? $one['odds_board'] : null,
            'final_calibrated' => $c,
            'expected_goals' => isset($one['expected_goals'])
                ? $one['expected_goals'] : array('home' => '-', 'away' => '-'),
            'markets_dixon_coles' => array('exact_score_top10' => $top),
            'uncertainty_band_home_pct' => isset($one['uncertainty_band_home_pct'])
                ? $one['uncertainty_band_home_pct'] : array(0, 0),
            'reward_difficulty_multiplier' => isset($one['reward_difficulty_multiplier'])
                ? $one['reward_difficulty_multiplier'] : 1,
            'scoreline' => isset($one['scoreline']) ? array(
                'headline' => $one['scoreline']['headline'],
                'rating' => isset($one['scoreline']['rating'])
                    ? $one['scoreline']['rating'] : null,
                'home' => array('decimal' => $one['scoreline']['home']['decimal'],
                    'fractional' => $one['scoreline']['home']['fractional']),
                'away' => array('decimal' => $one['scoreline']['away']['decimal'],
                    'fractional' => $one['scoreline']['away']['fractional']),
            ) : null,
            'reward_points' => vendicator_leg_points($best,
                isset($one['reward_difficulty_multiplier'])
                    ? (float) $one['reward_difficulty_multiplier'] : 1.0),
        );
    }
    $data = array(
        'fixtures' => $fx,
        'tables' => isset($p['tables']) ? $p['tables'] : array(),
        'compareUrl' => add_query_arg('fx', '', vendicator_page_url('compare')),
    );
    $js = <<<'JS'
(function(){
function pad(n){return (n<10?"0":"")+n;}
/* One clock for the whole platform. Fixtures are published in UK time, so
   every date and time on the site is rendered in Europe/London no matter
   where the member is reading from - a kick-off never reads differently on
   two pages, or on two people's screens. */
var VD_TZ="Europe/London";
function tzParts(d){var p={};
(new Intl.DateTimeFormat("en-GB",{timeZone:VD_TZ,year:"numeric",month:"2-digit",
day:"2-digit",hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:false})
.formatToParts(d)).forEach(function(x){p[x.type]=x.value;});return p;}
function vdTime(d){var p=tzParts(d);return p.hour+":"+p.minute+":"+p.second;}
function vdDate(d){var p=tzParts(d);return p.day+"/"+p.month+"/"+p.year;}
function vdStamp(ts){if(!ts)return "";var d=new Date(ts*1000);var p=tzParts(d);
return p.day+"/"+p.month+"/"+p.year+" "+p.hour+":"+p.minute;}
setInterval(function(){var d=new Date();var p=tzParts(d);
var t=p.hour+":"+p.minute+":<small>"+p.second+"</small>";
var el=document.getElementById("vd-clock");if(el){el.innerHTML=t;
el.title="Platform time - "+VD_TZ;}
var el2=document.getElementById("vd-clock2");
if(el2)el2.innerHTML=t+' <span style="font-size:12px;color:#9AA3B2;">'
+vdDate(d)+" &middot; UK time</span>";},250);
var fx=(window.VD&&VD.fixtures)||[];var idx=0;
function parseKick(f){if(f&&f.kickoff_ts)return new Date(f.kickoff_ts*1000);
var k=f&&f.kickoff;var m=k&&k.match(/(\d+)\/(\d+)\/(\d+)\s+(\d+):(\d+)/);
return m?new Date(+m[3],m[2]-1,+m[1],+m[4],+m[5]):null;}
function tag(f){var kick=parseKick(f);if(!kick)return "";
var mins=Math.round((kick-new Date())/60000);
if(mins<=0&&mins>-130)return '<span class="vd-tick-live">&#9679; in play window</span>';
if(mins>0&&mins<=60)return '<span class="vd-tick-soon">&#9200; starts in '+mins+'m</span>';
if(mins>0)return '<span class="vd-muted">'+(f.kickoff_ts?vdStamp(f.kickoff_ts):f.kickoff)+'</span>';
return '<span class="vd-muted">finished</span>';}
function fmt(f){var odds=f.odds_board?["home","draw","away"].map(function(k){
var o=f.odds_board[k]&&f.odds_board[k][0];return o?o.odds:"-";}).join(" | "):"";
return '<span class="vd-tick-code">'+(f.short||f.fixture)+'</span> '+tag(f)
+' <span class="vd-tick-odds">'+odds+'</span>';}
function hover(f){var fc=f.final_calibrated;if(!fc)return "";
var fav=fc.home>=fc.draw&&fc.home>=fc.away?["home win",fc.home]:(fc.away>=fc.draw&&fc.away>=fc.home?["away win",fc.away]:["draw",fc.draw]);
var top=f.markets_dixon_coles.exact_score_top10[0];
return "<b>"+f.fixture+"</b><br>The model favours the "+fav[0]+" at <b>"+fav[1]
+"%</b>, driven by expected goals "+f.expected_goals.home+" - "+f.expected_goals.away
+". Most likely scoreline <b>"+top[0]+"</b> ("+top[1]+"%). 90% band on the home win: "
+f.uncertainty_band_home_pct.join("-")+"%. Reward difficulty x"+f.reward_difficulty_multiplier+".";}
var VD_COMPARE=(window.VD&&VD.compareUrl)||"";
/* header Vendicator Scoreline follows the ticker's current fixture */
function slRate(s,total){if(s&&s.rating!=null)return (+s.rating).toFixed(2);
return (Math.max(Math.min(+total||50,100),10)/10).toFixed(2);}
function navScore(f){var el=document.getElementById("vd-nav-sl");if(!el)return;
if(f&&f.scoreline){var s=f.scoreline;
el.innerHTML="VS <b>"+slRate(s,s.headline)+"</b> <small>"+(s.home&&s.home.decimal||"-")
+" / "+(s.away&&s.away.decimal||"-")+"</small>"
+(f.reward_points?' <i class="vd-navreward">'+(f.reward_points/100).toFixed(2)+'</i>':"");
el.title="Vendicator Scoreline "+slRate(s,s.headline)+" (composite "+s.headline
+"/100) · "+(f.home_team||"home")+" "
+(s.home?s.home.decimal+" ("+s.home.fractional+")":"-")+" · "+(f.away_team||"away")+" "
+(s.away?s.away.decimal+" ("+s.away.fractional+")":"-");}}
function tick(){if(!fx.length)return;var f=fx[idx%fx.length];
var it=document.getElementById("vd-tick-item");var hv=document.getElementById("vd-hover");
if(it)it.innerHTML=fmt(f);
navScore(f);
if(hv)hv.innerHTML=hover(f)+(VD_COMPARE?'<br><br><a class="vd-btn" style="padding:6px 14px;font-size:12px;" href="'
+VD_COMPARE+encodeURIComponent(f.fixture)+'">Open this prediction card &rarr;</a>':"");
var tk=document.querySelector(".vd-ticker");
if(tk&&VD_COMPARE)tk.onclick=function(e){if(e.target.tagName!=="A")
location.href=VD_COMPARE+encodeURIComponent(f.fixture);};
idx++;}
tick();setInterval(tick,5000);
var tables=(window.VD&&VD.tables)||{};
function trow(team){for(var d in tables){var t=tables[d];
for(var j=0;j<t.length;j++){if(t[j].team===team)return {pos:j+1,div:d,r:t[j]};}}return null;}
/* bet builder: live running total, risk factor, Scoreline bonus */
document.querySelectorAll(".vd-slipform").forEach(function(form){
var slip=form.querySelector(".vd-slip");if(!slip)return;
var elCount=slip.querySelector(".vd-slip-count");
var elTotal=slip.querySelector(".vd-slip-total");
var elRisk=slip.querySelector(".vd-slip-risk");
var elBonus=slip.querySelector(".vd-slip-bonus");
var sl=parseFloat((form.querySelector("[name=vd_scoreline]")||{}).value||50);
/* points read like decimal odds: 263 stored -> 2.63 shown */
function pts(n){return (n/100).toFixed(2);}
function recalc(){
var on=[].slice.call(form.querySelectorAll(".vd-opt input:checked"));
var gross=0,combined=1,longest=0;
on.forEach(function(i){var o=i.closest(".vd-opt");
gross+=parseFloat(o.dataset.points)||0;
var pct=Math.min(Math.max(parseFloat(o.dataset.pct)||50,1.5),97);
longest=Math.max(longest,100/pct);
combined*=pct/100;});
var countRisk=Math.min(on.length/8,1);
var risk=on.length?Math.min(countRisk*0.45+(1-combined)*0.55,1):0;
/* mirrors vendicator_win_multiplier(): Scoreline + risk carried + price */
var bonus=1+(Math.min(Math.max(sl,0),100)/100)*0.4+risk*0.6;
if(longest>2)bonus+=Math.min((longest-2)/18,1)*0.25;
bonus=Math.min(bonus,2.25);
var payout=Math.round(gross*bonus);
/* projected downside: what a losing slip would cost at this risk */
var legs=on.length;
var penalty=legs>=3?Math.round(40*legs*(0.5+risk)):(risk>=0.75?Math.round(30*legs*risk):0);
var show=penalty>0&&risk>=0.75?-penalty:payout;
elCount.textContent=legs+(legs===1?" selection":" selections");
elTotal.textContent=(show>=0?"+":"-")+pts(Math.abs(show));
elTotal.classList.toggle("neg",show<0);
elRisk.textContent="risk "+Math.round(risk*100)+"%"+(penalty?" · "+pts(penalty)+" at stake":"");
elRisk.classList.toggle("hot",risk>=0.75);
if(elBonus)elBonus.textContent=legs?("bonus x"+bonus.toFixed(2)):"";
elTotal.title=legs?(pts(gross)+" base x"+bonus.toFixed(2)+" = "+pts(payout)
+" if every leg lands. The bonus grows with the Scoreline, the risk you are "
+"carrying and the price of your longest selection."):"";
}
form.querySelectorAll(".vd-opt input").forEach(function(i){
i.addEventListener("change",function(){
i.closest(".vd-opt").classList.toggle("on",i.checked);recalc();});});
recalc();});
var BADGE_ALIAS={"Ath Madrid":"Atletico Madrid","Ath Bilbao":"Athletic Bilbao",
"Man United":"Manchester United","Man City":"Manchester City",
"Sheffield Weds":"Sheffield Wednesday","Nott'm Forest":"Nottingham Forest",
"Vallecano":"Rayo Vallecano","Espanol":"Espanyol","Sociedad":"Real Sociedad",
"Betis":"Real Betis","Celta":"Celta Vigo","Paris SG":"Paris Saint-Germain",
"Bradford":"Bradford City","Alaves":"Deportivo Alaves","Milan":"AC Milan",
"Newcastle":"Newcastle United","Wolves":"Wolverhampton Wanderers",
"Leverkusen":"Bayer Leverkusen","Dortmund":"Borussia Dortmund"};
document.querySelectorAll("img[data-badge]").forEach(function(img){
var q=BADGE_ALIAS[img.dataset.badge]||img.dataset.badge;
fetch("https://www.thesportsdb.com/api/v1/json/3/searchteams.php?t="+encodeURIComponent(q))
.then(function(r){return r.json();}).then(function(d){
var u=d.teams&&d.teams[0]&&(d.teams[0].strBadge||d.teams[0].strTeamBadge);
if(u)img.src=u+"/preview";}).catch(function(){});});
/* League badges are looked up by ID, not by name.
   TheSportsDB's free tier caps every list endpoint at five rows, so
   searching for "Premier League" returns Albanian and Algerian leagues and
   nothing else. These IDs were each verified against lookupleague.php, so a
   badge is either right or absent - never the wrong competition. */
var LEAGUE_ID={"Premier League":4328,"Championship":4329,"League One":4396,
"League Two":4397,"La Liga":4335,"La Liga 2":4400,"Serie A":4332,
"Serie B":4394,"Bundesliga":4331,"Ligue 1":4334,"Champions League":4480,
"Europa League":4481,"FA Cup":4482,"EFL Cup":4570,"Copa del Rey":4483,
"Coppa Italia":4506,"Scottish Premiership":4330,"Eredivisie":4337,
"Primeira Liga":4344,"Pro League":4338,"Super Lig":4339,"Super League":4336,
"Serie A (Brazil)":4351,"Primera Division":4406,"MLS":4346,"J1 League":4633};
document.querySelectorAll("img[data-league]").forEach(function(img){
var id=LEAGUE_ID[img.dataset.league];if(!id)return;
fetch("https://www.thesportsdb.com/api/v1/json/3/lookupleague.php?id="+id)
.then(function(r){return r.json();}).then(function(d){
var l=(d.leagues||[])[0];
if(l&&l.strBadge)img.src=l.strBadge+"/preview";}).catch(function(){});});
document.querySelectorAll("img[data-player],.vd-shirt[data-number]").forEach(function(el){
var name=el.dataset.player||el.dataset.number;
fetch("https://www.thesportsdb.com/api/v1/json/3/searchplayers.php?p="+encodeURIComponent(name))
.then(function(r){return r.json();}).then(function(d){
var p=d.player&&d.player[0];if(!p)return;
if(el.tagName==="IMG"){var u=p.strCutout||p.strThumb||p.strRender;
if(u)el.src=u+"/preview";}
else if(p.strNumber){el.textContent=p.strNumber;el.classList.add("on");}
}).catch(function(){});});
/* kick-off countdowns: a card stays on the board until its match starts */
function countdowns(){var now=Date.now()/1000;
document.querySelectorAll(".vd-countdown").forEach(function(el){
var left=(+el.dataset.ko||0)-now;
if(left<=0){el.innerHTML='<b class="vd-live">&#9679; kicked off</b>';
var card=el.closest(".vd-card");if(card)card.classList.add("vd-started");return;}
var d=Math.floor(left/86400),h=Math.floor(left%86400/3600),
m=Math.floor(left%3600/60),s=Math.floor(left%60);
el.textContent="starts in "+(d?d+"d ":"")+(d||h?h+"h ":"")+m+"m "+s+"s";});}
countdowns();setInterval(countdowns,1000);
document.querySelectorAll(".tip2").forEach(function(el){var t=trow(el.dataset.tip);
el.innerHTML=t?("<b>"+el.dataset.tip+"</b><br>Position "+t.pos+" ("+t.div+") - "+t.r.pts
+" pts<br>"+t.r.w+"W "+t.r.d+"D "+t.r.l+"L, GF "+t.r.gf+" GA "+t.r.ga
+"<br>Click for the full team page"):("<b>"+el.dataset.tip+"</b><br>Click for the full team page");});
})();
JS;
    return '<script>window.VD = ' . wp_json_encode($data) . ';' . $js . '</script>';
}

function vendicator_toasts() {
    $uid = get_current_user_id();
    if (!$uid) { return ''; }
    $bets = json_decode((string) get_user_meta($uid, 'vendicator_bets', true), true);
    $out = '';
    foreach ((array) $bets as $i => $b) {
        if (empty($b['settled']) || !empty($b['ack'])) { continue; }
        $won = isset($b['pick'], $b['result']) && $b['pick'] === $b['result'];
        $url = wp_nonce_url(admin_url('admin-post.php?action=vendicator_ack&i=' . $i),
            'vendicator_ack', '_vdnonce');
        $out .= '<div class="vd-toast' . ($won ? '' : ' lost') . '">'
            . '<a class="x" href="' . esc_url($url) . '">&#10005;</a>'
            . ($won ? '<b>You won!</b> ' : '<b>Unlucky.</b> ')
            . esc_html($b['fixture']) . ' finished '
            . esc_html(isset($b['score']) ? $b['score'] : '')
            . ($won ? ' &mdash; ' . vendicator_pts($b['points'], true)
                . ' points' : '')
            . '</div>';
    }
    // settled bet-builder slips get the same popup, and it stays until the
    // member closes it - a result is never allowed to scroll past unseen
    $slips = json_decode((string) get_user_meta($uid, 'vendicator_slips', true), true);
    foreach ((array) $slips as $i => $s) {
        if (empty($s['settled']) || !empty($s['ack'])) { continue; }
        $won = (isset($s['outcome']) && $s['outcome'] === 'won');
        $void = (isset($s['outcome']) && $s['outcome'] === 'void');
        $url = wp_nonce_url(admin_url('admin-post.php?action=vendicator_ack&slip=1&i='
            . $i), 'vendicator_ack', '_vdnonce');
        $out .= '<div class="vd-toast' . ($won ? '' : ' lost') . '">'
            . '<a class="x" href="' . esc_url($url) . '">&#10005;</a>'
            . ($won ? '<b>Slip landed!</b> ' : ($void ? '<b>Slip voided.</b> '
                : '<b>Slip missed.</b> '))
            . esc_html($s['fixture'])
            . (isset($s['score']) && $s['score'] ? ' finished '
                . esc_html($s['score']) : '')
            . ' &mdash; ' . vendicator_pts(isset($s['points']) ? $s['points'] : 0, true)
            . (isset($s['note']) ? '<br><small class="vd-muted">'
                . esc_html($s['note']) . '</small>' : '')
            . '</div>';
    }
    return $out ? '<div class="vd-toasts">' . $out . '</div>' : '';
}

/* --------------------------------------------------------------- shortcodes */

function vendicator_page_url($slug) {
    $p = get_page_by_path($slug);
    return $p ? get_permalink($p) : home_url('/');
}

/**
 * One absolute kick-off instant per fixture.
 *
 * The engine now stamps `kickoff_ts` (UTC epoch) on every fixture, so every
 * clock, countdown and "has this started" check on the site agrees. The
 * string parse is a fallback for payloads pushed before that field existed.
 */
function vendicator_kickoff_ts($fx) {
    if (!empty($fx['kickoff_ts'])) { return (int) $fx['kickoff_ts']; }
    if (!empty($fx['kickoff'])
        && preg_match('#(\d+)/(\d+)/(\d+)\s+(\d+):(\d+)#', $fx['kickoff'], $m)) {
        return mktime((int) $m[4], (int) $m[5], 0,
            (int) $m[2], (int) $m[1], (int) $m[3]);
    }
    return null;
}

/** True once the match is under way - the card comes off the board then. */
function vendicator_has_started($fx) {
    $ts = vendicator_kickoff_ts($fx);
    return $ts !== null && $ts <= time();
}

/**
 * Prediction cards stay up until their match actually kicks off; at that
 * moment they are moved into the records rather than simply vanishing, so
 * the site keeps its own history of what was on the board and when.
 */
function vendicator_log_started($fixtures) {
    $log = (array) get_option('vendicator_started_log', array());
    $changed = false;
    foreach ($fixtures as $fx) {
        if (!vendicator_has_started($fx)) { continue; }
        $key = $fx['league'] . '|' . $fx['fixture'];
        if (isset($log[$key])) { continue; }
        $log[$key] = array(
            'fixture' => $fx['fixture'], 'league' => $fx['league'],
            'kickoff' => isset($fx['kickoff']) ? $fx['kickoff'] : '',
            'kickoff_ts' => vendicator_kickoff_ts($fx),
            'gameweek' => isset($fx['gameweek']) ? $fx['gameweek'] : null,
            'season' => isset($fx['season_label']) ? $fx['season_label'] : '',
            'probs' => isset($fx['final_calibrated']) ? $fx['final_calibrated'] : null,
            'scoreline' => isset($fx['scoreline']['headline'])
                ? $fx['scoreline']['headline'] : null,
            'logged_at' => gmdate('c'),
        );
        $changed = true;
    }
    if ($changed) {
        // keep the tail bounded; the full archive lives in the JSONL records
        if (count($log) > 2000) { $log = array_slice($log, -2000, null, true); }
        update_option('vendicator_started_log', $log, false);
    }
    return $log;
}

add_shortcode('vendicator_login', function () {
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        return vendicator_shell('', '<div class="vd-card">'
            . vendicator_logo()
            . '<p>Welcome back, <b>' . esc_html($u->display_name) . '</b>.</p>'
            . '<p><a class="vd-btn" href="' . esc_url(vendicator_page_url('dashboard')) . '">Open predictions</a> '
            . '<a class="vd-btn" href="' . esc_url(vendicator_page_url('account')) . '">My account</a> '
            . '<a class="vd-btn" href="' . esc_url(wp_logout_url(home_url('/'))) . '">Log out</a></p>'
            . '</div>');
    }
    $err = isset($_GET['vd_error']) ? sanitize_text_field(wp_unslash($_GET['vd_error'])) : '';
    $out = '<div class="vd-wrap"><div class="vd-inner">'
        . '<div class="vd-card" style="text-align:center;">'
        . vendicator_logo()
        . '<p class="vd-muted">Football score predictions, live win probability and a rewards game. Sign in to access the model.</p></div>'
        . ($err ? '<div class="vd-card" style="border-color:#FF6B6B;">' . esc_html($err) . '</div>' : '')
        . '<div class="vd-grid"><div class="vd-card"><h3>Log in</h3>'
        . wp_login_form(array('echo' => false,
            'redirect' => vendicator_page_url('dashboard')))
        . '</div><div class="vd-card"><h3>Sign up</h3>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_signup">'
        . wp_nonce_field('vendicator_signup', '_vdnonce', true, false)
        . '<label>Username</label><input type="text" name="vd_user" required>'
        . '<label>Email</label><input type="email" name="vd_email" required>'
        . '<label>Password</label><input type="password" name="vd_pass" required minlength="8">'
        . '<input type="submit" value="Create account">'
        . '<p class="vd-muted">Free tier: lower-league predictions + BTTS/1X2. Earn points to rank up.</p>'
        . '</form></div></div>' . vendicator_footer() . '</div></div>';
    return $out;
});

add_action('admin_post_nopriv_vendicator_signup', 'vendicator_do_signup');
add_action('admin_post_vendicator_signup', 'vendicator_do_signup');
function vendicator_do_signup() {
    check_admin_referer('vendicator_signup', '_vdnonce');
    $user = sanitize_user(wp_unslash($_POST['vd_user'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['vd_email'] ?? ''));
    $pass = (string) wp_unslash($_POST['vd_pass'] ?? '');
    $login_url = vendicator_page_url('login');
    if (!$user || !is_email($email) || strlen($pass) < 8) {
        wp_safe_redirect(add_query_arg('vd_error',
            rawurlencode('Please fill every field; password needs 8+ characters.'), $login_url));
        exit;
    }
    $uid = wp_create_user($user, $pass, $email);
    if (is_wp_error($uid)) {
        wp_safe_redirect(add_query_arg('vd_error',
            rawurlencode($uid->get_error_message()), $login_url));
        exit;
    }
    (new WP_User($uid))->set_role('subscriber');
    vendicator_init_user($uid);
    wp_set_current_user($uid);
    wp_set_auth_cookie($uid);
    wp_safe_redirect(vendicator_page_url('dashboard'));
    exit;
}

add_filter('wp_authenticate_user', function ($user) {
    if ($user instanceof WP_User
        && get_user_meta($user->ID, 'vendicator_banned', true)) {
        return new WP_Error('vendicator_banned',
            'This account has been suspended.');
    }
    return $user;
});

function vendicator_tier_markets($tier) {
    $m = array('free' => array('1x2', 'btts'),
        'bronze' => array('1x2', 'btts', 'alt_goals', 'halves',
            'double_chance', 'over_under'),
        'silver' => array('1x2', 'btts', 'alt_goals', 'halves', 'corners',
            'double_chance', 'over_under', 'exact_score', 'market_view'),
        'gold' => array_keys(vendicator_sections()));
    return isset($m[$tier]) ? $m[$tier] : $m['free'];
}

add_shortcode('vendicator_dashboard', function () {
    if (!is_user_logged_in()) {
        return '<div class="vd-wrap"><div class="vd-inner"><div class="vd-card">'
            . '<p>Please <a class="vd-btn" href="' . esc_url(vendicator_page_url('login'))
            . '">log in</a> to view predictions.</p></div></div></div>';
    }
    $p = get_option('vendicator_predictions');
    $uid = get_current_user_id();
    $tier = vendicator_user_tier($uid);
    $allowed = vendicator_tier_markets($tier);
    $sel = get_option('vendicator_model_selectors', array());
    $out = '<div class="vd-wrap"><div class="vd-inner">'
        . vendicator_nav('dashboard')
        . '<div class="vd-card"><p class="vd-muted" style="margin:0;">Your tier: '
        . '<span class="vd-pill">' . esc_html(strtoupper($tier))
        . '</span> unlocks: ' . esc_html(implode(', ', $allowed)) . '</p></div>';
    if (!$p) {
        return $out . '<div class="vd-card">No predictions published yet - '
            . 'the engine pushes the next matchday payload automatically.</div></div></div>';
    }
    $out .= '<div class="vd-card"><div id="vd-clock2" class="vd-clock">--:--:--</div>'
        . '<p class="vd-muted" style="margin:2px 0 0;">Live clock &middot; predictions updated '
        . esc_html(get_option('vendicator_predictions_updated', '')) . '</p>'
        . '<div class="vd-ticker"><span id="vd-tick-item" class="vd-muted">loading fixtures&hellip;</span>'
        . '<div id="vd-hover" class="vd-hover"></div></div>'
        . '<p class="vd-muted" style="margin:8px 0 0;font-size:12px;">'
        . 'Rotating every 5s &middot; hover for the model\'s reasoning</p></div>';

    $list = isset($p['fixtures']) && is_array($p['fixtures'])
        ? $p['fixtures'] : array($p);
    $picked = array();
    foreach ((array) json_decode((string) get_user_meta($uid, 'vendicator_bets', true), true) as $b) {
        if (!empty($b['fixture'])) { $picked[$b['fixture']] = true; }
    }
    // a card stays on the board right up to kick-off, then it is logged
    vendicator_log_started($list);
    $open = array();
    $counts = array();
    $weeks = array();
    foreach ($list as $fx) {
        if (isset($picked[$fx['fixture']])) { continue; }
        if (vendicator_has_started($fx)) { continue; }
        $lg = $fx['league'];
        $counts[$lg] = (isset($counts[$lg]) ? $counts[$lg] : 0) + 1;
        if (!empty($fx['gameweek'])) { $weeks[(int) $fx['gameweek']] = true; }
        $open[] = $fx;
    }
    ksort($weeks);

    $cat = vendicator_league_catalogue();
    $flt = isset($_GET['lg']) ? sanitize_text_field(wp_unslash($_GET['lg'])) : '';
    $gw = isset($_GET['gw']) ? (int) $_GET['gw'] : 0;
    $sort = isset($_GET['sort']) ? sanitize_key($_GET['sort']) : 'default';
    $page = max(1, isset($_GET['pg']) ? (int) $_GET['pg'] : 1);
    // Four cards a page. Every player in both squads now gets a row with a
    // full threshold ladder, so eight cards ran to ~68,000 DOM nodes - fine
    // on a desktop, punishing on a phone. Paging and the CardGrid still
    // reach every fixture.
    $per = 4;

    $filtered = array_values(array_filter($open,
        function ($f) use ($flt, $gw) {
            if ($flt && $f['league'] !== $flt) { return false; }
            if ($gw && (int) (isset($f['gameweek']) ? $f['gameweek'] : 0) !== $gw) {
                return false;
            }
            return true;
        }));
    $filtered = vendicator_sort_fixtures($filtered, $sort);
    $total = count($filtered);
    $pages = max(1, (int) ceil($total / $per));
    $page = min($page, $pages);
    $slice = array_slice($filtered, ($page - 1) * $per, $per);

    $base = vendicator_page_url('dashboard');
    $keep = array('lg' => $flt, 'gw' => $gw ? $gw : null, 'sort' => $sort);
    $out .= '<div class="vd-card"><form method="get" class="vd-filters">'
        . '<div><label>Competition</label><select name="lg" onchange="this.form.submit()">'
        . '<option value="">All competitions (' . (int) count($open) . ' open)</option>';
    foreach ($counts as $lg => $n) {
        $out .= '<option value="' . esc_attr($lg) . '" ' . selected($flt, $lg, false) . '>'
            . esc_html(isset($cat[$lg]) ? $cat[$lg][0] : $lg) . ' (' . (int) $n . ')</option>';
    }
    $out .= '</select></div>'
        . '<div><label>Filter / order</label><select name="sort" onchange="this.form.submit()">';
    foreach (vendicator_sort_modes() as $key => $label) {
        $out .= '<option value="' . esc_attr($key) . '" '
            . selected($sort, $key, false) . '>' . esc_html($label) . '</option>';
    }
    $out .= '</select></div>'
        . '<div><label>Gameweek</label><select name="gw" onchange="this.form.submit()">'
        . '<option value="0">Every gameweek</option>';
    foreach (array_keys($weeks) as $w) {
        $out .= '<option value="' . (int) $w . '" ' . selected($gw, $w, false)
            . '>Gameweek ' . (int) $w . '</option>';
    }
    $out .= '</select></div><div style="flex:0;"><input type="submit" value="Apply"></div>'
        . '</form><p class="vd-muted" style="margin:10px 0 0;font-size:12px;">Showing '
        . (int) count($slice) . ' of ' . (int) $total . ' open fixtures &middot; page '
        . (int) $page . ' of ' . (int) $pages
        . ($sort === 'default' && $page === 1 && $total
            ? ' &middot; the &#9733; card is the best value on the board' : '')
        . '</p></div>';

    foreach ($slice as $i => $fx) {
        $star = ($sort === 'default' && $page === 1 && $i === 0);
        $out .= vendicator_render_fixture($fx, $allowed, $sel, $star);
    }
    if (!$total) {
        $out .= '<div class="vd-card"><h3>All caught up</h3><p class="vd-muted">'
            . 'Nothing open matches this filter. Settled and in-play cards are logged in '
            . '<a class="vd-btn" href="' . esc_url(add_query_arg('tab', 'betting', vendicator_page_url('account')))
            . '">Betting History</a></p></div>';
    }
    $out .= '<div class="vd-card" style="text-align:center;">';
    if ($pages > 1 && $page > 1) {
        $out .= '<a class="vd-btn" href="' . esc_url(add_query_arg(
            array_merge($keep, array('pg' => $page - 1)), $base))
            . '">&larr; Previous</a> ';
    }
    if ($pages > 1 && $page < $pages) {
        $out .= '<a class="vd-btn" href="' . esc_url(add_query_arg(
            array_merge($keep, array('pg' => $page + 1)), $base))
            . '">Next page &rarr;</a> ';
    }
    $out .= '<a class="vd-btn" href="' . esc_url(vendicator_page_url('cardgrid'))
        . '"><span class="vd-mascot">&#127918;</span> More fixtures &mdash; open the '
        . 'CardGrid &rarr;</a></div>';
    $out .= vendicator_footer() . '</div></div>' . vendicator_toasts()
        . vendicator_dashboard_js($p);
    return $out;
});

/** The orders a member can put the prediction board into. */
function vendicator_sort_modes() {
    return array(
        'default' => 'Default - best value first, then competition and date',
        'date_asc' => 'Date - soonest first',
        'date_desc' => 'Date - furthest away first',
        'scoreline_desc' => 'Vendicator Scoreline - highest first',
        'scoreline_asc' => 'Vendicator Scoreline - lowest first',
        'alpha' => 'Alphabetical by fixture',
        'league' => 'Grouped by competition',
    );
}

/**
 * Apply a sort mode to the open board.
 *
 * "Value" in the default mode is the reward difficulty multiplier lifted by
 * the fixture's Scoreline: a card the model finds genuinely hard to call,
 * on teams it has a good record with, is the one worth leading with.
 */
function vendicator_sort_fixtures($fixtures, $mode) {
    $value = function ($f) {
        $d = isset($f['reward_difficulty_multiplier'])
            ? (float) $f['reward_difficulty_multiplier'] : 1.0;
        $s = isset($f['scoreline']['headline'])
            ? (float) $f['scoreline']['headline'] : 50.0;
        return $d * (0.6 + $s / 250.0);
    };
    $sl = function ($f) {
        return isset($f['scoreline']['headline'])
            ? (float) $f['scoreline']['headline'] : 0.0;
    };
    switch ($mode) {
        case 'date_asc':
            usort($fixtures, function ($a, $b) {
                return (vendicator_kickoff_ts($a) ?: PHP_INT_MAX)
                    <=> (vendicator_kickoff_ts($b) ?: PHP_INT_MAX); });
            break;
        case 'date_desc':
            usort($fixtures, function ($a, $b) {
                return (vendicator_kickoff_ts($b) ?: 0)
                    <=> (vendicator_kickoff_ts($a) ?: 0); });
            break;
        case 'scoreline_desc':
            usort($fixtures, function ($a, $b) use ($sl) {
                return $sl($b) <=> $sl($a); });
            break;
        case 'scoreline_asc':
            usort($fixtures, function ($a, $b) use ($sl) {
                return $sl($a) <=> $sl($b); });
            break;
        case 'alpha':
            usort($fixtures, function ($a, $b) {
                return strcasecmp($a['fixture'], $b['fixture']); });
            break;
        case 'league':
            usort($fixtures, function ($a, $b) {
                return array($a['league'], vendicator_kickoff_ts($a) ?: 0)
                    <=> array($b['league'], vendicator_kickoff_ts($b) ?: 0); });
            break;
        default:
            // best value on top, then the natural competition/date order
            usort($fixtures, function ($a, $b) use ($value) {
                $d = $value($b) <=> $value($a);
                if ($d !== 0) { return $d; }
                return array($a['league'], vendicator_kickoff_ts($a) ?: 0)
                    <=> array($b['league'], vendicator_kickoff_ts($b) ?: 0); });
            break;
    }
    return $fixtures;
}

function vendicator_dot_row($states) {
    $out = '<span class="vd-dots">';
    foreach ((array) $states as $s) {
        $out .= '<i class="vd-dot ' . esc_attr($s) . '" title="' . esc_attr($s) . '"></i>';
    }
    return $out . '</span>';
}

/** Name / position / team block used wherever a player is listed. */
function vendicator_player_identity($pl, $compact = false) {
    $pos = !empty($pl['position_short']) ? $pl['position_short']
        : strtoupper(substr((string) $pl['position'], 0, 3));
    $long = !empty($pl['position_long']) ? $pl['position_long'] : $pl['position'];
    return '<a class="vd-playerlink" href="' . esc_url(add_query_arg(
            array('vd_player' => rawurlencode((string) $pl['id']),
                  'vd_pname' => rawurlencode($pl['name'])),
            vendicator_page_url('player'))) . '">'
        . '<img class="vd-playerpic" data-player="' . esc_attr($pl['name']) . '" alt="">'
        . '<span class="vd-pident"><span class="vd-pname">'
        . esc_html($pl['name'])
        . ($pos ? ' <i class="vd-pos">' . esc_html($pos) . '</i>' : '')
        . '</span><small class="vd-pteam">' . esc_html($pl['team']) . '</small>'
        . '</span>'
        . '<span class="tip3"><b>' . esc_html($pl['name']) . '</b><br>'
        . esc_html($long) . '<br>' . esc_html($pl['team'])
        . '<br>' . (int) $pl['goals'] . ' goals, ' . (int) $pl['assists']
        . ' assists in ' . (int) $pl['games'] . ' games'
        . '<br>xG ' . esc_html($pl['xG']) . ' &middot; xA ' . esc_html($pl['xA'])
        . '<br>Value rating <b>' . esc_html($pl['rating']) . '</b>'
        . ($compact ? '' : '<br>Click for the full profile') . '</span></a>';
}

/**
 * Player markets, laid out as one ROW per player inside each category and
 * one COLUMN per threshold, so a member reads across a player's ladder
 * (1+, 2+, 3+ goals) instead of hunting through parallel lists.
 *
 * Every player the engine loaded is listed - the categories are collapsible
 * and each list scrolls inside its own box, which keeps the card a sane
 * height without hiding anyone. No nested <form>: each chip is a leg of
 * the card's single slip.
 */
function vendicator_player_market($p) {
    $players = $p['players'];
    $cats = array(
        array('score', 'Goals', 'goals', 'goals'),
        array('assist', 'Assists', 'assists', 'assists'),
        array('key_passes', 'Key passes', 'key_passes', null),
        array('tackles', 'Tackles (est.)', 'tackles', null),
        array('yellow_card', 'Cards', 'yellow_cards', null),
        array('red_card', 'Sent off', 'red_cards', null),
    );
    $out = '<div class="vd-card vd-wide"><h3>Player Markets</h3>'
        . '<p class="vd-muted" style="margin:-4px 0 12px;font-size:12.5px;">'
        . 'Every player in both squads, one row each. Pick any threshold to '
        . 'add it to the slip.</p>';
    $first = true;
    foreach ($cats as $c) {
        list($market, $label, $stat, $tally) = $c;
        $sorted = $players;
        usort($sorted, function ($a, $b) use ($stat) {
            return ((float) (isset($b[$stat]) ? $b[$stat] : 0))
                <=> ((float) (isset($a[$stat]) ? $a[$stat] : 0));
        });
        $rows = '';
        foreach ($sorted as $pl) {
            $lines = isset($pl['markets'][$market]) ? $pl['markets'][$market]
                : array();
            if (!$lines) { continue; }
            $weight = isset($pl['weight']) ? (float) $pl['weight'] : 1.0;
            $chips = '';
            foreach ($lines as $ln) {
                $chips .= vendicator_chip_option(
                    'player', $pl['id'] . '_' . $market . '_' . $ln['line'],
                    $pl['name'] . ' - ' . $ln['label'], $ln['pct'],
                    vendicator_leg_points($ln['pct'], $weight));
            }
            $dots = '';
            if ($tally && !empty($pl['last5'][$tally])) {
                $dots = vendicator_dot_row($pl['last5'][$tally]);
            }
            $rows .= '<div class="vd-prow">'
                . '<span class="vd-pwho">' . vendicator_player_identity($pl)
                . '<small class="vd-pstat">' . esc_html(isset($pl[$stat]) ? $pl[$stat] : 0)
                . ' this season' . ($dots ? ' ' . $dots : '') . '</small></span>'
                . '<span class="vd-plines">' . $chips . '</span></div>';
        }
        if (!$rows) { continue; }
        $out .= '<details class="vd-details vd-pcat"' . ($first ? ' open' : '')
            . '><summary>' . esc_html($label) . '</summary>'
            . '<div class="vd-prows vd-scroll tall">' . $rows . '</div></details>';
        $first = false;
    }
    return $out . '<p class="vd-muted" style="font-size:12px;">'
        . '<i class="vd-dot goal"></i> scored &nbsp; <i class="vd-dot assist"></i> assisted '
        . '&nbsp; <i class="vd-dot blank"></i> neither &nbsp; <i class="vd-dot dnp"></i> did not play. '
        . 'Points scale inversely with a player&rsquo;s value rating, so backing a '
        . 'lower-valued player pays more. Selecting many players raises the slip&rsquo;s '
        . 'risk factor, which is shown live on the slip bar. Tackles are estimated '
        . 'from position and minutes &mdash; the open feed carries no tackle counts.</p></div>';
}

function vendicator_render_fixture($p, $allowed, $sel, $star = false) {
    $f = $p['final_calibrated'];
    $dc = $p['markets_dixon_coles'];
    $out = '<div class="vd-card' . ($star ? ' vd-star' : '') . '">'
        . ($star ? '<div class="vd-starflag">&#9733; Best value on the board</div>' : '')
        . '<h2>' . vendicator_fixture_heading($p) . '</h2>'
        . '<p class="vd-muted">' . esc_html($p['league'])
        . (empty($p['kickoff']) ? '' : ' - kickoff ' . esc_html($p['kickoff']))
        . (($ko = vendicator_kickoff_ts($p))
            ? ' <span class="vd-countdown" data-ko="' . (int) $ko . '"></span>' : '')
        . ' - expected goals '
        . esc_html($p['expected_goals']['home'] . ' - ' . $p['expected_goals']['away']) . '</p>'
        . '<div class="vd-bar">'
        . '<div class="vd-h" style="flex:' . floatval($f['home']) . '">' . floatval($f['home']) . '%</div>'
        . '<div class="vd-d" style="flex:' . floatval($f['draw']) . '">' . floatval($f['draw']) . '%</div>'
        . '<div class="vd-a" style="flex:' . floatval($f['away']) . '">' . floatval($f['away']) . '%</div>'
        . '</div><p class="vd-muted">Calibrated ensemble - 90% band on home win: '
        . esc_html(implode('-', (array) $p['uncertainty_band_home_pct'])) . '%'
        . ' - difficulty x' . esc_html($p['reward_difficulty_multiplier']) . '</p>'
        . vendicator_scoreline_row($p)
        . '<details class="vd-details"><summary>Build your slip &mdash; markets, players &amp; odds</summary>'
        . '<form class="vd-slipform" method="post" action="'
        . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_slip">'
        . wp_nonce_field('vendicator_slip', '_vdnonce', true, false)
        . '<div class="vd-grid">';
    $show = vendicator_league_sections($p['league']);
    $vis = function ($slug) use ($show, $allowed) {
        return in_array($slug, $show, true) && in_array($slug, $allowed, true);
    };

    if ($vis('1x2')) { $out .= vendicator_outcome_options($p); }
    if ($vis('alt_goals')) { $out .= vendicator_totals_options($p); }
    if ($vis('btts')) { $out .= vendicator_btts_options($p); }
    if ($vis('halves')) { $out .= vendicator_halves_options($p); }
    if ($vis('exact_score')) { $out .= vendicator_score_options($p); }
    if ($vis('corners')) { $out .= vendicator_corners_options($p); }
    if ($vis('discipline')) { $out .= vendicator_discipline_options($p); }
    if ($vis('best_odds')) { $out .= vendicator_odds_options($p); }
    if ($vis('players') && !empty($p['players'])) {
        $out .= vendicator_player_market($p);
    }
    return $out . '</div>' . vendicator_slip_bar($p) . '</details></div>';
}

/**
 * Vendicator Scoreline strip for a fixture card.
 *
 * The rating is printed the way a price is - one digit before the point -
 * so it reads alongside the decimal odds beside it instead of competing
 * with them. The 0-100 composite it comes from is kept in the tooltip.
 */
function vendicator_scoreline_rating($sl, $fallback) {
    if (isset($sl['rating'])) { return number_format((float) $sl['rating'], 2); }
    return number_format(max(min((float) $fallback, 100), 10) / 10.0, 2);
}

function vendicator_scoreline_row($p) {
    if (empty($p['scoreline'])) { return ''; }
    $s = $p['scoreline'];
    $row = function ($team, $sl) {
        return '<span class="vd-sl-badge" title="' . esc_attr($sl['note'])
            . ' (composite ' . esc_attr($sl['total']) . '/100)">'
            . esc_html($team) . ' <b>'
            . esc_html(vendicator_scoreline_rating($sl, $sl['total'])) . '</b> '
            . '<small>' . esc_html($sl['decimal']) . ' / '
            . esc_html($sl['fractional']) . '</small></span>';
    };
    $gw = !empty($p['gameweek']) ? ' &middot; GW' . (int) $p['gameweek'] : '';
    // the reward value sitting next to the Scoreline: what this card's
    // headline selection actually pays, in the same decimal-odds shape
    $reward = '';
    if (!empty($p['final_calibrated'])) {
        $f = $p['final_calibrated'];
        $best = max((float) $f['home'], (float) $f['draw'], (float) $f['away']);
        $side = $best == $f['home'] ? 'home win'
            : ($best == $f['away'] ? 'away win' : 'draw');
        $pts = vendicator_leg_points($best,
            isset($p['reward_difficulty_multiplier'])
                ? (float) $p['reward_difficulty_multiplier'] : 1.0);
        $reward = '<span class="vd-reward" title="Reward points for the model'
            . '&rsquo;s headline selection (' . esc_attr($side) . ' at '
            . esc_attr($best) . '%), scaled by this fixture&rsquo;s difficulty. '
            . 'Points total towards your rank.">REWARD <b>'
            . esc_html(vendicator_pts($pts)) . '</b></span>';
    }
    return '<div class="vd-slrow">'
        . '<span class="vd-slhead">Vendicator Scoreline <b>'
        . esc_html(vendicator_scoreline_rating($s, $s['headline'])) . '</b></span>'
        . $reward
        . $row(isset($p['home_team']) ? $p['home_team'] : 'Home', $s['home'])
        . $row(isset($p['away_team']) ? $p['away_team'] : 'Away', $s['away'])
        . '<span class="vd-muted" style="font-size:11.5px;">'
        . esc_html(isset($p['season_label']) ? $p['season_label'] : '') . $gw
        . '</span></div>';
}

/** Running-total bar + submit for one card's slip. */
function vendicator_slip_bar($p) {
    $sl = isset($p['scoreline']) ? $p['scoreline'] : null;
    return '<div class="vd-slip">'
        . '<div class="vd-slip-main"><span class="vd-slip-count">0 selections</span>'
        . '<span class="vd-slip-total">+0</span></div>'
        . '<div class="vd-slip-meta"><span class="vd-slip-risk">risk 0%</span>'
        . '<span class="vd-slip-bonus"></span>'
        . ($sl ? '<span class="vd-slip-sl">Scoreline '
            . esc_html(vendicator_scoreline_rating($sl, $sl['headline']))
            . '</span>' : '')
        . '</div>'
        . '<input type="hidden" name="vd_fixture" value="' . esc_attr($p['fixture']) . '">'
        . '<input type="hidden" name="vd_league" value="' . esc_attr($p['league']) . '">'
        . '<input type="hidden" name="vd_scoreline" value="'
        . esc_attr($sl ? $sl['headline'] : 50) . '">'
        . '<button type="submit" class="vd-btn vd-slip-go">Place slip</button>'
        . '</div></form>';
}

function vendicator_render_fixture_legacy($p, $allowed, $sel) {

    $alt = array();
    foreach (array('0.5', '1.5', '2.5', '3.5', '4.5') as $line) {
        if (isset($dc['totals']['over_' . $line])) {
            $alt['Over ' . $line] = $dc['totals']['over_' . $line];
            $alt['Under ' . $line] = $dc['totals']['under_' . $line];
        }
    }
    $cards = array(
        '1x2' => array('1X2', array('Home' => $dc['1x2']['home'], 'Draw' => $dc['1x2']['draw'], 'Away' => $dc['1x2']['away'])),
        'alt_goals' => array('Alternative Total Goals', $alt),
    );
    foreach ($cards as $slug => $card) {
        if (!$vis($slug)) { continue; }
        $model = isset($sel[$slug]) ? $sel[$slug] : 'ensemble';
        $out .= '<div class="vd-card"><h3>' . esc_html($card[0])
            . ' <span class="vd-muted">(' . esc_html($model) . ')</span></h3><table class="vd-table">';
        foreach ($card[1] as $k => $v) {
            $out .= '<tr><td>' . esc_html($k) . '</td><td>' . floatval($v) . '%</td></tr>';
        }
        $out .= '</table></div>';
    }
    if ($vis('btts') && !empty($p['team_to_score'])) {
        $t = $p['team_to_score'];
        $home = isset($p['home_team']) ? $p['home_team'] : '';
        $away = isset($p['away_team']) ? $p['away_team'] : '';
        $out .= '<div class="vd-card"><h3>Both Teams To Score (BTTS)</h3>'
            . '<p style="font-size:26px;font-weight:800;color:#C6FF4D;margin:0 0 2px;">'
            . floatval($dc['btts']['yes']) . '% <span class="vd-muted" style="font-size:14px;">yes</span></p>'
            . '<table class="vd-table">'
            . '<tr><td>BTTS &mdash; yes</td><td>' . floatval($dc['btts']['yes']) . '%</td></tr>'
            . '<tr><td>BTTS &mdash; no</td><td>' . floatval($dc['btts']['no']) . '%</td></tr>'
            . '<tr><td>' . esc_html($home) . ' to score</td><td>' . floatval($t['home_pct'])
            . '% @ ' . esc_html($t['fair_odds']['home']) . '</td></tr>'
            . '<tr><td>' . esc_html($away) . ' to score</td><td>'
            . floatval($t['away_pct']) . '% @ ' . esc_html($t['fair_odds']['away']) . '</td></tr>'
            . '</table><p class="vd-muted" style="font-size:12px;">Likelier scorer: <b>'
            . esc_html($t['best_team']) . '</b>. Fair odds from the model scoreline grid.</p></div>';
    }
    if ($vis('players') && !empty($p['players'])) {
        $out .= vendicator_player_market($p);
    }
    if ($vis('discipline') && !empty($p['discipline'])) {
        $out .= '<div class="vd-card"><h3>Cards, Fouls &amp; Corners</h3>';
        foreach ($p['discipline'] as $m) {
            $out .= '<p style="margin:10px 0 4px;color:#C6FF4D;font-weight:700;">'
                . esc_html($m['label']) . ' <span class="vd-muted" style="font-weight:400;">'
                . 'expected ' . esc_html($m['expected']) . '</span></p><table class="vd-table">';
            foreach ($m['lines'] as $ln) {
                $out .= '<tr><td>' . esc_html($ln['label']) . '</td><td>'
                    . floatval($ln['pct']) . '%</td></tr>';
            }
            $out .= '</table>';
        }
        $out .= '<p class="vd-muted" style="font-size:12px;">Lines are chosen from each '
            . 'side\'s recent rates &mdash; only thresholds that are actually live for '
            . 'this fixture are offered.</p></div>';
    }
    if ($vis('best_odds') && !empty($p['odds_board'])) {
        $labels = array('home' => 'Home win', 'draw' => 'Draw', 'away' => 'Away win');
        $out .= '<div class="vd-card"><h3>Best Odds &mdash; top 3 books</h3><table class="vd-table">';
        foreach ($p['odds_board'] as $market => $prices) {
            $out .= '<tr><td colspan="2" style="color:#C6FF4D;font-weight:700;padding-top:9px;">'
                . esc_html(isset($labels[$market]) ? $labels[$market] : $market) . '</td></tr>';
            foreach (array_slice((array) $prices, 0, 3) as $pr) {
                $out .= '<tr><td>' . esc_html($pr['book']) . '</td><td>'
                    . esc_html($pr['odds']) . '</td></tr>';
            }
        }
        $out .= '</table><p class="vd-muted" style="font-size:12px;">Open odds data, '
            . 'refreshed every run. Informational only &mdash; not betting advice.</p></div>';
    }
    if ($vis('exact_score')) {
        $out .= '<div class="vd-card"><h3>Exact Score - top 10</h3><table class="vd-table">';
        foreach ($dc['exact_score_top10'] as $pair) {
            $out .= '<tr><td>' . esc_html($pair[0]) . '</td><td>' . floatval($pair[1]) . '%</td></tr>';
        }
        $out .= '</table></div>';
    }
    $out .= '<div class="vd-card"><h3>Your prediction (+points)</h3>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_bet">'
        . wp_nonce_field('vendicator_bet', '_vdnonce', true, false)
        . '<input type="hidden" name="vd_fixture" value="' . esc_attr($p['fixture']) . '">'
        . '<div class="vd-pickrow">'
        . '<label><input type="radio" name="vd_pick" value="H" required> Home win</label>'
        . '<label><input type="radio" name="vd_pick" value="D"> Draw</label>'
        . '<label><input type="radio" name="vd_pick" value="A"> Away win</label></div><br>'
        . '<input type="submit" value="Lock it in">'
        . '<p class="vd-muted">Correct pick earns 100 x difficulty points; streaks pay bonuses (3, 5, 10 in a row).</p>'
        . '</form></div>';
    return $out . '</div></details></div>';
}

add_action('admin_post_vendicator_bet', function () {
    check_admin_referer('vendicator_bet', '_vdnonce');
    $uid = get_current_user_id();
    if (!$uid) { wp_safe_redirect(vendicator_page_url('login')); exit; }
    $bets = json_decode((string) get_user_meta($uid, 'vendicator_bets', true), true);
    if (!is_array($bets)) { $bets = array(); }
    $fixture = sanitize_text_field(wp_unslash($_POST['vd_fixture'] ?? ''));
    foreach ($bets as $b) {  // one pick per fixture, ever
        if (isset($b['fixture']) && $b['fixture'] === $fixture) {
            wp_safe_redirect(vendicator_page_url('account') . '?tab=betting');
            exit;
        }
    }
    $bets[] = array('ts' => gmdate('c'), 'fixture' => $fixture,
        'pick' => sanitize_text_field(wp_unslash($_POST['vd_pick'] ?? '')),
        'settled' => false, 'points' => null);
    update_user_meta($uid, 'vendicator_bets', wp_json_encode($bets));
    wp_safe_redirect(vendicator_page_url('account') . '?tab=betting');
    exit;
});

add_action('admin_post_vendicator_slip', function () {
    check_admin_referer('vendicator_slip', '_vdnonce');
    $uid = get_current_user_id();
    if (!$uid) { wp_safe_redirect(vendicator_page_url('login')); exit; }
    $fixture = sanitize_text_field(wp_unslash($_POST['vd_fixture'] ?? ''));
    $league = sanitize_text_field(wp_unslash($_POST['vd_league'] ?? ''));
    $scoreline = (float) ($_POST['vd_scoreline'] ?? 50);
    $legs = array();
    foreach ((array) wp_unslash($_POST['vd_sel'] ?? array()) as $raw) {
        $parts = explode('|', sanitize_text_field($raw));
        if (count($parts) !== 5) { continue; }
        $legs[] = array('group' => $parts[0], 'value' => $parts[1],
            'label' => $parts[2], 'pct' => (float) $parts[3],
            'points' => (int) $parts[4]);
        vendicator_log_section_pick($league, $parts[0]);
    }
    if ($legs) {
        $slips = json_decode((string) get_user_meta($uid, 'vendicator_slips', true), true);
        if (!is_array($slips)) { $slips = array(); }
        foreach ($slips as $s) {   // one slip per fixture
            if (isset($s['fixture']) && $s['fixture'] === $fixture
                && empty($s['settled'])) {
                wp_safe_redirect(vendicator_page_url('account') . '?tab=betting');
                exit;
            }
        }
        $slips[] = array('ts' => gmdate('c'), 'fixture' => $fixture,
            'league' => $league, 'scoreline' => $scoreline, 'legs' => $legs,
            'risk' => vendicator_risk_factor($legs),
            'settled' => false, 'points' => null, 'outcome' => null);
        update_user_meta($uid, 'vendicator_slips', wp_json_encode($slips));
    }
    wp_safe_redirect(vendicator_page_url('account') . '?tab=betting');
    exit;
});

add_action('admin_post_vendicator_player_bet', function () {
    check_admin_referer('vendicator_player_bet', '_vdnonce');
    $uid = get_current_user_id();
    if (!$uid) { wp_safe_redirect(vendicator_page_url('login')); exit; }
    $raw = sanitize_text_field(wp_unslash($_POST['vd_player_pick'] ?? ''));
    $fixture = sanitize_text_field(wp_unslash($_POST['vd_fixture'] ?? ''));
    $league = sanitize_text_field(wp_unslash($_POST['vd_league'] ?? ''));
    $parts = explode('|', $raw);
    if (count($parts) === 3) {
        $bets = json_decode((string) get_user_meta($uid, 'vendicator_bets', true), true);
        if (!is_array($bets)) { $bets = array(); }
        $key = $fixture . '#player:' . $parts[0] . ':' . $parts[1];
        $dupe = false;
        foreach ($bets as $b) {
            if (isset($b['key']) && $b['key'] === $key) { $dupe = true; break; }
        }
        if (!$dupe) {
            $bets[] = array('ts' => gmdate('c'), 'fixture' => $fixture,
                'key' => $key, 'type' => 'player', 'player' => $parts[2],
                'market' => $parts[1], 'pick' => $parts[2] . ' to '
                    . str_replace('_', ' ', $parts[1]),
                'settled' => false, 'points' => null);
            update_user_meta($uid, 'vendicator_bets', wp_json_encode($bets));
            vendicator_log_section_pick($league, 'players');
        }
    }
    wp_safe_redirect(vendicator_page_url('account') . '?tab=betting');
    exit;
});

/**
 * Head-to-head between any two clubs, written from the same season rates
 * that feed the prediction engine. Nothing here is hand-authored: each
 * sentence is generated from the live table rows and the pair's Vendicator
 * Scorelines, so it stays honest as the season moves.
 */
function vendicator_team_compare($a, $b, $lg, $p) {
    $tables = isset($p['tables']) ? (array) $p['tables'] : array();
    $rows = array();
    $pos = array();
    foreach ($tables as $code => $tbl) {
        if ($lg && $code !== $lg) { continue; }
        foreach ($tbl as $i => $r) {
            if ($r['team'] === $a || $r['team'] === $b) {
                $rows[$r['team']] = $r;
                $pos[$r['team']] = $i + 1;
            }
        }
    }
    if (count($rows) < 2) {
        return '<div class="vd-card"><h3>Comparison unavailable</h3>'
            . '<p class="vd-muted">Both clubs need current-season rows before a '
            . 'comparison can be written. Pick two sides from the same table.'
            . '</p></div>';
    }
    $scores = array();
    foreach ((array) (isset($p['fixtures']) ? $p['fixtures'] : array()) as $fx) {
        foreach (array('home', 'away') as $side) {
            $t = isset($fx[$side . '_team']) ? $fx[$side . '_team'] : null;
            if (($t === $a || $t === $b) && !empty($fx['scoreline'][$side])) {
                $scores[$t] = $fx['scoreline'][$side];
            }
        }
    }
    $stat = function ($r) {
        $played = max((int) $r['p'], 1);
        return array(
            'ppg' => round((int) $r['pts'] / $played, 2),
            'gf90' => round((int) $r['gf'] / $played, 2),
            'ga90' => round((int) $r['ga'] / $played, 2),
            'gd' => (int) $r['gf'] - (int) $r['ga'],
        );
    };
    $sa = $stat($rows[$a]);
    $sb = $stat($rows[$b]);
    $metrics = array(
        array('League position', $pos[$a], $pos[$b], false),
        array('Points per game', $sa['ppg'], $sb['ppg'], true),
        array('Goals scored per game', $sa['gf90'], $sb['gf90'], true),
        array('Goals conceded per game', $sa['ga90'], $sb['ga90'], false),
        array('Goal difference', $sa['gd'], $sb['gd'], true),
        array('Vendicator Scoreline',
            isset($scores[$a]) ? $scores[$a]['total'] : '-',
            isset($scores[$b]) ? $scores[$b]['total'] : '-', true),
    );
    $out = '<div class="vd-card"><h3>Head to head</h3>'
        . '<div class="vd-cmphead"><span>' . vendicator_team_link($a, $lg)
        . '</span><b class="vd-vs">vs</b><span>' . vendicator_team_link($b, $lg)
        . '</span></div><table class="vd-table vd-cmptable">';
    $wins_a = 0;
    $wins_b = 0;
    foreach ($metrics as $m) {
        list($name, $va, $vb, $higher) = $m;
        $cmp = 0;
        if (is_numeric($va) && is_numeric($vb) && $va != $vb) {
            $cmp = ($va > $vb) === (bool) $higher ? 1 : -1;
        }
        if ($cmp > 0) { $wins_a++; } elseif ($cmp < 0) { $wins_b++; }
        $out .= '<tr><td class="' . ($cmp > 0 ? 'vd-better' : '') . '">'
            . esc_html($va) . '</td><td class="vd-cmpname">' . esc_html($name)
            . '</td><td class="' . ($cmp < 0 ? 'vd-better' : '') . '">'
            . esc_html($vb) . '</td></tr>';
    }
    $out .= '</table></div>';

    $lead = $wins_a === $wins_b ? null : ($wins_a > $wins_b ? $a : $b);
    $trail = $lead === null ? null : ($lead === $a ? $b : $a);
    $ls = $lead === $a ? $sa : $sb;
    $ts = $lead === $a ? $sb : $sa;
    // two decimals throughout: at 3 and 3 a reader cannot tell whether two
    // sides are level or just rounded to the same integer
    $n = function ($v) { return number_format((float) $v, 2); };
    $played = min((int) $rows[$a]['p'], (int) $rows[$b]['p']);
    $text = $lead === null
        ? esc_html($a) . ' and ' . esc_html($b) . ' separate on almost nothing: '
            . 'they take ' . $n($sa['ppg']) . ' and ' . $n($sb['ppg'])
            . ' points per game respectively, and the model would price a '
            . 'meeting close to even before home advantage is applied.'
        : esc_html($lead) . ' hold the edge on ' . $wins_a . ' of the '
            . ($wins_a + $wins_b) . ' measures that separate them. They sit '
            . (int) $pos[$lead] . ' to ' . esc_html($trail) . '&rsquo;s '
            . (int) $pos[$trail] . ', bank ' . $n($ls['ppg']) . ' points per game '
            . 'against ' . $n($ts['ppg']) . ', and score ' . $n($ls['gf90'])
            . ' per game while conceding ' . $n($ls['ga90']) . ' &mdash; '
            . esc_html($trail) . ' manage ' . $n($ts['gf90']) . ' and ship '
            . $n($ts['ga90']) . '. Those season rates are exactly what the '
            . 'Dixon-Coles attack and defence strengths are fitted on, so a '
            . 'fixture between them would open with ' . esc_html($lead)
            . ' favoured.';
    $out .= '<div class="vd-card"><h3>The model&rsquo;s read</h3>'
        . '<p style="font-size:13.5px;">' . $text . '</p>'
        . ($played < 5 ? '<p class="vd-muted" style="font-size:12.5px;">'
            . 'Read this lightly &mdash; it is built on ' . (int) $played
            . ' game' . ($played === 1 ? '' : 's') . ' of the new season, so '
            . 'the rates are still noisy. The engine itself leans on several '
            . 'seasons of history, not just these numbers.</p>' : '');
    if (isset($scores[$a], $scores[$b])) {
        $out .= '<p class="vd-muted" style="font-size:12.5px;">Vendicator '
            . 'Scorelines: ' . esc_html($a) . ' <b>' . esc_html($scores[$a]['total'])
            . '</b> (' . esc_html($scores[$a]['decimal']) . ' / '
            . esc_html($scores[$a]['fractional']) . ') &middot; '
            . esc_html($b) . ' <b>' . esc_html($scores[$b]['total']) . '</b> ('
            . esc_html($scores[$b]['decimal']) . ' / '
            . esc_html($scores[$b]['fractional']) . '). The Scoreline folds in '
            . 'how accurate the model has actually been on each side, not just '
            . 'how good they are.</p>';
    }
    return $out . '</div>';
}

add_shortcode('vendicator_compare', function () {
    if (!is_user_logged_in()) {
        return vendicator_shell('', '<div class="vd-card"><p>Please '
            . '<a class="vd-btn" href="' . esc_url(vendicator_page_url('login'))
            . '">log in</a>.</p></div>');
    }
    $want = isset($_GET['fx']) ? sanitize_text_field(wp_unslash($_GET['fx'])) : '';
    $ta = isset($_GET['a']) ? sanitize_text_field(wp_unslash($_GET['a'])) : '';
    $tb = isset($_GET['b']) ? sanitize_text_field(wp_unslash($_GET['b'])) : '';
    $lg = isset($_GET['lg']) ? sanitize_text_field(wp_unslash($_GET['lg'])) : '';
    $p = get_option('vendicator_predictions');
    $uid = get_current_user_id();
    $tier = vendicator_user_tier($uid);
    $allowed = vendicator_tier_markets($tier);
    $sel = get_option('vendicator_model_selectors', array());

    if ($ta && $tb) {
        $body = '<div class="vd-card"><a class="vd-btn" href="'
            . esc_url(add_query_arg('lg', $lg, vendicator_page_url('leagues')))
            . '">&larr; Back to the table</a></div>'
            . vendicator_team_compare($ta, $tb, $lg, (array) $p);
        return vendicator_shell('leagues', $body)
            . vendicator_dashboard_js($p ? $p : array());
    }

    $found = null;
    foreach ((array) (isset($p['fixtures']) ? $p['fixtures'] : array()) as $fx) {
        if ($fx['fixture'] === $want) { $found = $fx; break; }
    }
    $body = '<div class="vd-card"><a class="vd-btn" href="'
        . esc_url(vendicator_page_url('dashboard')) . '">&larr; Back to predictions</a></div>';
    if (!$found) {
        $body .= '<div class="vd-card"><h3>Card not found</h3><p class="vd-muted">'
            . esc_html($want ? $want : 'No fixture selected')
            . ' is not in the current prediction set.</p></div>';
        return vendicator_shell('dashboard', $body);
    }
    $body .= vendicator_render_fixture($found, $allowed, $sel);
    return vendicator_shell('dashboard', $body) . vendicator_dashboard_js($p);
});

add_shortcode('vendicator_cardgrid', function () {
    if (!is_user_logged_in()) {
        return vendicator_shell('', '<div class="vd-card"><p>Please '
            . '<a class="vd-btn" href="' . esc_url(vendicator_page_url('login'))
            . '">log in</a>.</p></div>');
    }
    $p = get_option('vendicator_predictions');
    $uid = get_current_user_id();
    $tier = vendicator_user_tier($uid);
    $shine = in_array($tier, array('gold'), true) ? ' gold'
        : (in_array($tier, array('silver'), true) ? ' silver' : '');
    $cat = vendicator_league_catalogue();
    $cutoff = strtotime('+3 days');
    $byleague = array();
    foreach ((array) (isset($p['fixtures']) ? $p['fixtures'] : array()) as $fx) {
        $ts = vendicator_kickoff_ts($fx);
        if ($ts && $ts > $cutoff) { continue; }
        if (vendicator_has_started($fx)) { continue; }   // logged, not shown
        $fx['_ts'] = $ts;
        $byleague[$fx['league']][] = $fx;
    }
    $out = '<div class="vd-wrap"><div class="vd-inner">' . vendicator_nav('')
        . '<div class="vd-card"><h2 style="margin:0 0 4px;">'
        . '<span class="vd-mascot">&#127918;</span> CardGrid</h2>'
        . '<p class="vd-muted" style="margin:0;">Every card kicking off in the next '
        . 'three days, grouped by competition. Cards leave the grid when their match '
        . 'starts &mdash; unless you have them locked in.</p>'
        . '<p style="margin:10px 0 0;"><span class="vd-pill">&#128274; Live in-play '
        . 'betting &mdash; coming soon for higher tiers</span></p></div>';
    if (!$byleague) {
        $out .= '<div class="vd-card"><p class="vd-muted">No cards kicking off in the '
            . 'next three days. Check the predictions page for the full list.</p></div>';
    }
    foreach ($byleague as $lg => $fxs) {
        usort($fxs, function ($a, $b) { return ($a['_ts'] ?: 0) <=> ($b['_ts'] ?: 0); });
        $lname = isset($cat[$lg]) ? $cat[$lg][0] : $lg;
        $out .= '<div class="vd-card"><h3 class="vd-lhead">'
            . '<img class="vd-leaguebadge" data-league="' . esc_attr($lname)
            . '" alt="">' . esc_html($lname)
            . ' <span class="vd-muted">(' . count($fxs) . ')</span></h3>'
            . '<div class="vd-cardgrid">';
        foreach ($fxs as $fx) {
            $f = $fx['final_calibrated'];
            $sl = isset($fx['scoreline']) ? $fx['scoreline']['headline'] : null;
            $home = isset($fx['home_team']) ? $fx['home_team'] : '';
            $away = isset($fx['away_team']) ? $fx['away_team'] : '';
            $out .= '<a class="vd-gcard' . $shine . '" href="'
                . esc_url(add_query_arg('fx', rawurlencode($fx['fixture']),
                    vendicator_page_url('compare'))) . '">'
                . '<span class="vd-gcode">' . esc_html($fx['short']) . '</span>'
                . '<span class="vd-gname">'
                . '<img class="vd-gbadge" data-badge="' . esc_attr($home) . '" alt="">'
                . esc_html($home) . ' <em>v</em> '
                . '<img class="vd-gbadge" data-badge="' . esc_attr($away) . '" alt="">'
                . esc_html($away) . '</span>'
                . '<span class="vd-gkick">' . esc_html($fx['kickoff'])
                . (isset($fx['gameweek']) && $fx['gameweek']
                    ? ' &middot; GW' . (int) $fx['gameweek'] : '') . '</span>'
                . '<span class="vd-gbar"><i class="h" style="flex:' . floatval($f['home'])
                . '"></i><i class="d" style="flex:' . floatval($f['draw'])
                . '"></i><i class="a" style="flex:' . floatval($f['away']) . '"></i></span>'
                . '<span class="vd-ghover"><b>' . esc_html($fx['fixture']) . '</b><br>'
                . 'Home ' . floatval($f['home']) . '% &middot; Draw ' . floatval($f['draw'])
                . '% &middot; Away ' . floatval($f['away']) . '%<br>'
                . 'Expected goals ' . esc_html($fx['expected_goals']['home']) . ' - '
                . esc_html($fx['expected_goals']['away'])
                . ($sl ? '<br>Vendicator Scoreline <b>' . esc_html($sl) . '</b>' : '')
                . '<br>Difficulty x' . esc_html($fx['reward_difficulty_multiplier'])
                . '<br><br>Click to open the full card</span></a>';
        }
        $out .= '</div></div>';
    }
    return $out . vendicator_footer() . '</div></div>'
        . vendicator_dashboard_js($p ? $p : array());
});

add_shortcode('vendicator_help', function () {
    $sent = isset($_GET['vd_sent']) ? sanitize_text_field(wp_unslash($_GET['vd_sent'])) : '';
    $faqs = array(
        'How do points work?' => 'Reward points are written the way decimal odds '
            . 'are - 2.63, not 263 - so they read like the slip you are used to. '
            . 'Every selection carries a value set by its probability: safe picks '
            . 'pay a little, long shots pay a lot. Land every leg and a bonus '
            . 'multiplier is applied on top. Miss three or more legs and points '
            . 'are deducted, and the card is removed but still logged as a loss.',
        'How is the winning bonus calculated?' => 'Taking on risk deliberately is '
            . 'the point of the builder, so a slip that survives it is paid for it. '
            . 'A full house multiplies your base points by up to 2.25x: the '
            . 'Vendicator Scoreline contributes up to +40%, the risk factor you '
            . 'chose to carry up to +60%, and the price of your longest selection '
            . 'up to a further +25%. The live figure is shown on the slip bar '
            . 'before you place it.',
        'What is the Vendicator Scoreline?' => 'It is our own rating for every team '
            . 'and every player, plus a matching price in decimal and fractional '
            . 'form. It is printed the way a price is - one digit before the point, '
            . 'so a 40.3 composite reads as 4.03 - and it blends the model research '
            . 'with how accurate we have actually been on that team, how often we '
            . 'have narrowly missed, which selection would have won instead, and '
            . 'the platform betting record.',
        'Why was a leg on my slip voided?' => 'Half-time results, corners, fouls '
            . 'and player lines cannot be settled from a final score alone, and '
            . 'the free data we use carries only the score. Rather than guess, '
            . 'those legs are voided: they are dropped from the slip and cost you '
            . 'nothing, and the rest of the slip settles normally. As richer free '
            . 'match detail comes into the pipeline, more of them settle properly.',
        'How do I rank up?' => 'Lifetime points decide your rank badge and your '
            . 'subscription tier. Higher tiers unlock more leagues, more markets, '
            . 'uncertainty bands, odds comparison and customization.',
        'Where does the data come from?' => 'Entirely free and open sources: '
            . 'football-data.co.uk for results and odds, Understat for expected goals '
            . 'and player numbers, TheSportsDB for badges and photos, StatsBomb open '
            . 'data for event-level training, and API-Football on its free tier.',
        'Is this betting advice?' => 'No. Vendicator is a statistical analysis and '
            . 'prediction game. Points have no cash value and nothing here is a '
            . 'recommendation to place a real wager.',
        'How do I get help?' => 'Use the form below and we will reply by email. '
            . 'Subscription, invoice and receipt queries go to the same inbox.',
    );
    $out = '<div class="vd-wrap"><div class="vd-inner">' . vendicator_nav('help')
        . '<div class="vd-card"><h2 style="margin:0 0 6px;">Help &amp; support</h2>'
        . '<p class="vd-muted" style="margin:0;">Answers to the common questions, '
        . 'plus a direct line to the team.</p></div>';
    if ($sent === '1') {
        $out .= '<div class="vd-card" style="border-color:var(--lime);">'
            . '<b>Message sent.</b> We will reply to the address you gave.</div>';
    } elseif ($sent === '0') {
        $out .= '<div class="vd-card" style="border-color:#FF6B6B;">'
            . 'Sorry, that message could not be sent. Please email '
            . 'contact@vendicator.co.uk directly.</div>';
    }
    $out .= '<div class="vd-card"><h3>Frequently asked questions</h3>';
    foreach ($faqs as $q => $a) {
        $out .= '<details class="vd-details" style="border-bottom:1px solid '
            . 'rgba(255,255,255,.07);padding-bottom:6px;"><summary>'
            . esc_html($q) . '</summary><p style="font-size:13.5px;">'
            . esc_html($a) . '</p></details>';
    }
    $out .= '</div><div class="vd-card"><h3>Contact us</h3>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_contact">'
        . wp_nonce_field('vendicator_contact', '_vdnonce', true, false)
        . '<label>Your name</label><input type="text" name="vd_name" required>'
        . '<label>Your email</label><input type="email" name="vd_email" required>'
        . '<label>Subject</label><select name="vd_topic">'
        . '<option>General enquiry</option><option>Subscription</option>'
        . '<option>Invoice or receipt</option><option>Report a problem</option>'
        . '<option>Data or prediction question</option></select>'
        . '<label>Message</label><textarea name="vd_message" rows="6" required '
        . 'style="width:100%;background:rgba(255,255,255,.06);border:1px solid '
        . 'var(--edge);border-radius:10px;color:var(--white);padding:10px 12px;'
        . 'margin:4px 0 12px;"></textarea>'
        . '<input type="submit" value="Send message">'
        . '<p class="vd-muted" style="font-size:12px;">Goes to '
        . '<b>contact@vendicator.co.uk</b>. We aim to reply within two working days.</p>'
        . '</form></div>';
    return $out . vendicator_footer() . '</div></div>'
        . vendicator_dashboard_js(array());
});

add_action('admin_post_nopriv_vendicator_contact', 'vendicator_do_contact');
add_action('admin_post_vendicator_contact', 'vendicator_do_contact');
function vendicator_do_contact() {
    check_admin_referer('vendicator_contact', '_vdnonce');
    $name = sanitize_text_field(wp_unslash($_POST['vd_name'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['vd_email'] ?? ''));
    $topic = sanitize_text_field(wp_unslash($_POST['vd_topic'] ?? 'General'));
    $msg = sanitize_textarea_field(wp_unslash($_POST['vd_message'] ?? ''));
    $ok = false;
    if ($name && is_email($email) && $msg) {
        $body = "From: $name <$email>\nTopic: $topic\n\n$msg\n";
        $ok = wp_mail('contact@vendicator.co.uk',
            '[Vendicator] ' . $topic . ' - ' . $name, $body,
            array('Reply-To: ' . $name . ' <' . $email . '>'));
        $log = (array) get_option('vendicator_contact_log', array());
        $log[] = array('ts' => gmdate('c'), 'name' => $name, 'email' => $email,
            'topic' => $topic, 'message' => $msg, 'delivered' => (bool) $ok);
        update_option('vendicator_contact_log', array_slice($log, -200));
    }
    wp_safe_redirect(add_query_arg('vd_sent', $ok ? '1' : '0',
        vendicator_page_url('help')));
    exit;
}

add_shortcode('vendicator_leagues', function () {
    $p = get_option('vendicator_predictions');
    $tables = isset($p['tables']) ? (array) $p['tables'] : array();
    $registry = isset($p['teams']) ? (array) $p['teams'] : array();
    $cat = vendicator_league_catalogue();
    $sel = isset($_GET['lg']) ? sanitize_text_field(wp_unslash($_GET['lg'])) : '';
    $ctry = isset($_GET['ctry']) ? sanitize_text_field(wp_unslash($_GET['ctry'])) : '';
    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    if (!$sel) { $sel = $tables ? array_keys($tables)[0] : 'E0'; }

    $countries = array();
    foreach ($cat as $code => $cfg) { $countries[$cfg[1]] = true; }
    $out = '<div class="vd-wrap"><div class="vd-inner">'
        . vendicator_nav('leagues')
        . '<div class="vd-card">' . vendicator_logo('league tables')
        . '<form method="get" class="vd-filters" style="margin-top:14px;">'
        . '<div><label>Country</label><select name="ctry"><option value="">All countries</option>';
    foreach (array_keys($countries) as $c) {
        $out .= '<option value="' . esc_attr($c) . '" ' . selected($ctry, $c, false)
            . '>' . esc_html($c) . '</option>';
    }
    $out .= '</select></div><div><label>Competition</label><select name="lg">';
    foreach ($cat as $code => $cfg) {
        if ($ctry && $cfg[1] !== $ctry) { continue; }
        $out .= '<option value="' . esc_attr($code) . '" ' . selected($sel, $code, false)
            . '>' . esc_html($cfg[0]) . '</option>';
    }
    $out .= '</select></div><div><label>Search players</label>'
        . '<input type="text" name="q" value="' . esc_attr($q) . '" placeholder="e.g. Haaland"></div>'
        . '<div style="flex:0;"><input type="submit" value="Show"></div>'
        . '</form></div>';

    if ($q) {
        $out .= '<div class="vd-card"><h3>Player search &mdash; &ldquo;' . esc_html($q) . '&rdquo;</h3>';
        $hits = array();
        foreach ((array) (isset($p['fixtures']) ? $p['fixtures'] : array()) as $fx) {
            foreach ((array) (isset($fx['players']) ? $fx['players'] : array()) as $pl) {
                if (stripos($pl['name'], $q) !== false) { $hits[$pl['id']] = $pl; }
            }
        }
        if ($hits) {
            $out .= '<table class="vd-table"><tr><td><b>Player</b></td><td><b>Team</b></td>'
                . '<td><b>G / A</b></td><td><b>Value</b></td></tr>';
            foreach ($hits as $pl) {
                $out .= '<tr><td><a class="vd-btn" style="padding:4px 12px;font-size:12px;" href="'
                    . esc_url(add_query_arg(array('vd_player' => rawurlencode((string) $pl['id']),
                        'vd_pname' => rawurlencode($pl['name'])), vendicator_page_url('player')))
                    . '">' . esc_html($pl['name']) . '</a></td><td>' . esc_html($pl['team'])
                    . '</td><td>' . (int) $pl['goals'] . ' / ' . (int) $pl['assists']
                    . '</td><td><span class="vd-value">' . esc_html($pl['rating'])
                    . '</span></td></tr>';
            }
            $out .= '</table>';
        } else {
            $out .= '<p class="vd-muted">No players matched in the current prediction set. '
                . 'The searchable pool grows with every fixture the engine covers.</p>';
        }
        $out .= '</div>';
    }

    $table = isset($tables[$sel]) ? $tables[$sel] : array();
    $label = isset($cat[$sel]) ? $cat[$sel][0] : $sel;
    $season = isset($p['table_seasons'][$sel]) ? $p['table_seasons'][$sel] : null;
    $out .= vendicator_league_table_card($table, $sel, $label, $season);
    return $out . vendicator_footer() . '</div></div>'
        . vendicator_dashboard_js($p ? $p : array());
});

/**
 * Which positions carry consequences in each competition, so the table can
 * show a member what a row actually means rather than just its number.
 * [promotion/europe places, play-off places, relegation places]
 */
function vendicator_league_zones($code) {
    $z = array(
        'E0' => array(4, 2, 3), 'E1' => array(2, 4, 3),
        'E2' => array(2, 4, 4), 'E3' => array(3, 4, 2),
        'SP1' => array(4, 2, 3), 'SP2' => array(2, 4, 4),
        'I1' => array(4, 2, 3), 'I2' => array(2, 5, 3),
        'D1' => array(4, 1, 2), 'F1' => array(3, 1, 2),
        'SC0' => array(3, 1, 1), 'N1' => array(2, 4, 2),
        'P1' => array(3, 1, 2), 'B1' => array(2, 4, 1),
    );
    return isset($z[$code]) ? $z[$code] : array(0, 0, 0);
}

/**
 * The league table, with the standings read the way a supporter reads them:
 * a green rising arrow on the places that go up or into Europe, amber on
 * the play-off places, a red falling arrow on the drop. Every row is a link
 * to the team page, and any two rows can be sent to the comparison engine.
 */
function vendicator_league_table_card($table, $code, $label, $season = null) {
    list($up, $playoff, $down) = vendicator_league_zones($code);
    $n = count($table);
    // a completed table is labelled as completed, never as "live"
    $slabel = !empty($season['label']) ? $season['label'] : '';
    $live = !isset($season['current']) || !empty($season['current']);
    $out = '<div class="vd-card"><h3>' . esc_html($label) . ' &mdash; '
        . ($slabel ? esc_html($slabel) . ' ' : '')
        . ($live ? 'live table' : 'final table') . '</h3>'
        . ($live ? '' : '<p class="vd-muted" style="margin:-6px 0 12px;'
            . 'font-size:12.5px;">The new season has not produced results yet, '
            . 'so this is where last season finished. It switches to the live '
            . 'table on the first matchday.</p>')
        . '<form method="get" action="' . esc_url(vendicator_page_url('compare')) . '">'
        . '<input type="hidden" name="lg" value="' . esc_attr($code) . '">'
        . '<div class="vd-tablewrap"><table class="vd-table vd-ltable">'
        . '<tr><td><b>#</b></td><td><b>Team</b></td><td><b>P</b></td>'
        . '<td><b>W</b></td><td><b>D</b></td><td><b>L</b></td><td><b>GF</b></td>'
        . '<td><b>GA</b></td><td><b>GD</b></td><td><b>Pts</b></td>'
        . '<td><b>Compare</b></td></tr>';
    foreach ($table as $i => $r) {
        $pos = $i + 1;
        $gd = (int) $r['gf'] - (int) $r['ga'];
        $zone = '';
        $mark = '';
        if ($up && $pos <= $up) {
            $zone = ' up';
            $mark = '<span class="vd-arrow up" title="Promotion / European '
                . 'qualification place">&#9650;</span>';
        } elseif ($playoff && $pos <= $up + $playoff) {
            $zone = ' po';
            $mark = '<span class="vd-arrow po" title="Play-off place">&#9679;</span>';
        } elseif ($down && $pos > $n - $down) {
            $zone = ' down';
            $mark = '<span class="vd-arrow down" title="Relegation place">&#9660;</span>';
        }
        $team = esc_attr($r['team']);
        $out .= '<tr class="vd-lrow' . $zone . '"><td>' . $mark . ' ' . $pos
            . '</td><td>' . vendicator_team_link($r['team'], $code)
            . '</td><td>' . (int) $r['p']
            . '</td><td>' . (int) $r['w'] . '</td><td>' . (int) $r['d'] . '</td><td>'
            . (int) $r['l'] . '</td><td>' . (int) $r['gf'] . '</td><td>' . (int) $r['ga']
            . '</td><td>' . ($gd > 0 ? '+' : '') . $gd . '</td><td>' . (int) $r['pts']
            . '</td><td class="vd-cmpcell">'
            . '<label title="Set as team A"><input type="radio" name="a" value="'
            . $team . '"><span>A</span></label>'
            . '<label title="Set as team B"><input type="radio" name="b" value="'
            . $team . '"><span>B</span></label></td></tr>';
    }
    if (!$table) {
        $out .= '<tr><td colspan="11" class="vd-muted">No standings cached for this '
            . 'competition yet &mdash; it fills on the next pipeline run.</td></tr>';
    }
    $out .= '</table></div>'
        . '<div class="vd-legend"><span class="vd-arrow up">&#9650;</span> promotion '
        . '/ Europe <span class="vd-arrow po">&#9679;</span> play-offs '
        . '<span class="vd-arrow down">&#9660;</span> relegation</div>'
        . ($table ? '<p style="margin-top:12px;"><input type="submit" '
            . 'value="Compare A vs B"></p>' : '')
        . '</form><p class="vd-muted" style="font-size:12px;">Standings rebuild '
        . 'from open results data on every pipeline run &mdash; pick any two '
        . 'sides above for a written head-to-head.</p></div>';
    return $out;
}

add_shortcode('vendicator_player', function () {
    $pid = isset($_GET['vd_player']) ? sanitize_text_field(wp_unslash($_GET['vd_player'])) : '';
    $pname = isset($_GET['vd_pname']) ? sanitize_text_field(wp_unslash($_GET['vd_pname'])) : '';
    $p = get_option('vendicator_predictions');
    $pl = null;
    foreach ((array) (isset($p['fixtures']) ? $p['fixtures'] : array()) as $fx) {
        foreach ((array) (isset($fx['players']) ? $fx['players'] : array()) as $cand) {
            if ((string) $cand['id'] === (string) $pid) { $pl = $cand; break 2; }
        }
    }
    $out = '<div class="vd-wrap"><div class="vd-inner">' . vendicator_nav('')
        . '<div class="vd-card">' . vendicator_logo();
    if (!$pl) {
        return $out . '<h2 style="margin-top:10px;">' . esc_html($pname) . '</h2>'
            . '<p class="vd-muted">This player is not in the current prediction set. '
            . 'Open a fixture that features them to load their profile.</p></div>'
            . vendicator_footer() . '</div></div>';
    }
    $long = !empty($pl['position_long']) ? $pl['position_long'] : $pl['position'];
    $short = !empty($pl['position_short']) ? $pl['position_short'] : '';
    $out .= '<div style="display:flex;align-items:center;gap:16px;margin-top:12px;">'
        . '<img class="vd-playerpic" style="width:84px;height:84px;" data-player="'
        . esc_attr($pl['name']) . '" alt=""><div><h2 style="margin:0;">'
        // squad number comes from TheSportsDB at render time; the slot stays
        // empty rather than showing a guess when the open feed has no number
        . '<span class="vd-shirt" data-number="' . esc_attr($pl['name'])
        . '"></span>' . esc_html($pl['name'])
        . ($short ? ' <i class="vd-pos">' . esc_html($short) . '</i>' : '')
        . '</h2><p class="vd-muted" style="margin:2px 0 0;">'
        . '<b style="color:#7CFFCB;">' . esc_html($long) . '</b> &middot; '
        . esc_html($pl['team'])
        . ' &middot; value rating <b style="color:#C6FF4D;">' . esc_html($pl['rating'])
        . '</b></p></div></div></div>';

    $mins = max((int) $pl['minutes'], 1);
    $out .= '<div class="vd-grid"><div class="vd-card"><h3>Season statistics</h3>'
        . '<table class="vd-table">'
        . '<tr><td>Appearances</td><td>' . (int) $pl['games'] . '</td></tr>'
        . '<tr><td>Minutes</td><td>' . (int) $pl['minutes'] . '</td></tr>'
        . '<tr><td>Goals</td><td>' . (int) $pl['goals'] . '</td></tr>'
        . '<tr><td>Assists</td><td>' . (int) $pl['assists'] . '</td></tr>'
        . '<tr><td>Goals per 90</td><td>' . round($pl['goals'] / $mins * 90, 2) . '</td></tr>'
        . '<tr><td>Assists per 90</td><td>' . round($pl['assists'] / $mins * 90, 2) . '</td></tr>'
        . '<tr><td>xG / xA</td><td>' . esc_html($pl['xG']) . ' / ' . esc_html($pl['xA']) . '</td></tr>'
        . '<tr><td>Shots / key passes</td><td>' . (int) $pl['shots'] . ' / '
        . (int) $pl['key_passes'] . '</td></tr></table></div>';

    $out .= '<div class="vd-card"><h3>Last 5 games</h3>'
        . '<p class="vd-muted" style="margin-bottom:6px;">Goals</p>'
        . vendicator_dot_row($pl['last5']['goals'])
        . '<p class="vd-muted" style="margin:14px 0 6px;">Assists</p>'
        . vendicator_dot_row($pl['last5']['assists'])
        . '<p class="vd-muted" style="font-size:12px;margin-top:14px;">'
        . '<i class="vd-dot goal"></i> scored &nbsp; <i class="vd-dot assist"></i> assisted '
        . '&nbsp; <i class="vd-dot blank"></i> neither &nbsp; <i class="vd-dot dnp"></i> '
        . 'did not play</p></div>';

    $finishing = $pl['goals'] - (float) $pl['xG'];
    $creating = $pl['assists'] - (float) $pl['xA'];
    $out .= '<div class="vd-card"><h3>Value &amp; history</h3><p style="font-size:13.5px;">'
        . esc_html($pl['name']) . ' rates <b style="color:#C6FF4D;">' . esc_html($pl['rating'])
        . '/100</b> on the model\'s value scale, built from scoring and creation rates per 90, '
        . 'shot and chance volume, minutes trusted, and finishing quality against expected goals. '
        . 'This season they are ' . ($finishing >= 0 ? 'outperforming' : 'underperforming')
        . ' their xG by ' . esc_html(number_format(abs($finishing), 2)) . ' and '
        . ($creating >= 0 ? 'beating' : 'trailing') . ' their xA by '
        . esc_html(number_format(abs($creating), 2)) . '. '
        . 'Because points scale inversely with value, backing them to score pays <b>'
        . vendicator_pts($pl['points']['score'], true) . '</b>, to assist <b>'
        . vendicator_pts($pl['points']['assist'], true) . '</b>, either <b>'
        . vendicator_pts($pl['points']['score_or_assist'], true) . '</b>.</p>'
        . '<p class="vd-muted" style="font-size:12px;">Historic seasons build up in the '
        . 'archive as the engine logs each matchday.</p></div></div>'
        . '<p style="margin-top:16px;"><a class="vd-btn" href="'
        . esc_url(vendicator_page_url('dashboard')) . '">Back to predictions</a></p>'
        . vendicator_footer() . '</div></div>'
        . vendicator_dashboard_js($p ? $p : array());
    return $out;
});

add_action('admin_post_vendicator_ack', function () {
    check_admin_referer('vendicator_ack', '_vdnonce');
    $uid = get_current_user_id();
    $i = isset($_GET['i']) ? (int) $_GET['i'] : -1;
    $key = empty($_GET['slip']) ? 'vendicator_bets' : 'vendicator_slips';
    if ($uid && $i >= 0) {
        $rows = json_decode((string) get_user_meta($uid, $key, true), true);
        if (isset($rows[$i])) {
            $rows[$i]['ack'] = true;
            update_user_meta($uid, $key, wp_json_encode($rows));
        }
    }
    wp_safe_redirect(wp_get_referer() ?: vendicator_page_url('dashboard'));
    exit;
});

add_shortcode('vendicator_team', function () {
    if (!is_user_logged_in()) {
        return vendicator_shell('', '<div class="vd-card"><p>Please '
            . '<a class="vd-btn" href="' . esc_url(vendicator_page_url('login')) . '">log in</a>.'
            . '</p></div>');
    }
    $team = isset($_GET['vd_team']) ? sanitize_text_field(wp_unslash($_GET['vd_team'])) : '';
    $league = isset($_GET['vd_league']) ? sanitize_text_field(wp_unslash($_GET['vd_league'])) : '';
    $p = get_option('vendicator_predictions');
    $tables = isset($p['tables']) ? $p['tables'] : array();
    $table = isset($tables[$league]) ? $tables[$league] : array();

    $out = '<div class="vd-wrap"><div class="vd-inner">' . vendicator_nav('')
        . '<div class="vd-card">' . vendicator_logo()
        . '<h2 style="margin-top:10px;">' . vendicator_team_link($team, $league) . '</h2>'
        . '<p class="vd-muted">' . esc_html($league) . ' &middot; live season data, '
        . 'refreshed every pipeline run</p></div>';

    $row = null;
    $pos = null;
    foreach ($table as $i => $r) {
        if ($r['team'] === $team) { $row = $r; $pos = $i + 1; }
    }
    $out .= '<div class="vd-grid"><div class="vd-card"><h3>Team information</h3>';
    if ($row) {
        $gd = (int) $row['gf'] - (int) $row['ga'];
        $ppg = $row['p'] ? round($row['pts'] / $row['p'], 2) : 0;
        $out .= '<table class="vd-table">'
            . '<tr><td>League position</td><td>' . (int) $pos . '</td></tr>'
            . '<tr><td>Played</td><td>' . (int) $row['p'] . '</td></tr>'
            . '<tr><td>Won / Drawn / Lost</td><td>' . (int) $row['w'] . ' / '
            . (int) $row['d'] . ' / ' . (int) $row['l'] . '</td></tr>'
            . '<tr><td>Goals for / against</td><td>' . (int) $row['gf'] . ' / '
            . (int) $row['ga'] . '</td></tr>'
            . '<tr><td>Goal difference</td><td>' . ($gd > 0 ? '+' : '') . $gd . '</td></tr>'
            . '<tr><td>Points / per game</td><td>' . (int) $row['pts'] . ' (' . $ppg . ')</td></tr>'
            . '</table>';
        $form = $row['p'] >= 3 && $ppg >= 2 ? 'in strong form' : ($ppg >= 1.3 ? 'holding steady' : 'struggling for points');
        $att = (int) $row['gf'] > (int) $row['ga'] ? 'outscoring opponents' : 'leaking more than they score';
        $out .= '<div class="vd-card" style="margin-top:12px;background:rgba(198,255,77,.06);">'
            . '<h3>Model read</h3><p style="font-size:13.5px;">'
            . esc_html($team) . ' sit ' . (int) $pos . ' on ' . (int) $row['pts']
            . ' points from ' . (int) $row['p'] . ' games, ' . $form . ' at ' . $ppg
            . ' points per game and ' . $att . ' (' . (int) $row['gf'] . ':' . (int) $row['ga']
            . '). These season rates feed the Dixon-Coles attack/defence strengths and the '
            . 'Bayesian uncertainty band behind every prediction shown for this side.</p></div>';
    } else {
        $out .= '<p class="vd-muted">No current-season rows yet for this team &mdash; '
            . 'the table fills as results publish.</p>';
    }
    $out .= '</div>';

    $out .= '<div class="vd-card"><h3>League table &mdash; ' . esc_html($league) . '</h3>'
        . '<table class="vd-table"><tr><td><b>#</b> Team</td><td><b>Pts</b></td></tr>';
    foreach (array_slice($table, 0, 24) as $i => $r) {
        $hl = $r['team'] === $team ? ' style="color:#C6FF4D;font-weight:800;"' : '';
        $out .= '<tr' . $hl . '><td>' . ($i + 1) . '. ' . esc_html($r['team'])
            . ' <span class="vd-muted">(' . (int) $r['p'] . 'p)</span></td><td>'
            . (int) $r['pts'] . '</td></tr>';
    }
    if (!$table) {
        $out .= '<tr><td class="vd-muted">Standings build as the season\'s results publish.</td><td></td></tr>';
    }
    $out .= '</table></div>';

    $out .= '<div class="vd-card"><h3>Player statistics</h3>'
        . '<p class="vd-muted">Squad and player-level stats (appearances, goals, assists, '
        . 'xG, xA, minutes) attach here from the open data layer as the player module '
        . 'lands &mdash; the same numbers drive top-scorer and assist markets.</p>'
        . '<p class="vd-muted" style="font-size:12px;">Free sources in the pipeline: '
        . 'Understat player xG/xA, TheSportsDB squad art, StatsBomb open events.</p></div>';

    $out .= '</div><p style="margin-top:16px;"><a class="vd-btn" href="'
        . esc_url(vendicator_page_url('dashboard')) . '">Back to predictions</a></p>'
        . vendicator_footer() . '</div></div>'
        . vendicator_dashboard_js($p ? $p : array());
    return $out;
});

add_shortcode('vendicator_account', function () {
    if (!is_user_logged_in()) {
        return vendicator_shell('account', '<div class="vd-card">'
            . '<p>Please <a class="vd-btn" href="' . esc_url(vendicator_page_url('login'))
            . '">log in</a>.</p></div>');
    }
    $uid = get_current_user_id();
    $u = wp_get_current_user();
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
    $tabs = array('settings' => 'Settings', 'leagues' => 'Competitions / Leagues',
        'betting' => 'Betting History', 'rewards' => 'Reward History',
        'profile' => 'Manage Profile', 'subscriptions' => 'Subscriptions');
    $base = vendicator_page_url('account');
    $nav = '<div class="vd-tabs">';
    foreach ($tabs as $slug => $label) {
        $nav .= '<a class="' . ($tab === $slug ? 'on' : '') . '" href="'
            . esc_url(add_query_arg('tab', $slug, $base)) . '">' . esc_html($label) . '</a>';
    }
    $nav .= '</div>';
    $tier = vendicator_user_tier($uid);
    $points = (int) get_user_meta($uid, 'vendicator_points_balance', true);
    $lifetime = (int) get_user_meta($uid, 'vendicator_lifetime_points', true);
    $body = '';

    if ($tab === 'settings') {
        $body = '<div class="vd-card"><h3>Settings</h3>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="vendicator_settings">'
            . wp_nonce_field('vendicator_settings', '_vdnonce', true, false)
            . '<label>Display name</label><input type="text" name="vd_display" value="'
            . esc_attr($u->display_name) . '">'
            . '<label>Email</label><input type="email" name="vd_email" value="'
            . esc_attr($u->user_email) . '">'
            . '<input type="submit" value="Save"></form></div>';
    } elseif ($tab === 'leagues') {
        $mine = (array) json_decode((string) get_user_meta($uid, 'vendicator_leagues', true), true);
        $body = '<div class="vd-card"><h3>Followed competitions</h3>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="vendicator_leagues">'
            . wp_nonce_field('vendicator_leagues', '_vdnonce', true, false);
        $leagues = vendicator_leagues();
        foreach ($leagues as $code => $name) {
            $body .= '<label><input type="checkbox" name="vd_leagues[]" value="' . esc_attr($code) . '" '
                . checked(in_array($code, $mine, true), true, false) . '> '
                . esc_html($name) . '</label><br>';
        }
        $body .= '<br><input type="submit" value="Save"></form>'
            . '<p class="vd-muted">Your rank gates which tiers you can open: '
            . esc_html(strtoupper($tier)) . ' unlocks ' . ($tier === 'free' ? 'T3 lower leagues'
            : ($tier === 'bronze' ? 'T3 + T2' : 'all tiers')) . '.</p></div>';
    } elseif ($tab === 'betting') {
        $slips = json_decode((string) get_user_meta($uid, 'vendicator_slips', true), true);
        $body = '<div class="vd-card"><h3>Bet builder slips</h3>';
        if (!$slips) {
            $body .= '<p class="vd-muted">No slips placed yet. Build one from any '
                . 'prediction card.</p>';
        }
        foreach (array_reverse((array) $slips) as $s) {
            $state = empty($s['settled']) ? 'open'
                : (isset($s['outcome']) ? $s['outcome'] : 'settled');
            $body .= '<details class="vd-details" style="border-bottom:1px solid '
                . 'rgba(255,255,255,.07);"><summary>'
                . esc_html($s['fixture']) . ' &middot; ' . count((array) $s['legs'])
                . ' legs &middot; ' . esc_html(strtoupper($state))
                . (isset($s['points']) && $s['settled']
                    ? ' &middot; ' . vendicator_pts($s['points'], true) : '')
                . '</summary><table class="vd-table">';
            foreach ((array) $s['legs'] as $leg) {
                $body .= '<tr><td>' . esc_html($leg['label']) . ' <span class="vd-muted">'
                    . esc_html($leg['pct']) . '%</span></td><td>'
                    . vendicator_pts($leg['points'], true) . '</td></tr>';
            }
            $body .= '</table>'
                . '<p class="vd-muted" style="font-size:12px;">risk '
                . round((float) (isset($s['risk']) ? $s['risk'] : 0) * 100) . '%'
                . (isset($s['score']) && $s['score'] ? ' &middot; finished '
                    . esc_html($s['score']) : '')
                . (isset($s['note']) ? ' &middot; ' . esc_html($s['note']) : '')
                . '</p></details>';
        }
        $bets = json_decode((string) get_user_meta($uid, 'vendicator_bets', true), true);
        $body .= '</div><div class="vd-card"><h3>Single predictions</h3><table class="vd-table">';
        if (!$bets) { $body .= '<tr><td class="vd-muted">No predictions yet.</td><td></td></tr>'; }
        foreach (array_reverse((array) $bets) as $b) {
            $body .= '<tr><td>' . esc_html(substr($b['ts'], 0, 16) . ' · ' . $b['fixture'])
                . ' → ' . esc_html($b['pick']) . '</td><td>'
                . ($b['settled'] ? vendicator_pts($b['points'], true)
                    : '<span class="vd-muted">open</span>')
                . '</td></tr>';
        }
        $body .= '</table></div>';
    } elseif ($tab === 'rewards') {
        $hist = json_decode((string) get_user_meta($uid, 'vendicator_points_history', true), true);
        $data = wp_json_encode(array_values(array_map('intval', (array) $hist)));
        $body = '<div class="vd-card"><h3>Points balance</h3>'
            . '<p style="font-size:32px;font-weight:800;color:#C6FF4D;">'
            . vendicator_pts($points)
            . ' <span class="vd-muted" style="font-size:14px;">('
            . vendicator_pts($lifetime) . ' lifetime)</span></p>'
            . '<p class="vd-muted" style="margin:-8px 0 14px;font-size:12px;">'
            . 'Reward points read like decimal odds &mdash; a landed long shot '
            . 'moves you further than a string of short ones.</p>'
            . '<h3>Graph tally</h3><canvas id="vd-graph" width="640" height="180"></canvas>'
            . '<script>(function(){var d=' . $data . ';var c=document.getElementById("vd-graph");'
            . 'if(!c)return;var x=c.getContext("2d");if(!d.length)d=[0];'
            . 'var mx=Math.max.apply(null,d.concat([1]));x.strokeStyle="#C6FF4D";x.lineWidth=2.5;'
            . 'x.shadowColor="rgba(198,255,77,.6)";x.shadowBlur=8;x.beginPath();'
            . 'd.forEach(function(v,i){var px=20+i*(600/Math.max(d.length-1,1));'
            . 'var py=160-(v/mx)*140;i?x.lineTo(px,py):x.moveTo(px,py);});x.stroke();})();</script>'
            . '<p class="vd-muted">Every settled prediction appends to the tally - climb it to rank up.</p></div>';
    } elseif ($tab === 'profile') {
        list($rank, $nextrank) = vendicator_rank($lifetime);
        $loc = get_user_meta($uid, 'vendicator_location', true);
        $fav = get_user_meta($uid, 'vendicator_fav_team', true);
        $hide = (bool) get_user_meta($uid, 'vendicator_hide_email', true);
        $body = '<div class="vd-card"><h3>Rank badge</h3>'
            . '<div class="vd-rank"><span class="vd-rank-icon">' . $rank['icon'] . '</span>'
            . '<b>' . esc_html($rank['name']) . '</b></div>';
        if ($nextrank) {
            $pctv = min(100, round($lifetime / max((int) $nextrank['points'], 1) * 100));
            $body .= '<p class="vd-muted">' . vendicator_pts($lifetime) . ' / '
                . vendicator_pts($nextrank['points'])
                . ' points to ' . esc_html($nextrank['name']) . ' ' . $nextrank['icon'] . '</p>'
                . '<div style="height:10px;background:rgba(255,255,255,.08);border-radius:6px;overflow:hidden;">'
                . '<div style="height:100%;width:' . $pctv . '%;background:linear-gradient(90deg,#9BE81F,#C6FF4D);"></div></div>';
        } else { $body .= '<p class="vd-muted">Top rank reached.</p>'; }
        $body .= '<p class="vd-muted">Badges upgrade automatically as your points total grows - your status is earned, not uploaded.</p></div>'
            . '<div class="vd-card"><h3>Profile</h3>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="vendicator_profile">'
            . wp_nonce_field('vendicator_profile', '_vdnonce', true, false)
            . '<label>Username (display name)</label><input type="text" name="vd_display" value="' . esc_attr($u->display_name) . '">'
            . '<label>Location</label><input type="text" name="vd_location" value="' . esc_attr($loc) . '">'
            . '<label>Favourite team (followed in predictions)</label><input type="text" name="vd_fav" value="' . esc_attr($fav) . '">'
            . '<label><input type="checkbox" name="vd_hide_email" value="1" ' . checked($hide, true, false) . '> Hide my email from leaderboards and public profiles</label><br><br>'
            . '<label>New password (optional)</label><input type="password" name="vd_pass1" minlength="8">'
            . '<label>Confirm new password</label><input type="password" name="vd_pass2" minlength="8">'
            . '<input type="submit" value="Save profile">'
            . '<p class="vd-muted">Subscription management lives in the Subscriptions tab.</p>'
            . '</form></div>';
    } else { /* subscriptions */
        $tiers = vendicator_tiers();
        $body = '<div class="vd-card"><h3>Your subscription</h3>'
            . '<p><span class="vd-pill">' . esc_html(strtoupper($tier)) . '</span> '
            . esc_html($tiers[$tier]['benefits']) . '</p>';
        $next = null;
        foreach ($tiers as $slug => $cfg) {
            if ((int) $cfg['points'] > (int) $tiers[$tier]['points']) { $next = $slug; break; }
        }
        if ($next) {
            $need = (int) $tiers[$next]['points'];
            $pctv = min(100, round($lifetime / max($need, 1) * 100));
            $body .= '<p class="vd-muted">Progress to ' . esc_html(strtoupper($next))
                . ': ' . vendicator_pts($lifetime) . ' / ' . vendicator_pts($need)
                . ' lifetime points (' . $pctv . '%)</p>'
                . '<div style="height:10px;background:rgba(255,255,255,.08);border-radius:6px;overflow:hidden;">'
                . '<div style="height:100%;width:' . $pctv . '%;background:linear-gradient(90deg,#9BE81F,#C6FF4D);"></div></div>';
            if ($lifetime >= $need) {
                $body .= '<br><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="action" value="vendicator_upgrade">'
                    . wp_nonce_field('vendicator_upgrade', '_vdnonce', true, false)
                    . '<input type="submit" value="Upgrade to ' . esc_attr(strtoupper($next)) . '"></form>';
            }
        } else { $body .= '<p class="vd-muted">Top tier reached.</p>'; }
        $body .= '</div>';
    }
    return vendicator_shell('account', '<div class="vd-card">'
        . vendicator_logo('account') . '</div>' . $nav . $body)
        . vendicator_dashboard_js(array());
});

add_action('admin_post_vendicator_settings', function () {
    check_admin_referer('vendicator_settings', '_vdnonce');
    $uid = get_current_user_id();
    if ($uid) {
        wp_update_user(array('ID' => $uid,
            'display_name' => sanitize_text_field(wp_unslash($_POST['vd_display'] ?? '')),
            'user_email' => sanitize_email(wp_unslash($_POST['vd_email'] ?? ''))));
    }
    wp_safe_redirect(vendicator_page_url('account'));
    exit;
});
add_action('admin_post_vendicator_leagues', function () {
    check_admin_referer('vendicator_leagues', '_vdnonce');
    $uid = get_current_user_id();
    if ($uid) {
        $sel = array_map('sanitize_text_field', (array) wp_unslash($_POST['vd_leagues'] ?? array()));
        update_user_meta($uid, 'vendicator_leagues', wp_json_encode($sel));
    }
    wp_safe_redirect(vendicator_page_url('account') . '?tab=leagues');
    exit;
});
add_action('admin_post_vendicator_profile', function () {
    check_admin_referer('vendicator_profile', '_vdnonce');
    $uid = get_current_user_id();
    if ($uid) {
        wp_update_user(array('ID' => $uid, 'display_name' =>
            sanitize_text_field(wp_unslash(isset($_POST['vd_display']) ? $_POST['vd_display'] : ''))));
        update_user_meta($uid, 'vendicator_location',
            sanitize_text_field(wp_unslash(isset($_POST['vd_location']) ? $_POST['vd_location'] : '')));
        update_user_meta($uid, 'vendicator_fav_team',
            sanitize_text_field(wp_unslash(isset($_POST['vd_fav']) ? $_POST['vd_fav'] : '')));
        update_user_meta($uid, 'vendicator_hide_email', empty($_POST['vd_hide_email']) ? 0 : 1);
        $p1 = (string) wp_unslash(isset($_POST['vd_pass1']) ? $_POST['vd_pass1'] : '');
        $p2 = (string) wp_unslash(isset($_POST['vd_pass2']) ? $_POST['vd_pass2'] : '');
        if ($p1 !== '' && $p1 === $p2 && strlen($p1) >= 8) {
            wp_set_password($p1, $uid);
            wp_set_current_user($uid);
            wp_set_auth_cookie($uid);
        }
    }
    wp_safe_redirect(vendicator_page_url('account') . '?tab=profile');
    exit;
});
add_action('admin_post_vendicator_upgrade', function () {
    check_admin_referer('vendicator_upgrade', '_vdnonce');
    $uid = get_current_user_id();
    if ($uid) {
        $lifetime = (int) get_user_meta($uid, 'vendicator_lifetime_points', true);
        $current = vendicator_user_tier($uid);
        foreach (vendicator_tiers() as $slug => $cfg) {
            if ((int) $cfg['points'] > 0 && $lifetime >= (int) $cfg['points']) {
                $current = $slug;
            }
        }
        update_user_meta($uid, 'vendicator_tier', $current);
    }
    wp_safe_redirect(vendicator_page_url('account') . '?tab=subscriptions');
    exit;
});

/* ------------------------------------------------------------ outbound mail */

/**
 * Vendicator sends real mail (contact form, receipts, password resets), and
 * Hostinger's shared PHP mail() lands in spam often enough to be useless for
 * anything a member is waiting on. This routes wp_mail through a proper
 * authenticated SMTP relay instead.
 *
 * The defaults target Zoho Mail's free custom-domain plan, which is the
 * strongest genuinely-free option for contact@vendicator.co.uk: five
 * mailboxes on your own domain, IMAP/SMTP included, no card required, and
 * Zoho Desk (3 agents) and Zoho Invoice (free, unlimited) sit on the same
 * login for support tickets and subscription invoices. Host and port are
 * editable, so switching relays later is a settings change, not a rebuild.
 */
function vendicator_mail_settings() {
    $d = array('enabled' => 0, 'host' => 'smtp.zoho.eu', 'port' => 465,
        'secure' => 'ssl', 'user' => 'contact@vendicator.co.uk',
        'pass' => '', 'from' => 'contact@vendicator.co.uk',
        'from_name' => 'Vendicator');
    return array_merge($d, (array) get_option('vendicator_mail', array()));
}

add_action('phpmailer_init', function ($mailer) {
    $m = vendicator_mail_settings();
    if (empty($m['enabled']) || empty($m['host']) || empty($m['pass'])) {
        return;   // fall back to the host's mail() until SMTP is configured
    }
    $mailer->isSMTP();
    $mailer->Host = $m['host'];
    $mailer->Port = (int) $m['port'];
    $mailer->SMTPAuth = true;
    $mailer->Username = $m['user'];
    $mailer->Password = $m['pass'];
    $mailer->SMTPSecure = $m['secure'];
    if (!empty($m['from'])) {
        $mailer->setFrom($m['from'], $m['from_name'], false);
    }
});

function vendicator_admin_mail() {
    $m = vendicator_mail_settings();
    $sent = isset($_GET['vd_test']) ? sanitize_text_field(wp_unslash($_GET['vd_test'])) : '';
    echo '<div class="wrap"><h1>Mail &amp; Support</h1>';
    if ($sent === '1') {
        echo '<div class="notice notice-success"><p>Test message accepted by the '
            . 'relay. Check the inbox.</p></div>';
    } elseif ($sent === '0') {
        echo '<div class="notice notice-error"><p>The relay rejected the test '
            . 'message. Check the host, port and app password.</p></div>';
    }
    echo '<p class="vd-admin-note">Outbound mail for the contact form, receipts '
        . 'and password resets. Until this is switched on, WordPress uses the '
        . 'host&rsquo;s own <code>mail()</code>, which frequently lands in spam.</p>';

    echo '<h2>1. Create the mailbox (you, not the plugin)</h2>'
        . '<ol class="vd-admin-note">'
        . '<li>Sign up at <code>zoho.com/mail</code> &rarr; <b>Forever Free Plan</b> '
        . '&rarr; &ldquo;Sign up with a domain I already own&rdquo;, and enter '
        . '<code>vendicator.co.uk</code>.</li>'
        . '<li>Verify the domain with the TXT record Zoho gives you, then add the '
        . 'DNS records in the table below at your registrar.</li>'
        . '<li>Create the mailbox <code>contact@vendicator.co.uk</code>.</li>'
        . '<li>In Zoho, turn on two-factor authentication and generate an '
        . '<b>application-specific password</b> for SMTP &mdash; paste that below, '
        . 'never your main account password.</li>'
        . '<li>Optional, same login, still free: <b>Zoho Desk</b> (3 agents) turns '
        . 'the contact form into a ticket queue, and <b>Zoho Invoice</b> (unlimited, '
        . 'free) issues subscription invoices.</li></ol>';

    echo '<h2>2. DNS records</h2><table class="widefat" style="max-width:820px;">'
        . '<tr><th>Type</th><th>Host</th><th>Value</th><th>Why</th></tr>'
        . '<tr><td>MX</td><td>@</td><td>mx.zoho.eu (priority 10)</td><td rowspan="3">'
        . 'Delivers mail addressed to your domain</td></tr>'
        . '<tr><td>MX</td><td>@</td><td>mx2.zoho.eu (priority 20)</td></tr>'
        . '<tr><td>MX</td><td>@</td><td>mx3.zoho.eu (priority 50)</td></tr>'
        . '<tr><td>TXT</td><td>@</td><td>v=spf1 include:zoho.eu ~all</td>'
        . '<td>SPF &mdash; authorises Zoho to send as you</td></tr>'
        . '<tr><td>TXT</td><td>zmail._domainkey</td><td><i>the key Zoho shows you</i></td>'
        . '<td>DKIM &mdash; signs your mail so it is not spoofable</td></tr>'
        . '<tr><td>TXT</td><td>_dmarc</td>'
        . '<td>v=DMARC1; p=quarantine; rua=mailto:contact@vendicator.co.uk</td>'
        . '<td>DMARC &mdash; tells receivers what to do with fakes</td></tr>'
        . '</table><p class="vd-admin-note">Use the <code>.com</code> hostnames '
        . '(<code>mx.zoho.com</code>, <code>include:zoho.com</code>) if you register '
        . 'on the US data centre rather than the EU one.</p>';

    echo '<h2>3. Connect it</h2><form method="post" action="'
        . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_save_mail">'
        . wp_nonce_field('vendicator_save_mail', '_vdnonce', true, false)
        . '<table class="form-table">'
        . '<tr><th>Route mail through SMTP</th><td><label><input type="checkbox" '
        . 'name="enabled" value="1" ' . checked(!empty($m['enabled']), true, false)
        . '> Enabled</label></td></tr>'
        . '<tr><th>SMTP host</th><td><input type="text" name="host" value="'
        . esc_attr($m['host']) . '" class="regular-text"></td></tr>'
        . '<tr><th>Port</th><td><input type="number" name="port" value="'
        . (int) $m['port'] . '"> <select name="secure">'
        . '<option value="ssl" ' . selected($m['secure'], 'ssl', false) . '>SSL (465)</option>'
        . '<option value="tls" ' . selected($m['secure'], 'tls', false) . '>TLS (587)</option>'
        . '</select></td></tr>'
        . '<tr><th>Username</th><td><input type="text" name="user" value="'
        . esc_attr($m['user']) . '" class="regular-text"></td></tr>'
        . '<tr><th>App password</th><td><input type="password" name="pass" value="" '
        . 'class="regular-text" autocomplete="new-password" placeholder="'
        . ($m['pass'] ? 'saved - leave blank to keep' : 'paste the app password')
        . '"><p class="description">Stored in the WordPress options table. Use a '
        . 'Zoho application-specific password, not your login password.</p></td></tr>'
        . '<tr><th>From address</th><td><input type="email" name="from" value="'
        . esc_attr($m['from']) . '" class="regular-text"> as <input type="text" '
        . 'name="from_name" value="' . esc_attr($m['from_name']) . '"></td></tr>'
        . '</table><p><input type="submit" class="button button-primary" '
        . 'value="Save mail settings"></p></form>';

    echo '<h2>4. Send a test</h2><form method="post" action="'
        . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_test_mail">'
        . wp_nonce_field('vendicator_test_mail', '_vdnonce', true, false)
        . '<p><input type="email" name="to" class="regular-text" value="'
        . esc_attr(get_option('admin_email')) . '"> '
        . '<input type="submit" class="button" value="Send test message"></p></form>';

    $log = (array) get_option('vendicator_contact_log', array());
    echo '<h2>Recent contact messages (' . count($log) . ')</h2>'
        . '<table class="widefat"><tr><th>When</th><th>From</th><th>Topic</th>'
        . '<th>Delivered</th></tr>';
    foreach (array_slice($log, -20) as $row) {
        echo '<tr><td>' . esc_html(substr($row['ts'], 0, 16)) . '</td><td>'
            . esc_html($row['name'] . ' <' . $row['email'] . '>') . '</td><td>'
            . esc_html($row['topic']) . '</td><td>'
            . (empty($row['delivered']) ? '&#10007;' : '&#10003;') . '</td></tr>';
    }
    if (!$log) { echo '<tr><td colspan="4">No messages yet.</td></tr>'; }
    echo '</table></div>';
}

add_action('admin_post_vendicator_save_mail', function () {
    check_admin_referer('vendicator_save_mail', '_vdnonce');
    if (!current_user_can('manage_options')) { wp_die('Denied'); }
    $cur = vendicator_mail_settings();
    $pass = (string) wp_unslash($_POST['pass'] ?? '');
    update_option('vendicator_mail', array(
        'enabled' => empty($_POST['enabled']) ? 0 : 1,
        'host' => sanitize_text_field(wp_unslash($_POST['host'] ?? '')),
        'port' => (int) ($_POST['port'] ?? 465),
        'secure' => in_array($_POST['secure'] ?? 'ssl', array('ssl', 'tls'), true)
            ? $_POST['secure'] : 'ssl',
        'user' => sanitize_text_field(wp_unslash($_POST['user'] ?? '')),
        // blank means "keep what is already stored" - so the saved password
        // is never echoed back into the page to be re-submitted
        'pass' => $pass !== '' ? $pass : $cur['pass'],
        'from' => sanitize_email(wp_unslash($_POST['from'] ?? '')),
        'from_name' => sanitize_text_field(wp_unslash($_POST['from_name'] ?? '')),
    ));
    wp_safe_redirect(admin_url('admin.php?page=vendicator-mail'));
    exit;
});

add_action('admin_post_vendicator_test_mail', function () {
    check_admin_referer('vendicator_test_mail', '_vdnonce');
    if (!current_user_can('manage_options')) { wp_die('Denied'); }
    $to = sanitize_email(wp_unslash($_POST['to'] ?? ''));
    $ok = $to && wp_mail($to, 'Vendicator test message',
        "This is a test from the Vendicator Mail & Support panel.\n\n"
        . "If you are reading it, outbound mail is working.\n");
    wp_safe_redirect(admin_url('admin.php?page=vendicator-mail&vd_test='
        . ($ok ? '1' : '0')));
    exit;
});

/* -------------------------------------------------------------- admin panel */

add_action('admin_menu', function () {
    add_menu_page('Vendicator', 'Vendicator', 'manage_options', 'vendicator',
        'vendicator_admin_models', 'dashicons-chart-line', 3);
    add_submenu_page('vendicator', 'Model Selectors', 'Model Selectors',
        'manage_options', 'vendicator', 'vendicator_admin_models');
    add_submenu_page('vendicator', 'Leagues', 'Leagues',
        'manage_options', 'vendicator-leagues', 'vendicator_admin_leagues');
    add_submenu_page('vendicator', 'Match Markets', 'Match Markets',
        'manage_options', 'vendicator-markets', 'vendicator_admin_markets');
    add_submenu_page('vendicator', 'Mail &amp; Support', 'Mail &amp; Support',
        'manage_options', 'vendicator-mail', 'vendicator_admin_mail');
    add_submenu_page('vendicator', 'Subscriptions', 'Subscriptions',
        'manage_options', 'vendicator-subs', 'vendicator_admin_subs');
    add_submenu_page('vendicator', 'Users', 'Users',
        'manage_options', 'vendicator-users', 'vendicator_admin_users');
    add_submenu_page('vendicator', 'Reward Packages', 'Reward Packages',
        'manage_options', 'vendicator-rewards', 'vendicator_admin_rewards');
    add_submenu_page('vendicator', 'Infrastructure Upgrades', 'Infrastructure Upgrades',
        'manage_options', 'vendicator-infra', 'vendicator_admin_infra');
    add_submenu_page('vendicator', 'Custom Sections', 'Custom Sections',
        'manage_options', 'vendicator-sections', 'vendicator_admin_sections');
});

function vendicator_admin_models() {
    $sel = get_option('vendicator_model_selectors', array());
    echo '<div class="wrap"><h1>Vendicator — Model Selectors</h1>'
        . '<p class="vd-admin-note">Pick which engine generates each site section. '
        . 'Locked models list what they need to unlock.</p>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_save_selectors">';
    wp_nonce_field('vendicator_save_selectors', '_vdnonce');
    echo '<table class="widefat striped" style="max-width:700px;">'
        . '<thead><tr><th>Section</th><th>Model</th></tr></thead><tbody>';
    foreach (vendicator_sections() as $slug => $label) {
        echo '<tr><td>' . esc_html($label) . '</td><td><select name="sel[' . esc_attr($slug) . ']">';
        foreach (vendicator_models() as $mslug => $m) {
            $locked = $m[1];
            printf('<option value="%s" %s %s>%s</option>',
                esc_attr($mslug),
                selected(isset($sel[$slug]) ? $sel[$slug] : 'ensemble', $mslug, false),
                $locked ? 'disabled class="vd-admin-lock"' : '',
                esc_html($m[0] . ($locked ? ' [locked]' : '')));
        }
        echo '</select></td></tr>';
    }
    echo '</tbody></table><p><input type="submit" class="button button-primary" value="Save selectors"></p></form></div>';
}
add_action('admin_post_vendicator_save_selectors', function () {
    check_admin_referer('vendicator_save_selectors', '_vdnonce');
    if (current_user_can('manage_options')) {
        $models = vendicator_models();
        $clean = array();
        foreach ((array) wp_unslash($_POST['sel'] ?? array()) as $k => $v) {
            if (isset($models[$v]) && !$models[$v][1]) { $clean[sanitize_key($k)] = sanitize_key($v); }
        }
        update_option('vendicator_model_selectors', $clean);
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator'));
    exit;
});

function vendicator_admin_subs() {
    $tiers = vendicator_tiers();
    echo '<div class="wrap"><h1>Subscriptions</h1>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_save_tiers">';
    wp_nonce_field('vendicator_save_tiers', '_vdnonce');
    echo '<p class="vd-admin-note">Thresholds are stored as whole numbers and '
        . 'shown to members in decimal-odds form, so 1000 here reads as 10.00 '
        . 'on the site.</p>'
        . '<table class="widefat striped" style="max-width:820px;"><thead><tr>'
        . '<th>Tier</th><th>Lifetime points required</th><th>Members see</th>'
        . '<th>Benefits</th></tr></thead><tbody>';
    foreach ($tiers as $slug => $cfg) {
        printf('<tr><td>%s</td><td><input type="number" name="pts[%s]" value="%d"></td>'
            . '<td>%s</td>'
            . '<td><input type="text" style="width:100%%" name="ben[%s]" value="%s"></td></tr>',
            esc_html($cfg['label']), esc_attr($slug), (int) $cfg['points'],
            esc_html(vendicator_pts($cfg['points'])),
            esc_attr($slug), esc_attr($cfg['benefits']));
    }
    echo '</tbody></table><p><input type="submit" class="button button-primary" value="Save tiers"></p></form>';
    echo '<h2>Members by tier</h2><table class="widefat striped" style="max-width:600px;"><tbody>';
    foreach (get_users() as $u) {
        printf('<tr><td>%s</td><td>%s</td><td>%s pts</td></tr>',
            esc_html($u->user_login), esc_html(vendicator_user_tier($u->ID)),
            esc_html(vendicator_pts(get_user_meta($u->ID, 'vendicator_lifetime_points', true))));
    }
    echo '</tbody></table></div>';
}
add_action('admin_post_vendicator_save_tiers', function () {
    check_admin_referer('vendicator_save_tiers', '_vdnonce');
    if (current_user_can('manage_options')) {
        $tiers = vendicator_tiers();
        foreach ($tiers as $slug => $cfg) {
            $tiers[$slug]['points'] = (int) ($_POST['pts'][$slug] ?? $cfg['points']);
            $tiers[$slug]['benefits'] = sanitize_text_field(
                wp_unslash($_POST['ben'][$slug] ?? $cfg['benefits']));
        }
        update_option('vendicator_subscription_tiers', $tiers);
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator-subs'));
    exit;
});

function vendicator_admin_users() {
    echo '<div class="wrap"><h1>Users</h1><table class="widefat striped" style="max-width:820px;">'
        . '<thead><tr><th>User</th><th>Email</th><th>Tier</th><th>Points</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach (get_users() as $u) {
        $banned = (bool) get_user_meta($u->ID, 'vendicator_banned', true);
        $url = wp_nonce_url(admin_url('admin-post.php?action=vendicator_ban&uid=' . $u->ID
            . '&to=' . ($banned ? 0 : 1)), 'vendicator_ban', '_vdnonce');
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>'
            . '<td><a class="button" href="%s">%s</a></td></tr>',
            esc_html($u->user_login), esc_html($u->user_email),
            esc_html(vendicator_user_tier($u->ID)),
            esc_html(vendicator_pts(get_user_meta($u->ID, 'vendicator_points_balance', true))),
            $banned ? '<b style="color:#c00;">banned</b>' : 'active',
            esc_url($url), $banned ? 'Unban' : 'Ban');
    }
    echo '</tbody></table></div>';
}
add_action('admin_post_vendicator_ban', function () {
    check_admin_referer('vendicator_ban', '_vdnonce');
    $uid = (int) ($_GET['uid'] ?? 0);
    if (current_user_can('manage_options') && $uid
        && !user_can($uid, 'manage_options')) {
        update_user_meta($uid, 'vendicator_banned', (int) ($_GET['to'] ?? 1));
        if (!empty($_GET['to'])) {
            $sessions = WP_Session_Tokens::get_instance($uid);
            $sessions->destroy_all();
        }
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator-users'));
    exit;
});

function vendicator_admin_rewards() {
    $packages = get_option('vendicator_reward_packages', array());
    echo '<div class="wrap"><h1>Reward Packages</h1>'
        . '<table class="widefat striped" style="max-width:820px;"><thead><tr>'
        . '<th>Package</th><th>Points cost</th><th>Description</th><th></th></tr></thead><tbody>';
    foreach ((array) $packages as $i => $pkg) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=vendicator_del_package&i=' . $i),
            'vendicator_del_package', '_vdnonce');
        printf('<tr><td>%s</td><td>%d</td><td>%s</td><td><a class="button" href="%s">Delete</a></td></tr>',
            esc_html($pkg['name']), (int) $pkg['cost'], esc_html($pkg['desc']), esc_url($url));
    }
    echo '</tbody></table><h2>Add package</h2>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_add_package">';
    wp_nonce_field('vendicator_add_package', '_vdnonce');
    echo '<p><input type="text" name="name" placeholder="Package name" required> '
        . '<input type="number" name="cost" placeholder="Points cost" required> '
        . '<input type="text" size="40" name="desc" placeholder="Description"> '
        . '<input type="submit" class="button button-primary" value="Add"></p></form></div>';
}
add_action('admin_post_vendicator_add_package', function () {
    check_admin_referer('vendicator_add_package', '_vdnonce');
    if (current_user_can('manage_options')) {
        $packages = (array) get_option('vendicator_reward_packages', array());
        $packages[] = array(
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'cost' => (int) ($_POST['cost'] ?? 0),
            'desc' => sanitize_text_field(wp_unslash($_POST['desc'] ?? '')));
        update_option('vendicator_reward_packages', array_values($packages));
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator-rewards'));
    exit;
});
add_action('admin_post_vendicator_del_package', function () {
    check_admin_referer('vendicator_del_package', '_vdnonce');
    if (current_user_can('manage_options')) {
        $packages = (array) get_option('vendicator_reward_packages', array());
        unset($packages[(int) ($_GET['i'] ?? -1)]);
        update_option('vendicator_reward_packages', array_values($packages));
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator-rewards'));
    exit;
});

function vendicator_admin_infra() {
    echo '<div class="wrap"><h1>Infrastructure Upgrades</h1>'
        . '<p class="vd-admin-note">The engine currently runs the VPS-free architecture. '
        . 'Each upgrade below is wired into the plan and unlocks when its requirement is met '
        . '- nothing here is lost, it is simply waiting for scale.</p>'
        . '<table class="widefat striped" style="max-width:820px;"><tbody>';
    foreach (vendicator_infra_items() as $item) {
        printf('<tr class="vd-admin-lock"><td><label><input type="checkbox" disabled> %s</label></td>'
            . '<td><em>%s</em></td></tr>',
            esc_html($item[0]), esc_html($item[1]));
    }
    echo '</tbody></table></div>';
}

function vendicator_admin_sections() {
    echo '<div class="wrap"><h1>Custom Sections</h1>'
        . '<p class="vd-admin-note">Add future site sections here - each appears in the '
        . 'Model Selectors list and on the dashboard automatically.</p>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_add_section">';
    wp_nonce_field('vendicator_add_section', '_vdnonce');
    echo '<p><input type="text" name="slug" placeholder="slug (e.g. handicaps)" required> '
        . '<input type="text" name="label" placeholder="Label (e.g. Asian Handicap)" required> '
        . '<input type="submit" class="button button-primary" value="Add section"></p></form>'
        . '<table class="widefat striped" style="max-width:500px;"><tbody>';
    foreach ((array) get_option('vendicator_custom_sections', array()) as $slug => $label) {
        printf('<tr><td>%s</td><td>%s</td></tr>', esc_html($slug), esc_html($label));
    }
    echo '</tbody></table></div>';
}
add_action('admin_post_vendicator_add_section', function () {
    check_admin_referer('vendicator_add_section', '_vdnonce');
    if (current_user_can('manage_options')) {
        $sections = (array) get_option('vendicator_custom_sections', array());
        $slug = sanitize_key(wp_unslash($_POST['slug'] ?? ''));
        if ($slug) {
            $sections[$slug] = sanitize_text_field(wp_unslash($_POST['label'] ?? $slug));
            update_option('vendicator_custom_sections', $sections);
        }
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator-sections'));
    exit;
});

function vendicator_admin_markets() {
    $sections = vendicator_sections();
    $leagues = vendicator_leagues();
    $map = (array) get_option('vendicator_league_sections', array());
    $stats = (array) get_option('vendicator_section_stats', array());
    $cur = isset($_GET['lg']) ? sanitize_text_field(wp_unslash($_GET['lg'])) : '_default';
    $chosen = isset($map[$cur]) ? (array) $map[$cur] : vendicator_league_sections($cur);

    echo '<div class="wrap"><h1>Match Markets</h1>'
        . '<p class="vd-admin-note">Choose which market sections appear for each '
        . 'competition. Champions League nights can lead with Half Time / Full Time '
        . 'while league nights lead with player markets. The counters show what your '
        . 'members actually pick, so the model can follow real behaviour.</p>'
        . '<form method="get" style="margin-bottom:14px;">'
        . '<input type="hidden" name="page" value="vendicator-markets">'
        . '<select name="lg" onchange="this.form.submit()">'
        . '<option value="_default" ' . selected($cur, '_default', false)
        . '>All competitions (default)</option>';
    foreach ($leagues as $code => $label) {
        echo '<option value="' . esc_attr($code) . '" ' . selected($cur, $code, false)
            . '>' . esc_html($label) . '</option>';
    }
    echo '</select></form>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_save_markets">'
        . '<input type="hidden" name="lg" value="' . esc_attr($cur) . '">';
    wp_nonce_field('vendicator_save_markets', '_vdnonce');
    echo '<table class="widefat striped" style="max-width:720px;"><thead><tr>'
        . '<th>Show</th><th>Market section</th><th>Member picks logged</th>'
        . '</tr></thead><tbody>';
    foreach ($sections as $slug => $label) {
        $count = isset($stats[$cur][$slug]) ? (int) $stats[$cur][$slug] : 0;
        printf('<tr><td><input type="checkbox" name="sec[]" value="%s" %s></td>'
            . '<td>%s</td><td>%d</td></tr>',
            esc_attr($slug), checked(in_array($slug, $chosen, true), true, false),
            wp_kses_post($label), $count);
    }
    echo '</tbody></table><p><input type="submit" class="button button-primary" '
        . 'value="Save market layout"></p></form></div>';
}
add_action('admin_post_vendicator_save_markets', function () {
    check_admin_referer('vendicator_save_markets', '_vdnonce');
    if (current_user_can('manage_options')) {
        $sections = vendicator_sections();
        $lg = sanitize_text_field(wp_unslash($_POST['lg'] ?? '_default'));
        $clean = array();
        foreach ((array) wp_unslash($_POST['sec'] ?? array()) as $s) {
            $s = sanitize_key($s);
            if (isset($sections[$s])) { $clean[] = $s; }
        }
        $map = (array) get_option('vendicator_league_sections', array());
        $map[$lg] = $clean;
        update_option('vendicator_league_sections', $map);
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator-markets&lg='
        . rawurlencode(sanitize_text_field(wp_unslash($_POST['lg'] ?? '_default')))));
    exit;
});

function vendicator_admin_leagues() {
    $cat = vendicator_league_catalogue();
    $enabled = array_keys(vendicator_leagues());
    echo '<div class="wrap"><h1>Leagues &amp; Competitions</h1>'
        . '<p class="vd-admin-note">Separate from the model engines: enable competitions here and the '
        . 'site and data pipeline pick them up on the next run. Add competitions from any country or '
        . 'continent as the platform grows.</p>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_save_leagues">';
    wp_nonce_field('vendicator_save_leagues', '_vdnonce');
    echo '<table class="widefat striped" style="max-width:720px;"><thead><tr>'
        . '<th>Enabled</th><th>Competition</th><th>Country</th><th>Type</th></tr></thead><tbody>';
    foreach ($cat as $code => $cfg) {
        printf('<tr><td><input type="checkbox" name="lg[]" value="%s" %s></td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_attr($code), checked(in_array($code, $enabled, true), true, false),
            esc_html($cfg[0]), esc_html($cfg[1]), esc_html($cfg[2]));
    }
    echo '</tbody></table><p><input type="submit" class="button button-primary" value="Save leagues"></p></form>'
        . '<h2>Add a custom competition</h2>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vendicator_add_league">';
    wp_nonce_field('vendicator_add_league', '_vdnonce');
    echo '<p><input type="text" name="code" placeholder="Code (e.g. MX1)" required> '
        . '<input type="text" name="name" placeholder="Competition name" required> '
        . '<input type="text" name="country" placeholder="Country / continent" required> '
        . '<input type="submit" class="button button-primary" value="Add"></p></form>';
    $custom = (array) get_option('vendicator_custom_leagues', array());
    if ($custom) {
        echo '<table class="widefat striped" style="max-width:500px;"><tbody>';
        foreach ($custom as $code => $cfg) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($code), esc_html($cfg[0]), esc_html($cfg[1]));
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}
add_action('admin_post_vendicator_save_leagues', function () {
    check_admin_referer('vendicator_save_leagues', '_vdnonce');
    if (current_user_can('manage_options')) {
        $cat = vendicator_league_catalogue();
        $clean = array();
        foreach ((array) wp_unslash($_POST['lg'] ?? array()) as $code) {
            $code = sanitize_text_field($code);
            if (isset($cat[$code])) { $clean[] = $code; }
        }
        update_option('vendicator_enabled_leagues', $clean);
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator-leagues'));
    exit;
});
add_action('admin_post_vendicator_add_league', function () {
    check_admin_referer('vendicator_add_league', '_vdnonce');
    if (current_user_can('manage_options')) {
        $code = strtoupper(sanitize_key(wp_unslash($_POST['code'] ?? '')));
        if ($code) {
            $custom = (array) get_option('vendicator_custom_leagues', array());
            $custom[$code] = array(
                sanitize_text_field(wp_unslash($_POST['name'] ?? $code)),
                sanitize_text_field(wp_unslash($_POST['country'] ?? '')),
                'league');
            update_option('vendicator_custom_leagues', $custom);
        }
    }
    wp_safe_redirect(admin_url('admin.php?page=vendicator-leagues'));
    exit;
});
