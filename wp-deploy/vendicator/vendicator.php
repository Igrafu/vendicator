<?php
/**
 * Plugin Name: Vendicator
 * Description: Football prediction engine front end - predictions dashboard, accounts, points, subscriptions, rewards, and the Vendicator admin control panel.
 * Version: 0.1.0
 * Author: Vendicator
 */

if (!defined('ABSPATH')) { exit; }

/* ---------------------------------------------------------- configuration */

function vendicator_sections() {
    $custom = get_option('vendicator_custom_sections', array());
    $core = array(
        '1x2' => '1X2 Match Result', 'double_chance' => 'Double Chance',
        'btts' => 'BTTS', 'over_under' => 'Over / Under',
        'exact_score' => 'Exact Score', 'total_shots' => 'Total Shots',
        'top_scorers' => 'Top Scorers', 'market_view' => 'Market View',
    );
    foreach ((array) $custom as $slug => $label) { $core[$slug] = $label; }
    return $core;
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
        'free' => array('points' => 0, 'label' => 'Free',
            'benefits' => 'T3 lower-league predictions, 1X2 + BTTS, single-bookmaker odds'),
        'bronze' => array('points' => 1000, 'label' => 'Bronze',
            'benefits' => 'T2 leagues, over/under markets, daily value pick'),
        'silver' => array('points' => 5000, 'label' => 'Silver',
            'benefits' => 'T1 leagues, exact score + handicaps, uncertainty bands, odds comparison, themes'),
        'gold' => array('points' => 15000, 'label' => 'Gold',
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
        array('points' => 0, 'name' => 'Rookie', 'icon' => '⚽'),
        array('points' => 500, 'name' => 'Contender', 'icon' => '🛡️'),
        array('points' => 2000, 'name' => 'Sharp', 'icon' => '🎯'),
        array('points' => 5000, 'name' => 'Analyst', 'icon' => '📊'),
        array('points' => 10000, 'name' => 'Oracle', 'icon' => '🔮'),
        array('points' => 20000, 'name' => 'Legend', 'icon' => '👑'),
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
            $points = $won ? (int) round(100 * $difficulty) : 0;
            $streak = $won ? $streak + 1 : 0;
            if ($streak === 3) { $points += 150; }
            elseif ($streak === 5) { $points += 400; }
            elseif ($streak === 10) { $points += 1500; }
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
    }
    return $count;
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
.vd-clock{font-size:40px;font-weight:800;letter-spacing:3px;color:var(--lime);
text-shadow:0 0 22px rgba(198,255,77,.5);font-variant-numeric:tabular-nums;}
.vd-clock small{font-size:22px;opacity:.75;}
.vd-ticker{margin-top:12px;border:1px solid var(--edge);border-radius:12px;
background:rgba(255,255,255,.05);padding:12px 16px;position:relative;
display:flex;align-items:center;gap:12px;min-height:52px;cursor:pointer;}
.vd-tick-code{font-weight:800;letter-spacing:2px;color:var(--white);}
.vd-tick-odds{color:var(--mint);font-variant-numeric:tabular-nums;}
.vd-tick-soon{color:var(--lime);font-weight:700;}
.vd-tick-live{color:#FF6B6B;font-weight:700;}
.vd-ticker .vd-hover{display:none;position:absolute;left:0;top:56px;z-index:9;
width:min(460px,90vw);background:rgba(16,19,25,.98);border:1px solid rgba(198,255,77,.4);
border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.55;
box-shadow:0 0 36px rgba(198,255,77,.18),0 20px 44px rgba(0,0,0,.65);}
.vd-ticker:hover .vd-hover{display:block;}
.vd-teamlink{color:var(--white);text-decoration:none;display:inline-flex;
align-items:center;gap:8px;position:relative;}
.vd-teambadge{width:30px;height:30px;object-fit:contain;
filter:drop-shadow(0 0 7px rgba(198,255,77,.3));}
.vd-teamlink .tip2{display:none;position:absolute;top:36px;left:0;z-index:9;
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
.vendicator-page h1:not([class]),.vendicator-page .entry-header{display:none;}';
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
    if (is_page(array('login', 'dashboard', 'account', 'team'))) {
        $classes[] = 'vendicator-page';
    }
    return $classes;
});

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
    $data = array(
        'fixtures' => isset($p['fixtures']) ? $p['fixtures'] : array($p),
        'tables' => isset($p['tables']) ? $p['tables'] : array(),
    );
    $js = <<<'JS'
(function(){
function pad(n){return (n<10?"0":"")+n;}
setInterval(function(){var d=new Date();var el=document.getElementById("vd-clock");
if(el)el.innerHTML=pad(d.getHours())+":"+pad(d.getMinutes())+":<small>"+pad(d.getSeconds())+"</small>"
+' <span style="font-size:12px;color:#9AA3B2;">'+d.toLocaleDateString()+"</span>";},250);
var fx=(window.VD&&VD.fixtures)||[];var idx=0;
function parseKick(k){var m=k&&k.match(/(\d+)\/(\d+)\/(\d+)\s+(\d+):(\d+)/);
return m?new Date(+m[3],m[2]-1,+m[1],+m[4],+m[5]):null;}
function tag(f){var kick=parseKick(f.kickoff);if(!kick)return "";
var mins=Math.round((kick-new Date())/60000);
if(mins<=0&&mins>-130)return '<span class="vd-tick-live">&#9679; in play window</span>';
if(mins>0&&mins<=60)return '<span class="vd-tick-soon">&#9200; starts in '+mins+'m</span>';
if(mins>0)return '<span class="vd-muted">'+f.kickoff+'</span>';
return '<span class="vd-muted">finished</span>';}
function fmt(f){var odds=f.odds_board?["home","draw","away"].map(function(k){
var o=f.odds_board[k]&&f.odds_board[k][0];return o?o.odds:"-";}).join(" | "):"";
return '<span class="vd-tick-code">'+(f.short||f.fixture)+'</span> '+tag(f)
+' <span class="vd-tick-odds">'+odds+'</span>';}
function hover(f){var fc=f.final_calibrated;
var fav=fc.home>=fc.draw&&fc.home>=fc.away?["home win",fc.home]:(fc.away>=fc.draw&&fc.away>=fc.home?["away win",fc.away]:["draw",fc.draw]);
var top=f.markets_dixon_coles.exact_score_top10[0];
return "<b>"+f.fixture+"</b><br>The model favours the "+fav[0]+" at <b>"+fav[1]
+"%</b>, driven by expected goals "+f.expected_goals.home+" - "+f.expected_goals.away
+". Most likely scoreline <b>"+top[0]+"</b> ("+top[1]+"%). 90% band on the home win: "
+f.uncertainty_band_home_pct.join("-")+"%. Reward difficulty x"+f.reward_difficulty_multiplier+".";}
function tick(){if(!fx.length)return;var f=fx[idx%fx.length];
var it=document.getElementById("vd-tick-item");var hv=document.getElementById("vd-hover");
if(it)it.innerHTML=fmt(f);if(hv)hv.innerHTML=hover(f);idx++;}
tick();setInterval(tick,5000);
var tables=(window.VD&&VD.tables)||{};
function trow(team){for(var d in tables){var t=tables[d];
for(var j=0;j<t.length;j++){if(t[j].team===team)return {pos:j+1,div:d,r:t[j]};}}return null;}
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
            . ($won ? ' &mdash; +' . (int) $b['points'] . ' points' : '')
            . '</div>';
    }
    return $out ? '<div class="vd-toasts">' . $out . '</div>' : '';
}

/* --------------------------------------------------------------- shortcodes */

function vendicator_page_url($slug) {
    $p = get_page_by_path($slug);
    return $p ? get_permalink($p) : home_url('/');
}

add_shortcode('vendicator_login', function () {
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        return '<div class="vd-wrap"><div class="vd-inner"><div class="vd-card">'
            . '<div class="vd-logo">VENDI<b>CATOR</b></div>'
            . '<p>Welcome back, <b>' . esc_html($u->display_name) . '</b>.</p>'
            . '<p><a class="vd-btn" href="' . esc_url(vendicator_page_url('dashboard')) . '">Open predictions</a> '
            . '<a class="vd-btn" href="' . esc_url(vendicator_page_url('account')) . '">My account</a> '
            . '<a class="vd-btn" href="' . esc_url(wp_logout_url(home_url('/'))) . '">Log out</a></p>'
            . '</div></div></div>';
    }
    $err = isset($_GET['vd_error']) ? sanitize_text_field(wp_unslash($_GET['vd_error'])) : '';
    $out = '<div class="vd-wrap"><div class="vd-inner">'
        . '<div class="vd-card" style="text-align:center;">'
        . '<div class="vd-logo">VENDI<b>CATOR</b></div>'
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
        . '</form></div></div></div></div>';
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
        'bronze' => array('1x2', 'btts', 'double_chance', 'over_under'),
        'silver' => array('1x2', 'btts', 'double_chance', 'over_under', 'exact_score', 'market_view'),
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
        . '<div class="vd-card"><div class="vd-logo">VENDI<b>CATOR</b></div>'
        . '<p class="vd-muted">Your tier: <span class="vd-pill">' . esc_html(strtoupper($tier))
        . '</span> unlocks: ' . esc_html(implode(', ', $allowed)) . '</p></div>';
    if (!$p) {
        return $out . '<div class="vd-card">No predictions published yet - '
            . 'the engine pushes the next matchday payload automatically.</div></div></div>';
    }
    $out .= '<div class="vd-card"><div id="vd-clock" class="vd-clock">--:--:--</div>'
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
    $shown = 0;
    foreach ($list as $fx) {
        if (isset($picked[$fx['fixture']])) { continue; }
        $out .= vendicator_render_fixture($fx, $allowed, $sel);
        $shown++;
    }
    if (!$shown) {
        $out .= '<div class="vd-card"><h3>All caught up</h3><p class="vd-muted">'
            . 'You have made a pick on every open fixture. They are logged in '
            . '<a class="vd-btn" href="' . esc_url(add_query_arg('tab', 'betting', vendicator_page_url('account')))
            . '">Betting History</a></p></div>';
    }
    $out .= '</div></div>' . vendicator_toasts() . vendicator_dashboard_js($p);
    return $out;
});

function vendicator_render_fixture($p, $allowed, $sel) {
    $f = $p['final_calibrated'];
    $dc = $p['markets_dixon_coles'];
    $out = '<div class="vd-card"><h2>' . vendicator_fixture_heading($p) . '</h2>'
        . '<p class="vd-muted">' . esc_html($p['league'])
        . (empty($p['kickoff']) ? '' : ' - kickoff ' . esc_html($p['kickoff']))
        . ' - expected goals '
        . esc_html($p['expected_goals']['home'] . ' - ' . $p['expected_goals']['away']) . '</p>'
        . '<div class="vd-bar">'
        . '<div class="vd-h" style="flex:' . floatval($f['home']) . '">' . floatval($f['home']) . '%</div>'
        . '<div class="vd-d" style="flex:' . floatval($f['draw']) . '">' . floatval($f['draw']) . '%</div>'
        . '<div class="vd-a" style="flex:' . floatval($f['away']) . '">' . floatval($f['away']) . '%</div>'
        . '</div><p class="vd-muted">Calibrated ensemble - 90% band on home win: '
        . esc_html(implode('-', (array) $p['uncertainty_band_home_pct'])) . '%'
        . ' - difficulty x' . esc_html($p['reward_difficulty_multiplier']) . '</p></div>'
        . '<div class="vd-grid">';
    $alt = array();
    foreach (array('0.5', '1.5', '2.5', '3.5', '4.5') as $line) {
        if (isset($dc['totals']['over_' . $line])) {
            $alt['Over ' . $line] = $dc['totals']['over_' . $line];
            $alt['Under ' . $line] = $dc['totals']['under_' . $line];
        }
    }
    $cards = array(
        '1x2' => array('1X2', array('Home' => $dc['1x2']['home'], 'Draw' => $dc['1x2']['draw'], 'Away' => $dc['1x2']['away'])),
        'double_chance' => array('xTotal Alternative Goals', $alt),
        'over_under' => array('Over / Under', array('Over 1.5' => $dc['totals']['over_1.5'], 'Over 2.5' => $dc['totals']['over_2.5'], 'Under 2.5' => $dc['totals']['under_2.5'])),
    );
    foreach ($cards as $slug => $card) {
        if (!in_array($slug, $allowed, true)) { continue; }
        $model = isset($sel[$slug]) ? $sel[$slug] : 'ensemble';
        $out .= '<div class="vd-card"><h3>' . esc_html($card[0])
            . ' <span class="vd-muted">(' . esc_html($model) . ')</span></h3><table class="vd-table">';
        foreach ($card[1] as $k => $v) {
            $out .= '<tr><td>' . esc_html($k) . '</td><td>' . floatval($v) . '%</td></tr>';
        }
        $out .= '</table></div>';
    }
    if (in_array('btts', $allowed, true) && !empty($p['team_to_score'])) {
        $t = $p['team_to_score'];
        $names = explode(' vs ', $p['fixture']);
        $out .= '<div class="vd-card"><h3>Best Team To Score</h3>'
            . '<p style="font-size:19px;font-weight:800;color:#C6FF4D;margin:0 0 8px;">'
            . esc_html($t['best_team']) . '</p><table class="vd-table">'
            . '<tr><td>' . esc_html($names[0]) . ' to score</td><td>' . floatval($t['home_pct'])
            . '% @ ' . esc_html($t['fair_odds']['home']) . '</td></tr>'
            . '<tr><td>' . esc_html(isset($names[1]) ? $names[1] : 'Away') . ' to score</td><td>'
            . floatval($t['away_pct']) . '% @ ' . esc_html($t['fair_odds']['away']) . '</td></tr>'
            . '<tr><td>Both teams score</td><td>' . floatval($dc['btts']['yes']) . '%</td></tr>'
            . '</table><p class="vd-muted" style="font-size:12px;">Fair odds from the model\'s '
            . 'scoreline grid (1 / probability).</p></div>';
    }
    if (!empty($p['odds_board'])) {
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
    if (in_array('exact_score', $allowed, true)) {
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
    return $out . '</div>';
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

add_action('admin_post_vendicator_ack', function () {
    check_admin_referer('vendicator_ack', '_vdnonce');
    $uid = get_current_user_id();
    $i = isset($_GET['i']) ? (int) $_GET['i'] : -1;
    if ($uid && $i >= 0) {
        $bets = json_decode((string) get_user_meta($uid, 'vendicator_bets', true), true);
        if (isset($bets[$i])) {
            $bets[$i]['ack'] = true;
            update_user_meta($uid, 'vendicator_bets', wp_json_encode($bets));
        }
    }
    wp_safe_redirect(wp_get_referer() ?: vendicator_page_url('dashboard'));
    exit;
});

add_shortcode('vendicator_team', function () {
    if (!is_user_logged_in()) {
        return '<div class="vd-wrap"><div class="vd-inner"><div class="vd-card"><p>Please '
            . '<a class="vd-btn" href="' . esc_url(vendicator_page_url('login')) . '">log in</a>.'
            . '</p></div></div></div>';
    }
    $team = isset($_GET['vd_team']) ? sanitize_text_field(wp_unslash($_GET['vd_team'])) : '';
    $league = isset($_GET['vd_league']) ? sanitize_text_field(wp_unslash($_GET['vd_league'])) : '';
    $p = get_option('vendicator_predictions');
    $tables = isset($p['tables']) ? $p['tables'] : array();
    $table = isset($tables[$league]) ? $tables[$league] : array();

    $out = '<div class="vd-wrap"><div class="vd-inner">'
        . '<div class="vd-card"><div class="vd-logo">VENDI<b>CATOR</b></div>'
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
        . '</div></div>' . vendicator_dashboard_js($p ? $p : array());
    return $out;
});

add_shortcode('vendicator_account', function () {
    if (!is_user_logged_in()) {
        return '<div class="vd-wrap"><div class="vd-inner"><div class="vd-card">'
            . '<p>Please <a class="vd-btn" href="' . esc_url(vendicator_page_url('login'))
            . '">log in</a>.</p></div></div></div>';
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
        $bets = json_decode((string) get_user_meta($uid, 'vendicator_bets', true), true);
        $body = '<div class="vd-card"><h3>Betting / prediction history</h3><table class="vd-table">';
        if (!$bets) { $body .= '<tr><td class="vd-muted">No predictions yet.</td><td></td></tr>'; }
        foreach (array_reverse((array) $bets) as $b) {
            $body .= '<tr><td>' . esc_html(substr($b['ts'], 0, 16) . ' · ' . $b['fixture'])
                . ' → ' . esc_html($b['pick']) . '</td><td>'
                . ($b['settled'] ? esc_html($b['points']) . ' pts' : '<span class="vd-muted">open</span>')
                . '</td></tr>';
        }
        $body .= '</table></div>';
    } elseif ($tab === 'rewards') {
        $hist = json_decode((string) get_user_meta($uid, 'vendicator_points_history', true), true);
        $data = wp_json_encode(array_values(array_map('intval', (array) $hist)));
        $body = '<div class="vd-card"><h3>Points balance</h3>'
            . '<p style="font-size:32px;font-weight:800;color:#C6FF4D;">' . $points
            . ' <span class="vd-muted" style="font-size:14px;">(' . $lifetime . ' lifetime)</span></p>'
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
            $body .= '<p class="vd-muted">' . $lifetime . ' / ' . (int) $nextrank['points']
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
                . ': ' . $lifetime . ' / ' . $need . ' lifetime points (' . $pctv . '%)</p>'
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
    return '<div class="vd-wrap"><div class="vd-inner"><div class="vd-card">'
        . '<div class="vd-logo">VENDI<b>CATOR</b> <span class="vd-muted" style="font-size:14px;">account</span></div>'
        . '</div>' . $nav . $body . '</div></div>';
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

/* -------------------------------------------------------------- admin panel */

add_action('admin_menu', function () {
    add_menu_page('Vendicator', 'Vendicator', 'manage_options', 'vendicator',
        'vendicator_admin_models', 'dashicons-chart-line', 3);
    add_submenu_page('vendicator', 'Model Selectors', 'Model Selectors',
        'manage_options', 'vendicator', 'vendicator_admin_models');
    add_submenu_page('vendicator', 'Leagues', 'Leagues',
        'manage_options', 'vendicator-leagues', 'vendicator_admin_leagues');
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
    echo '<table class="widefat striped" style="max-width:820px;"><thead><tr>'
        . '<th>Tier</th><th>Lifetime points required</th><th>Benefits</th></tr></thead><tbody>';
    foreach ($tiers as $slug => $cfg) {
        printf('<tr><td>%s</td><td><input type="number" name="pts[%s]" value="%d"></td>'
            . '<td><input type="text" style="width:100%%" name="ben[%s]" value="%s"></td></tr>',
            esc_html($cfg['label']), esc_attr($slug), (int) $cfg['points'],
            esc_attr($slug), esc_attr($cfg['benefits']));
    }
    echo '</tbody></table><p><input type="submit" class="button button-primary" value="Save tiers"></p></form>';
    echo '<h2>Members by tier</h2><table class="widefat striped" style="max-width:600px;"><tbody>';
    foreach (get_users() as $u) {
        printf('<tr><td>%s</td><td>%s</td><td>%d pts</td></tr>',
            esc_html($u->user_login), esc_html(vendicator_user_tier($u->ID)),
            (int) get_user_meta($u->ID, 'vendicator_lifetime_points', true));
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
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td>'
            . '<td><a class="button" href="%s">%s</a></td></tr>',
            esc_html($u->user_login), esc_html($u->user_email),
            esc_html(vendicator_user_tier($u->ID)),
            (int) get_user_meta($u->ID, 'vendicator_points_balance', true),
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
