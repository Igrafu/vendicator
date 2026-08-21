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
    if (is_page(array('login', 'dashboard', 'account'))) {
        $classes[] = 'vendicator-page';
    }
    return $classes;
});

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
    $out .= '<p class="vd-muted" style="margin:0 0 4px;">Updated '
        . esc_html(get_option('vendicator_predictions_updated', '')) . '</p>';
    $list = isset($p['fixtures']) && is_array($p['fixtures'])
        ? $p['fixtures'] : array($p);
    foreach ($list as $fx) {
        $out .= vendicator_render_fixture($fx, $allowed, $sel);
    }
    $out .= '</div></div>';
    return $out;
});

function vendicator_render_fixture($p, $allowed, $sel) {
    $f = $p['final_calibrated'];
    $dc = $p['markets_dixon_coles'];
    $out = '<div class="vd-card"><h2>' . esc_html($p['fixture']) . '</h2>'
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
    $cards = array(
        '1x2' => array('1X2', array('Home' => $dc['1x2']['home'], 'Draw' => $dc['1x2']['draw'], 'Away' => $dc['1x2']['away'])),
        'double_chance' => array('Double Chance', array('1X' => $dc['double_chance']['1x'], '12' => $dc['double_chance']['12'], 'X2' => $dc['double_chance']['x2'])),
        'btts' => array('BTTS', array('Yes' => $dc['btts']['yes'], 'No' => $dc['btts']['no'])),
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
        . '<p><label><input type="radio" name="vd_pick" value="H" required> Home win</label><br>'
        . '<label><input type="radio" name="vd_pick" value="D"> Draw</label><br>'
        . '<label><input type="radio" name="vd_pick" value="A"> Away win</label></p>'
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
    $bets[] = array('ts' => gmdate('c'),
        'fixture' => sanitize_text_field(wp_unslash($_POST['vd_fixture'] ?? '')),
        'pick' => sanitize_text_field(wp_unslash($_POST['vd_pick'] ?? '')),
        'settled' => false, 'points' => null);
    update_user_meta($uid, 'vendicator_bets', wp_json_encode($bets));
    wp_safe_redirect(vendicator_page_url('account') . '?tab=betting');
    exit;
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
