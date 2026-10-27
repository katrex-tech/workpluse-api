import pytest
from app.pipeline.engine import FaceEngine
from app.config import settings

def test_matching_policy_accepted_match():
    """Top candidate exceeds threshold and exceeds runner-up by >= margin."""
    candidates = [
        {"user_id": "emp_101", "score": 0.88, "enrolled_at": "2026-10-27T00:00:00"},
        {"user_id": "emp_102", "score": 0.42, "enrolled_at": "2026-10-27T00:00:00"}
    ]
    decision, matched_uid, closest, runner_up = FaceEngine.evaluate_matching_decision(
        candidates,
        threshold=0.65,
        margin=0.08
    )
    assert decision == "matched"
    assert matched_uid == "emp_101"
    assert closest.score == 0.88
    assert runner_up.score == 0.42

def test_matching_policy_unknown_below_threshold():
    """Top candidate score is below threshold -> decision is unknown, but closest score is preserved."""
    candidates = [
        {"user_id": "emp_101", "score": 0.54, "enrolled_at": "2026-10-27T00:00:00"},
        {"user_id": "emp_102", "score": 0.31, "enrolled_at": "2026-10-27T00:00:00"}
    ]
    decision, matched_uid, closest, runner_up = FaceEngine.evaluate_matching_decision(
        candidates,
        threshold=0.65,
        margin=0.08
    )
    assert decision == "unknown"
    assert matched_uid is None
    assert closest.user_id == "emp_101"
    assert closest.score == 0.54
    assert runner_up.user_id == "emp_102"

def test_matching_policy_ambiguous_near_runner_up():
    """Top candidate is >= threshold, but runner-up is within margin (score_diff < 0.08) -> ambiguous."""
    candidates = [
        {"user_id": "twin_a", "score": 0.72, "enrolled_at": "2026-10-27T00:00:00"},
        {"user_id": "twin_b", "score": 0.69, "enrolled_at": "2026-10-27T00:00:00"}  # diff = 0.03 < 0.08!
    ]
    decision, matched_uid, closest, runner_up = FaceEngine.evaluate_matching_decision(
        candidates,
        threshold=0.65,
        margin=0.08
    )
    assert decision == "ambiguous"
    assert matched_uid is None
    assert closest.user_id == "twin_a"
    assert runner_up.user_id == "twin_b"

def test_matching_policy_empty_candidates():
    """Empty candidate gallery -> unknown with no fabricated scores."""
    decision, matched_uid, closest, runner_up = FaceEngine.evaluate_matching_decision(
        [],
        threshold=0.65,
        margin=0.08
    )
    assert decision == "unknown"
    assert matched_uid is None
    assert closest is None
    assert runner_up is None
