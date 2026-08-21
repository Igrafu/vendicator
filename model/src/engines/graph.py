"""MODEL C - tactical/player prediction (GNN). Phase 2 interface.

Goal: learn passing relationships, pressing structures, spatial influence and
tactical shifts from player-level graphs, then emit a tactical narrative the
LLM layer turns into readable match analysis.

Graph spec (per team, per window):
  nodes = players (features: position, minutes, xG, xA, touches, duels)
  edges = pass counts/success, pressure events, marking assignments
  model = GraphSAGE/GAT -> team embedding -> tactical heads:
          (build-up style, press intensity, wing bias, vulnerability zones)

Blocked on data: player-graph construction needs event/tracking data
(StatsBomb Open Data works for TRAINING; live inference needs a paid event
feed). Until then `TacticalReport.from_basic_stats` produces a rule-based
tactical sketch from lineups + aggregate stats so the site feature exists.
"""


class GNNStub:
    REQUIRED_DATA = "event/tracking data (StatsBomb open for training)"

    def team_embedding(self, graph):
        raise NotImplementedError(f"GNN needs {self.REQUIRED_DATA}")


class TacticalReport:
    """Rule-based stand-in so the 'how might this play out' section ships."""

    @staticmethod
    def from_basic_stats(home_stats, away_stats):
        """*_stats: {'shots_avg', 'sot_avg', 'gf_avg', 'ga_avg', 'form_pts'}"""
        notes = []
        if home_stats.get("shots_avg", 0) > away_stats.get("shots_avg", 0) * 1.3:
            notes.append("home side generates far more shot volume; expect "
                         "territorial dominance")
        if away_stats.get("ga_avg", 99) < 1.0:
            notes.append("away defence has been resilient (<1 goal conceded "
                         "per game); low-block likely")
        if home_stats.get("form_pts", 0) >= 2.2:
            notes.append("home team arrives in strong form")
        if not notes:
            notes.append("evenly matched on recent numbers; game state likely "
                         "decided by first goal")
        return notes
