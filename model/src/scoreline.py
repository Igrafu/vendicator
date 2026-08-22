"""THE VENDICATOR SCORELINE - the platform's own headline number.

Two numbers per team and per player, regenerated before every match so they
are ready in time to be used as a real-time reference:

  1. `total`   0-100 composite of everything the engine knows
  2. `odds`    the same judgement expressed as a price - decimal AND
               fractional - so it lines up with a real betting slip

What feeds the total (per the brief):
  * model research: form, attack/defence strength, xG differential
  * how ACCURATE the model has been on that team (settled Brier history)
  * NEAR MISSES: settled predictions that were close but wrong. A team the
    model keeps *just* missing on is less trustworthy than one it nails, so
    near-miss density drags the score down even when raw accuracy looks fine
  * the platform's own betting record on that team (bets won vs lost)
  * which alternative selection WOULD have won - logged so the number
    learns which markets pay out on this team

The odds number is the fair price on the team's strongest selection,
widened slightly when the Scoreline is uncertain, so it is honest rather
than flattering.
"""
import json
from fractions import Fraction
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HISTORY = ROOT / "records" / "model-history.jsonl"
BETS = ROOT / "records" / "bet-record.json"
OUT = ROOT / "records" / "scorelines.json"


def _load_history():
    if not HISTORY.exists():
        return []
    out = []
    for line in HISTORY.read_text().splitlines():
        if line.strip():
            try:
                out.append(json.loads(line))
            except ValueError:
                continue
    return out


def _load_bets():
    if not BETS.exists():
        return {}
    try:
        return json.loads(BETS.read_text())
    except ValueError:
        return {}


# Accuracy is a running hit rate, so on the first settled call a raw rate
# leaps from "no idea" to 0% or 100%. These pseudo-matches at even money
# hold it steady until there is enough history to have earned the move.
ACC_PRIOR_N = 4.0
ACC_PRIOR_RATE = 0.5


def accuracy_points(hits, n):
    """The Scoreline's accuracy component (0-20), priored so that early
    results inform it without whipsawing it."""
    rate = ((hits + ACC_PRIOR_RATE * ACC_PRIOR_N)
            / (n + ACC_PRIOR_N))
    return round(rate * 20, 2)


def near_miss_analysis(records):
    """Per team: how often the model was wrong but close, and which
    selection would have paid instead.

    'Close' = the model's top pick lost, but its probability was within
    12 points of the outcome that actually landed. Those are the ones worth
    learning from - a different market choice would have banked the points.
    """
    stats = {}
    for r in records:
        if not r.get("settled"):
            continue
        probs = r.get("probs") or {}
        result = (r.get("result") or {}).get("outcome")
        if not probs or result not in ("H", "D", "A"):
            continue
        key = {"H": "home", "D": "draw", "A": "away"}[result]
        actual_p = float(probs.get(key, 0))
        top_key = max(probs, key=lambda k: float(probs.get(k, 0)))
        top_p = float(probs.get(top_key, 0))
        hit = ({"home": "H", "draw": "D", "away": "A"}.get(top_key) == result)
        gap = top_p - actual_p
        for team in (r.get("home"), r.get("away")):
            if not team:
                continue
            e = stats.setdefault(team, {"n": 0, "hits": 0, "near": 0,
                                        "brier": 0.0, "would_have_won": {}})
            e["n"] += 1
            e["hits"] += 1 if hit else 0
            if not hit and gap <= 12:
                e["near"] += 1
                # the market that would have paid instead
                e["would_have_won"][key] = e["would_have_won"].get(key, 0) + 1
            ev = (r.get("eval") or {}).get("brier")
            if ev is not None:
                e["brier"] += float(ev)
    for e in stats.values():
        e["accuracy"] = e["hits"] / e["n"] if e["n"] else None
        # priored so a team with two settled calls is not judged as harshly
        # as one with forty - see ACC_PRIOR_N
        e["near_rate"] = e["near"] / (e["n"] + ACC_PRIOR_N)
        e["avg_brier"] = e["brier"] / e["n"] if e["n"] else None
    return stats


# The fractions bookmakers actually print, shortest-priced first.
STANDARD_FRACTIONS = [
    (1, 10), (1, 8), (1, 7), (1, 6), (1, 5), (2, 9), (1, 4), (2, 7),
    (3, 10), (1, 3), (4, 11), (2, 5), (4, 9), (1, 2), (8, 15), (4, 7),
    (8, 13), (2, 3), (8, 11), (4, 5), (5, 6), (10, 11), (1, 1), (11, 10),
    (6, 5), (5, 4), (11, 8), (3, 2), (13, 8), (7, 4), (15, 8), (2, 1),
    (85, 40), (9, 4), (5, 2), (11, 4), (3, 1), (10, 3), (7, 2), (4, 1),
    (9, 2), (5, 1), (11, 2), (6, 1), (13, 2), (7, 1), (15, 2), (8, 1),
    (9, 1), (10, 1), (11, 1), (12, 1), (14, 1), (16, 1), (18, 1), (20, 1),
    (25, 1), (33, 1), (40, 1), (50, 1), (66, 1), (80, 1), (100, 1),
]


def to_fractional(decimal_odds):
    """2.5 -> '6/4'. Snaps to the fraction a real bookmaker would show,
    so the number can be compared with a live slip elsewhere."""
    if decimal_odds is None or decimal_odds <= 1:
        return None
    target = decimal_odds - 1.0
    num, den = min(STANDARD_FRACTIONS,
                   key=lambda f: abs(f[0] / f[1] - target))
    return "evens" if (num, den) == (1, 1) else f"{num}/{den}"


def odds_style_rating(total):
    """The 0-100 composite expressed the way a price is written.

    Bookmakers and the exchanges lead with a single digit before the point
    (1.85, 2.63, 9.40), so the Scoreline is shown on the same scale: a 40.3
    composite reads as 4.03. The ordering and the information are identical -
    it just sits alongside a real slip without a mental conversion.
    """
    return round(max(min(float(total), 100.0), 10.0) / 10.0, 2)


def market_price(fx, side):
    """De-vigged best board price for one side, or None.

    The open odds board is the single best-calibrated read available on any
    fixture - it is thousands of people betting real money. Stripping the
    overround turns it back into a probability we can price against.
    """
    board = fx.get("odds_board") or {}
    prices = {}
    for k in ("home", "draw", "away"):
        row = board.get(k) or []
        if not row or not row[0].get("odds"):
            return None
        prices[k] = float(row[0]["odds"])
    overround = sum(1.0 / v for v in prices.values())
    if overround <= 0:
        return None
    fair = (1.0 / prices[side]) / overround
    return round(1.0 / fair, 3) if fair > 0 else None


def _odds_from(total, edge_prob, book_price=None):
    """Fair decimal price, widened when the Scoreline is unsure.

    Where the board carries a price we lean on it: the market is better
    calibrated than any single model, so the published number sits between
    our own read and theirs rather than ignoring one of them.
    """
    p = max(min(float(edge_prob), 0.95), 0.03)
    if book_price and book_price > 1.0:
        p = p * 0.5 + (1.0 / book_price) * 0.5
    confidence = max(min(total / 100.0, 1.0), 0.05)
    # low confidence -> shade the price out (longer odds, honest)
    adjusted = p * (0.75 + 0.25 * confidence)
    dec = round(1.0 / adjusted, 2)
    return dec, to_fractional(dec)


def team_scoreline(team, table_row=None, model_probs=None, history=None,
                   bets=None, book_price=None, players=None):
    """-> {'total', 'decimal', 'fractional', 'components', 'note'}"""
    history = history or {}
    bets = bets or {}
    h = history.get(team, {})
    b = bets.get(team, {})

    parts = {}
    # 1. season strength from the live table (0-30)
    if table_row and int(table_row.get("p", 0)) > 0:
        played = int(table_row["p"])
        ppg = int(table_row.get("pts", 0)) / played
        gd90 = (int(table_row.get("gf", 0)) - int(table_row.get("ga", 0))) / played
        parts["form"] = round(min(ppg / 3.0, 1.0) * 20, 1)
        parts["goal_difference"] = round(
            max(min((gd90 + 2) / 4, 1.0), 0.0) * 10, 1)
    else:
        parts["form"] = 10.0
        parts["goal_difference"] = 5.0

    # 2. model conviction on this fixture (0-20)
    if model_probs:
        top = max(float(v) for v in model_probs.values())
        parts["model_conviction"] = round(min(top / 100.0, 1.0) * 20, 1)
    else:
        parts["model_conviction"] = 10.0

    # 3. how accurate the model has been on this team (0-20), priored so a
    #    single settled call cannot swing it from end to end
    parts["model_accuracy"] = accuracy_points(int(h.get("hits", 0)),
                                              int(h.get("n", 0)))

    # 4. near misses drag the score down (0 to -12)
    parts["near_miss_penalty"] = round(-h.get("near_rate", 0.0) * 12, 1)

    # 5. the platform's betting record on this team (0-20)
    won, lost = int(b.get("won", 0)), int(b.get("lost", 0))
    if won + lost >= 3:
        parts["bet_record"] = round(won / (won + lost) * 20, 1)
    else:
        parts["bet_record"] = 10.0

    # 6. squad quality: the players actually available to this side (0-15).
    #    A table row says how the season has gone; the squad says who is
    #    going to play, which is the other half of the same question.
    if players:
        ratings = sorted((float(p.get("rating", 0)) for p in players),
                         reverse=True)[:11]
        if ratings:
            parts["squad"] = round(
                min(sum(ratings) / len(ratings) / 100.0, 1.0) * 15, 1)
    parts.setdefault("squad", 7.5)

    total = round(max(min(sum(parts.values()), 100.0), 1.0), 1)

    edge = (max(float(v) for v in model_probs.values()) / 100.0
            if model_probs else 0.4)
    dec, frac = _odds_from(total, edge, book_price)

    note = []
    if h.get("n"):
        note.append(f"{h['hits']}/{h['n']} settled calls correct")
    if h.get("near"):
        best = max(h["would_have_won"], key=h["would_have_won"].get) \
            if h["would_have_won"] else None
        note.append(f"{h['near']} near miss(es)"
                    + (f", '{best}' would have paid" if best else ""))
    if won + lost:
        note.append(f"member bets {won}W-{lost}L")
    return {"total": total, "rating": odds_style_rating(total),
            "decimal": dec, "fractional": frac,
            "components": parts, "note": "; ".join(note) or
            "no settled history yet - score is model-derived"}


# Between 1 and 20 cards carry a value flag on any given board, and no more.
# Gold is awarded per competition - the single best card in each league, and
# only when it clears the floor - so a golden ticket means something within
# its own division rather than being swept up by whichever league happens to
# be pricing generously that week. Silver and bronze are then taken from
# what is left, best first.
GRADE_MIN, GRADE_MAX = 1, 20
GRADE_FLOOR = 45.0          # value score a card must reach to be graded
GOLD_FLOOR = 62.0           # a golden ticket has to clear a higher bar
SILVER_SHARE, BRONZE_SHARE = 0.30, 0.55
GRADE_BONUS = {"gold": 0.40, "silver": 0.25, "bronze": 0.12}


def value_score(headline, difficulty, movement_up, settled_calls):
    """Raw value signal for one card, before it is ranked against the board.

    Weighted towards what actually discriminates. Difficulty is included but
    held light: right now it sits at ~1.96 for almost every fixture, so
    leaning on it would hand every card the same score. Projected movement
    only counts once there is settled history behind it - before that it is
    a constant, and a constant that rewards having no track record is worse
    than no signal at all.
    """
    score = max(min((float(headline) - 25.0) / 35.0, 1.0), 0.0) * 70
    score += max(min((float(difficulty) - 1.4) / 0.6, 1.0), 0.0) * 20
    if int(settled_calls or 0) >= 3:
        score += max(min(float(movement_up) / 1.5, 1.0), 0.0) * 10
    return round(score, 2)


def grade_board(fixtures):
    """Grade a whole matchday at once, by rank rather than by threshold.

    A card is good value RELATIVE to what else is on the board that day -
    absolute cut-offs drift out of calibration the moment the underlying
    numbers shift, which is exactly what happened when every fixture came
    back with the same difficulty. Ranking is self-correcting: the top
    slice is graded, the rest pay their selection values and nothing more.
    """
    scored = []
    for fx in fixtures:
        sl = fx.get("scoreline") or {}
        calls = ((sl.get("home") or {}).get("movement") or {}).get(
            "settled_calls", 0)
        scored.append((value_score(sl.get("headline", 0),
                                   fx.get("reward_difficulty_multiplier", 1.0),
                                   (sl.get("movement") or {}).get("up", 0.0),
                                   calls), fx))
    scored.sort(key=lambda kv: kv[0], reverse=True)
    for score, fx in scored:
        fx["scoreline"]["grade"] = None
        fx["scoreline"]["value_score"] = score

    # only cards clearing the floor are eligible at all, and never more
    # than GRADE_MAX of them
    eligible = [(s, fx) for s, fx in scored if s >= GRADE_FLOOR][:GRADE_MAX]
    if len(eligible) < GRADE_MIN and scored:
        eligible = scored[:GRADE_MIN]

    # gold: the best card in each competition, if it clears the higher bar
    seen_leagues = set()
    gold = []
    for s, fx in eligible:
        lg = fx.get("league")
        if lg in seen_leagues or s < GOLD_FLOOR:
            continue
        seen_leagues.add(lg)
        gold.append((s, fx))
    for s, fx in gold:
        fx["scoreline"]["grade"] = {"tier": "gold", "score": s,
                                    "bonus": GRADE_BONUS["gold"]}

    rest = [(s, fx) for s, fx in eligible
            if fx["scoreline"]["grade"] is None]
    cut = int(round(len(rest) * SILVER_SHARE))
    for s, fx in rest[:cut]:
        fx["scoreline"]["grade"] = {"tier": "silver", "score": s,
                                    "bonus": GRADE_BONUS["silver"]}
    upto = cut + int(round(len(rest) * BRONZE_SHARE))
    for s, fx in rest[cut:upto]:
        fx["scoreline"]["grade"] = {"tier": "bronze", "score": s,
                                    "bonus": GRADE_BONUS["bronze"]}
    return fixtures


def _bet_record_points(won, lost):
    """The platform betting-record component (0-20), priored like accuracy."""
    n = won + lost
    if n <= 0:
        return 10.0
    rate = (won + ACC_PRIOR_RATE * ACC_PRIOR_N) / (n + ACC_PRIOR_N)
    return round(rate * 20, 2)


def projected_movement(team, history, current_total, bets=None,
                       selections=1):
    """Where this team's Scoreline lands after the match is settled.

    The accuracy component is a running hit rate, so one more settled call
    moves it by a knowable amount: a hit nudges it up, a miss (and any near
    miss) drags it down. Reporting both before kick-off tells a member
    whether a card is worth holding for - a team on the way up is worth more
    than the same number on the way down.
    """
    h = (history or {}).get(team, {})
    n = int(h.get("n", 0))
    hits = int(h.get("hits", 0))
    now = accuracy_points(hits, n)
    up = accuracy_points(hits + 1, n + 1) - now
    down = accuracy_points(hits, n + 1) - now
    # a miss also adds to the near-miss rate, which carries its own penalty.
    # Same prior applies: one unlucky result is not a pattern.
    near = int(h.get("near", 0))
    near_now = -(near / (n + ACC_PRIOR_N)) * 12
    near_after = -((near + 1) / (n + 1 + ACC_PRIOR_N)) * 12
    down += near_after - near_now

    # The platform's own betting record on this side moves too, and it moves
    # by more when the card carried more selections: a Scoreline that
    # survived an eight-leg builder has been tested harder than one that
    # survived a single 1X2 call, and is worth more afterwards.
    b = (bets or {}).get(team, {})
    won, lost = int(b.get("won", 0)), int(b.get("lost", 0))
    legs = max(int(selections or 1), 1)
    rec_now = _bet_record_points(won, lost)
    up += _bet_record_points(won + legs, lost) - rec_now
    down += _bet_record_points(won, lost + legs) - rec_now

    return {
        "up": round(up, 2),
        "down": round(down, 2),
        "up_rating": odds_style_rating(current_total + up),
        "down_rating": odds_style_rating(current_total + down),
        "settled_calls": n,
        "selections_counted": legs,
    }


def player_scoreline(player, history=None):
    """Player Scoreline: value rating tempered by recent involvement."""
    rating = float(player.get("rating", 50))
    last5 = player.get("last5", {})
    goals = sum(1 for s in last5.get("goals", []) if s == "goal")
    assists = sum(1 for s in last5.get("assists", []) if s == "assist")
    dnp = sum(1 for s in last5.get("goals", []) if s == "dnp")
    involvement = (goals * 9 + assists * 7) - dnp * 4
    total = round(max(min(rating * 0.75 + involvement + 12, 100.0), 1.0), 1)
    prob = max(min((player.get("prob", {}).get("score", 10) or 10) / 100.0,
                   0.9), 0.02)
    dec, frac = _odds_from(total, prob)
    return {"total": total, "rating": odds_style_rating(total),
            "decimal": dec, "fractional": frac,
            "note": f"{goals} goal(s), {assists} assist(s) in last 5"
                    + (f", {dnp} missed" if dnp else "")}


def build(payload):
    """Attach scorelines to every fixture in a predictions payload."""
    history = near_miss_analysis(_load_history())
    bets = _load_bets()
    tables = payload.get("tables", {})
    store = {"teams": {}, "players": {}}

    for fx in payload.get("fixtures", []):
        rows = {r["team"]: r for r in tables.get(fx["league"], [])}
        probs = fx.get("final_calibrated", {})
        home, away = fx.get("home_team"), fx.get("away_team")
        squads = {}
        for pl in fx.get("players", []) or []:
            squads.setdefault(pl.get("team"), []).append(pl)
        hs = team_scoreline(home, rows.get(home),
                            {"top": probs.get("home", 33)}, history, bets,
                            market_price(fx, "home"), squads.get(home))
        as_ = team_scoreline(away, rows.get(away),
                             {"top": probs.get("away", 33)}, history, bets,
                             market_price(fx, "away"), squads.get(away))
        # how many selections this card actually offers, which is how hard
        # its Scoreline is about to be tested
        legs = 3 + len(fx.get("players", []) or []) // 8
        hs["movement"] = projected_movement(home, history, hs["total"],
                                            bets, legs)
        as_["movement"] = projected_movement(away, history, as_["total"],
                                             bets, legs)
        headline = round((hs["total"] + as_["total"]) / 2, 1)
        move_up = round((hs["movement"]["up"] + as_["movement"]["up"]) / 2, 2)
        move_down = round(
            (hs["movement"]["down"] + as_["movement"]["down"]) / 2, 2)
        fx["scoreline"] = {
            "home": hs, "away": as_, "headline": headline,
            "rating": odds_style_rating(headline),
            # what the headline rating becomes once this card settles
            "movement": {
                "up": move_up, "down": move_down,
                "up_rating_delta": round(
                    odds_style_rating(headline + move_up)
                    - odds_style_rating(headline), 2),
                "down_rating_delta": round(
                    odds_style_rating(headline + move_down)
                    - odds_style_rating(headline), 2),
            },
            # grade is filled in by grade_board() once every card is priced,
            # because value is judged against the rest of the board
            "grade": None,
        }
        store["teams"][home] = hs
        store["teams"][away] = as_
        for pl in fx.get("players", []) or []:
            ps = player_scoreline(pl, history)
            pl["scoreline"] = ps
            store["players"][str(pl["id"])] = dict(ps, name=pl["name"])

    grade_board(payload.get("fixtures", []))
    graded = {}
    for fx in payload.get("fixtures", []):
        tier = (fx["scoreline"].get("grade") or {}).get("tier", "ungraded")
        graded[tier] = graded.get(tier, 0) + 1

    OUT.write_text(json.dumps(store, indent=1))
    print(f"Vendicator Scorelines: {len(store['teams'])} teams, "
          f"{len(store['players'])} players -> {OUT}")
    print("  value grades: " + ", ".join(f"{k} {v}" for k, v in
                                         sorted(graded.items())))
    return payload
