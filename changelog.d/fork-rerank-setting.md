**The search reranker is set in Admin → AI.** Its address now has a field under semantic search,
beside the other AI hosts, and Test sends it a small probe and shows how fast it answered. The
FORK_RERANK_URL variable still works when the field is empty. Adds one column to the fork's own
table `fork_ai_options`; the migration runs on start.
