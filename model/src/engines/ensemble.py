"""ENSEMBLE + PROBABILITY ENGINE.

Stacking meta-learner over the specialist models. The meta-model's input is
each base model's probability vector (Elo, Dixon-Coles, Bayesian, tabular
ensemble members, de-vigged market), optionally plus context features - so it
LEARNS when each model should be trusted rather than fixed-weight averaging.

Final output passes through probability calibration (Platt/logistic by
default, isotonic when enough data) -> the calibrated percentages every
downstream engine (Monte Carlo, value, rewards) consumes.
"""
import numpy as np
from sklearn.isotonic import IsotonicRegression
from sklearn.linear_model import LogisticRegression

CLASSES = ["H", "D", "A"]
ISOTONIC_MIN_ROWS = 400


class StackedEnsemble:
    def __init__(self):
        self.meta = LogisticRegression(max_iter=2000, C=1.0)
        self.calibrators = None
        self.base_names = None

    @staticmethod
    def _stack(base_probs, context=None):
        """base_probs: {name: (n,3) array}; context: optional (n,k) array."""
        cols = [np.asarray(base_probs[n]) for n in sorted(base_probs)]
        X = np.hstack(cols)
        if context is not None:
            X = np.hstack([X, np.asarray(context, dtype=float)])
        return np.nan_to_num(X, nan=1 / 3)

    def fit(self, base_probs, y, context=None):
        """Fit meta-learner + calibration on held-out base predictions.
        base_probs must come from data the base models did NOT train on."""
        self.base_names = sorted(base_probs)
        X = self._stack(base_probs, context)
        yi = np.array([CLASSES.index(v) for v in y])
        self.meta.fit(X, yi)
        raw = self.meta.predict_proba(X)

        # per-class calibration: isotonic when data allows, else Platt-style
        self.calibrators = []
        for c in range(3):
            target = (yi == c).astype(float)
            if len(yi) >= ISOTONIC_MIN_ROWS:
                cal = IsotonicRegression(out_of_bounds="clip",
                                         y_min=1e-4, y_max=1 - 1e-4)
                cal.fit(raw[:, c], target)
            else:
                cal = LogisticRegression(max_iter=1000)
                cal.fit(raw[:, c].reshape(-1, 1), target)
            self.calibrators.append(cal)
        return self

    def predict_proba(self, base_probs, context=None):
        X = self._stack(base_probs, context)
        raw = self.meta.predict_proba(X)
        out = np.zeros_like(raw)
        for c, cal in enumerate(self.calibrators):
            if isinstance(cal, IsotonicRegression):
                out[:, c] = cal.predict(raw[:, c])
            else:
                out[:, c] = cal.predict_proba(
                    raw[:, c].reshape(-1, 1))[:, 1]
        out = np.clip(out, 1e-4, None)
        return out / out.sum(axis=1, keepdims=True)

    def trust_weights(self):
        """Rough view of how much the meta-model leans on each base model:
        mean |coefficient| over that model's three probability columns."""
        coefs = np.abs(self.meta.coef_).mean(axis=0)
        return {name: float(coefs[i * 3:(i + 1) * 3].mean())
                for i, name in enumerate(self.base_names)}


def brier(probs, y):
    """Multiclass Brier score (lower = better)."""
    yi = np.array([CLASSES.index(v) for v in y])
    onehot = np.eye(3)[yi]
    return float(((probs - onehot) ** 2).sum(axis=1).mean())


def log_loss_score(probs, y):
    yi = np.array([CLASSES.index(v) for v in y])
    p = np.clip(probs[np.arange(len(yi)), yi], 1e-12, 1)
    return float(-np.log(p).mean())
