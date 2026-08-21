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
        e["near_rate"] = e["near"] / e["n"] if e["n"] else 0.0
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


def _odds_from(total, edge_prob):
    """Fair decimal price, widened when the Scoreline is unsure."""
    p = max(min(float(edge_prob), 0.95), 0.03)
    confidence = max(min(total / 100.0, 1.0), 0.05)
    # low confidence -> shade the price out (longer odds, honest)
    adjusted = p * (0.75 + 0.25 * confidence)
    dec = round(1.0 / adjusted, 2)
    return dec, to_fractional(dec)


def team_scoreline(team, table_row=None, model_probs=None, history=None,
                   bets=None):
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

    # 3. how accurate the model has been on this team (0-20)
    if h.get("accuracy") is not None:
        parts["model_accuracy"] = round(h["accuracy"] * 20, 1)
    else:
        parts["model_accuracy"] = 10.0

    # 4. near misses drag the score down (0 to -12)
    parts["near_miss_penalty"] = round(-h.get("near_rate", 0.0) * 12, 1)

    # 5. the platform's betting record on this team (0-20)
    won, lost = int(b.get("won", 0)), int(b.get("lost", 0))
    if won + lost >= 3:
        parts["bet_record"] = round(won / (won + lost) * 20, 1)
    else:
        parts["bet_record"] = 10.0

    total = round(max(min(sum(parts.values()), 100.0), 1.0), 1)

    edge = (max(float(v) for v in model_probs.values()) / 100.0
            if model_probs else 0.4)
    dec, frac = _odds_from(total, edge)

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
        hs = team_scoreline(home, rows.get(home),
                            {"top": probs.get("home", 33)}, history, bets)
        as_ = team_scoreline(away, rows.get(away),
                             {"top": probs.get("away", 33)}, history, bets)
        headline = round((hs["total"] + as_["total"]) / 2, 1)
        fx["scoreline"] = {"home": hs, "away": as_, "headline": headline,
                           "rating": odds_style_rating(headline)}
        store["teams"][home] = hs
        store["teams"][away] = as_
        for pl in fx.get("players", []) or []:
            ps = player_scoreline(pl, history)
            pl["scoreline"] = ps
            store["players"][str(pl["id"])] = dict(ps, name=pl["name"])

    OUT.write_text(json.dumps(store, indent=1))
    print(f"Vendicator Scorelines: {len(store['teams'])} teams, "
          f"{len(store['players'])} players -> {OUT}")
    return payload
