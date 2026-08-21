"""EXPLANATION LAYER - the only place an LLM appears.

The LLM (Claude) NEVER produces or modifies a probability. It receives the
engines' outputs and turns them into:
  - "why does the model favour X" explanations
  - injury/news interpretation (input -> features, human-checked)
  - tactical summaries (from graph.TacticalReport / later GNN heads)
  - personalised match previews
  - conversational answers on the site

This module just assembles the structured context the LLM reasons over, so
every explanation is grounded in actual model numbers.
"""
import json


def explanation_context(fixture, ensemble_probs, base_probs, trust_weights,
                        market_probs=None, tactical_notes=None,
                        uncertainty=None):
    """Bundle everything an LLM needs to explain a prediction faithfully."""
    return {
        "fixture": fixture,
        "final_calibrated_probs_pct": {k: round(v * 100, 1)
                                       for k, v in ensemble_probs.items()},
        "base_model_probs_pct": {m: {k: round(v * 100, 1)
                                     for k, v in p.items()}
                                 for m, p in base_probs.items()},
        "meta_model_trust": trust_weights,
        "market_probs_pct": ({k: round(v * 100, 1)
                              for k, v in market_probs.items()}
                             if market_probs else None),
        "uncertainty": uncertainty,
        "tactical_notes": tactical_notes or [],
        "rules": [
            "explain, never re-estimate: quote the probabilities as given",
            "attribute drivers to the models that produced them",
            "state uncertainty bands when present",
            "this is analysis, not betting advice",
        ],
    }


def as_prompt(ctx):
    return ("Explain this football prediction to a site user in 3 short "
            "paragraphs, following the rules field strictly:\n"
            + json.dumps(ctx, indent=2))
