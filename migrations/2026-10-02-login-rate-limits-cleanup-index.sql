-- Apply once to an existing database to support efficient rate-limit cleanup.
ALTER TABLE LoginRateLimits
  ADD INDEX login_rate_limits_window_started (window_started_at);
