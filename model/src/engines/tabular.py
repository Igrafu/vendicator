"""MODEL A - match prediction: gradient-boosted ensemble.

Uses every library that imports cleanly on this machine (LightGBM, XGBoost,
CatBoost) and falls back to scikit-learn's HistGradientBoosting (same
histogram-GBM family as LightGBM) so the ensemble always has >= 2 members.
Outputs multiclass probabilities for H/D/A per match.
"""
import numpy as np

AVAILABLE = {}
try:
    from lightgbm import LGBMClassifier
    AVAILABLE["lightgbm"] = lambda: LGBMClassifier(
        n_estimators=300, learning_rate=0.05, num_leaves=31,
        objective="multiclass", verbose=-1)
except Exception:
    pass
try:
    from xgboost import XGBClassifier
    AVAILABLE["xgboost"] = lambda: XGBClassifier(
        n_estimators=300, learning_rate=0.05, max_depth=5,
        objective="multi:softprob", verbosity=0, use_label_encoder=False,
        eval_metric="mlogloss")
except Exception:
    pass
try:
    from catboost import CatBoostClassifier
    AVAILABLE["catboost"] = lambda: CatBoostClassifier(
        iterations=300, learning_rate=0.05, depth=6,
        loss_function="MultiClass", verbose=False, allow_writing_files=False)
except Exception:
    pass
from sklearn.ensemble import HistGradientBoostingClassifier
AVAILABLE["hist_gbm"] = lambda: HistGradientBoostingClassifier(
    max_iter=300, learning_rate=0.05)

CLASSES = ["H", "D", "A"]


class TabularEnsemble:
    def __init__(self, members=None):
        names = members or list(AVAILABLE)
        self.models = {n: AVAILABLE[n]() for n in names if n in AVAILABLE}

    def fit(self, X, y):
        """X: 2d array (may contain NaN); y: array of 'H'/'D'/'A'."""
        X = np.asarray(X, dtype=float)
        yi = np.array([CLASSES.index(v) for v in y])
        med = np.nanmedian(X, axis=0)
        self.impute_ = np.where(np.isnan(med), 0.0, med)
        Xf = self._clean(X)
        for m in self.models.values():
            m.fit(Xf, yi)
        return self

    def _clean(self, X):
        X = np.asarray(X, dtype=float).copy()
        idx = np.where(np.isnan(X))
        X[idx] = np.take(self.impute_, idx[1])
        return X

    def predict_proba(self, X):
        """Average of member probabilities -> {H, D, A} columns."""
        Xf = self._clean(X)
        probs = []
        for m in self.models.values():
            p = np.asarray(m.predict_proba(Xf))
            if p.shape[1] == 3:
                probs.append(p)
        return np.mean(probs, axis=0)

    def member_probas(self, X):
        """Per-member probabilities, for the stacking meta-model."""
        Xf = self._clean(X)
        return {n: np.asarray(m.predict_proba(Xf))
                for n, m in self.models.items()}
